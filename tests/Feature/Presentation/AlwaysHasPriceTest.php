<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\AgentOverride;
use App\Models\Presentation;
use App\Models\PresentationSnapshotLink;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\User;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\PresentationCompilerService;
use App\Services\Presentations\PresentationPriceMissingException;
use App\Services\Presentations\PresentationPriceReadiness;
use App\Services\Presentations\SnapshotLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "A presentation must ALWAYS have a price" (Johan, 2026-10-07).
 *
 * One test per path in the cc5 audit (/tmp/qa1-cc5-presentation-dashes-2026-10-07.md):
 * every way a presentation used to end up with a dash / missing valuation is
 * either FIXED (picks and price survive) or STOPPED with a plain message at
 * Confirm & Generate / PDF / the seller page / Seller Live / sending.
 */
final class AlwaysHasPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ═══ A. The engine always finds a price when the data allows ═══════════════

    /** Audit #3 — a selection that points only at retired comps, read outside Review. */
    public function test_engine_falls_back_to_all_comps_when_every_pick_is_retired(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);
        // The pick's row is gone and no fresh copy of that sale exists.
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id]])->save();
        $comps[0]->delete();

        $analysis = $this->compile($version);

        $this->assertNotNull($analysis['cma_valuation']['cma_middle'], 'a stale selection must never blank the price');
        $this->assertSame(2, $analysis['cma_valuation']['compute_pool_n']);
    }

    /** Audit #1/#3 — retired picks are carried onto the fresh copy of the same sale, not widened to everything. */
    public function test_engine_carries_retired_picks_onto_the_fresh_copies(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $old = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => [$old[0]->id, $old[1]->id]])->save();
        $this->rehydrate($old);

        $analysis = $this->compile($version);

        $this->assertSame(2, $analysis['cma_valuation']['compute_pool_n'], 'only the two picked sales are in the pool');
        $this->assertSame(1_650_000, $analysis['cma_valuation']['cma_middle']);
    }

    /** Audit #2 — a stored [] ("untick everything") used to blank every tile. */
    public function test_engine_treats_a_stored_empty_selection_as_all_comps(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => []])->save();

        $analysis = $this->compile($version);

        $this->assertNotNull($analysis['cma_valuation']['cma_middle']);
        $this->assertSame(3, $analysis['cma_valuation']['compute_pool_n']);
    }

    /** Audit #7 — ticked comps that have no sold price cannot make a price. */
    public function test_engine_falls_back_when_the_ticked_comps_have_no_sold_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $this->seedComps($agencyId, $version->presentation_id, 3);
        $unpriced = $this->seedComps($agencyId, $version->presentation_id, 1)[0];
        $unpriced->forceFill(['sold_price_inc' => 0])->save();
        $version->forceFill(['included_comp_ids_json' => [$unpriced->id]])->save();

        $analysis = $this->compile($version);

        $this->assertNotNull($analysis['cma_valuation']['cma_middle']);
    }

    /** Comps without a size are still comps — the price is the median of sold prices. */
    public function test_comps_without_a_size_still_produce_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $this->seedComps($agencyId, $version->presentation_id, 3)
            ->each(fn ($c) => $c->forceFill(['size_m2' => null])->save());

        $analysis = $this->compile($version);

        $this->assertSame(1_700_000, $analysis['cma_valuation']['cma_middle']);
    }

    // ═══ B. Review can no longer empty the selection ═══════════════════════════

    /** Audit #2 — "Select none" / price-range slider matching nothing. */
    public function test_set_comps_refuses_an_empty_selection_with_a_plain_message(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id, $comps[1]->id]])->save();

        $resp = $this->actingAs($user)->postJson(
            route('presentations.review.set-comps', $version->id),
            ['included_ids' => []],
        );

        $resp->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(PresentationPriceReadiness::NOTHING_TICKED_MESSAGE, $resp->json('message'));
        $this->assertSame([$comps[0]->id, $comps[1]->id], $version->fresh()->included_comp_ids_json);
        $this->assertDatabaseMissing('agent_overrides', [
            'presentation_version_id' => $version->id,
            'override_type'           => AgentOverride::TYPE_COMP_BULK_SET,
        ]);
    }

    /** The real browser sends a form with NO included_ids[] at all when nothing is ticked. */
    public function test_set_comps_refuses_a_form_post_with_no_ids_at_all(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 2);
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id]])->save();

        $resp = $this->actingAs($user)->post(
            route('presentations.review.set-comps', $version->id),
            [],
            ['Accept' => 'application/json'],
        );

        $resp->assertStatus(422);
        $this->assertSame(PresentationPriceReadiness::NOTHING_TICKED_MESSAGE, $resp->json('message'));
        $this->assertSame([$comps[0]->id], $version->fresh()->included_comp_ids_json);
    }

    /** A selection of only unpriced comps is as good as empty. */
    public function test_set_comps_refuses_a_selection_with_no_priced_comp(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 2);
        $comps[1]->forceFill(['sold_price_inc' => 0])->save();

        $this->actingAs($user)->postJson(
            route('presentations.review.set-comps', $version->id),
            ['included_ids' => [$comps[1]->id]],
        )->assertStatus(422);

        $this->assertNull($version->fresh()->included_comp_ids_json);
    }

    public function test_set_comps_still_saves_a_real_selection(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);

        $this->actingAs($user)->postJson(
            route('presentations.review.set-comps', $version->id),
            ['included_ids' => [$comps[0]->id, $comps[2]->id]],
        )->assertOk()->assertJson(['ok' => true, 'included_count' => 2]);

        $this->assertSame([$comps[0]->id, $comps[2]->id], $version->fresh()->included_comp_ids_json);
    }

    /** Audit #2 — unticking the LAST ticked comp. */
    public function test_unticking_the_last_comp_is_refused(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 2);
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id]])->save();

        $resp = $this->actingAs($user)->postJson(
            route('presentations.review.toggle-comp', ['version' => $version->id, 'comp' => $comps[0]->id]),
            ['included' => false],
        );

        $resp->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(PresentationPriceReadiness::NOTHING_TICKED_MESSAGE, $resp->json('message'));
        $this->assertSame([$comps[0]->id], $version->fresh()->included_comp_ids_json);
    }

    /** Review must draw the ticks the engine uses: a stored [] is "all comps", ticked. */
    public function test_review_ticks_every_comp_when_the_stored_selection_is_empty(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $this->seedComps($agencyId, $version->presentation_id, 2);
        $version->forceFill(['included_comp_ids_json' => []])->save();

        $resp = $this->actingAs($user)->get(route('presentations.review.show', $version->id));

        $resp->assertOk();
        preg_match_all('/data-comp-id="\d+"\s+data-included="([01])"/', $resp->getContent(), $rowFlags);
        $this->assertSame(['1', '1'], $rowFlags[1]);
        // …and the tile is a number, not a dash.
        $this->assertSame(1_650_000, $resp->viewData('cmaValuation')['cma_middle']);
    }

    /** Toggling from a stored [] must start from what the screen showed (all ticked). */
    public function test_toggle_from_a_stored_empty_selection_starts_from_all_comps(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => []])->save();

        $this->actingAs($user)->postJson(
            route('presentations.review.toggle-comp', ['version' => $version->id, 'comp' => $comps[0]->id]),
            ['included' => false],
        )->assertOk();

        $this->assertEqualsCanonicalizing([$comps[1]->id, $comps[2]->id], $version->fresh()->included_comp_ids_json);
    }

    // ═══ C. Regenerate keeps picks and says what it dropped ════════════════════

    /** Audit #5 — a pick whose sale is not in the fresh pull is dropped, but no longer silently. */
    public function test_compile_logs_and_announces_picks_that_are_not_in_the_fresh_pull(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $old = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => [$old[0]->id, $old[1]->id, $old[2]->id]])->save();

        // Regeneration: sales 0 and 1 come back (new ids); sale 2 is not in the fresh pull.
        $fresh = $this->rehydrate($old->take(2)->values());
        $old[2]->delete();

        $this->actingAs($user);
        $next = (new PresentationCompilerService())->compile($version->presentation_id, $user->id);

        $this->assertSame([$fresh[0]->id, $fresh[1]->id], $next->fresh()->included_comp_ids_json);
        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $next->id,
            'override_type'           => AgentOverride::TYPE_COMP_UNAVAILABLE,
            'target_id'               => (string) $old[2]->id,
        ]);

        $resp = $this->actingAs($user)->get(route('presentations.review.show', $next->id));
        $resp->assertOk();
        $resp->assertSee('no longer in the refreshed comparable sales');
    }

    /** Audit #13 — the version copy must not carry [] either. */
    public function test_compile_does_not_carry_an_empty_selection_forward(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $this->seedComps($agencyId, $version->presentation_id, 2);
        $version->forceFill(['included_comp_ids_json' => []])->save();

        $this->actingAs($user);
        $next = (new PresentationCompilerService())->compile($version->presentation_id, $user->id);

        $this->assertNull($next->fresh()->included_comp_ids_json);
    }

    /** Audit #1 — every pick gone after a regenerate: all comps, a price, never []. */
    public function test_compile_falls_back_to_all_comps_when_every_pick_is_gone(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id]])->save();
        $comps[0]->delete();

        $this->actingAs($user);
        $next = (new PresentationCompilerService())->compile($version->presentation_id, $user->id);

        $this->assertNull($next->fresh()->included_comp_ids_json);
        $this->assertNotNull($this->compile($next)['cma_valuation']['cma_middle']);
    }

    // ═══ D. Confirm & Generate freezes a price, or stops ═══════════════════════

    /** Audit #1 — no comparable sales at all: Confirm is refused server-side, nothing frozen. */
    public function test_confirm_is_refused_when_there_are_no_comparable_sales(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_IN_ANALYSIS,
        ]);

        $resp = $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id));

        $resp->assertRedirect(route('presentations.analysis', $version->presentation_id));
        $resp->assertSessionHas('error', PresentationPriceReadiness::message(PresentationPriceReadiness::NO_COMPS));
        $fresh = $version->fresh();
        $this->assertSame(PresentationVersion::REVIEW_IN_ANALYSIS, $fresh->review_status);
        $this->assertNull($fresh->snapshot_payload);
        $this->assertNull($fresh->published_at);
    }

    public function test_confirm_names_unpriced_comps_when_that_is_the_problem(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_IN_ANALYSIS,
        ]);
        $this->seedComps($agencyId, $version->presentation_id, 2)
            ->each(fn ($c) => $c->forceFill(['sold_price_inc' => 0])->save());

        $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertSessionHas('error', PresentationPriceReadiness::message(PresentationPriceReadiness::NO_PRICED_COMPS));
        $this->assertNull($version->fresh()->snapshot_payload);
    }

    /** Audit — "frozen price not written on publish": the price is in the frozen blob. */
    public function test_confirm_freezes_the_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_IN_ANALYSIS,
        ]);
        $this->seedComps($agencyId, $version->presentation_id, 3);

        $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id));

        $fresh = $version->fresh();
        $this->assertSame(PresentationVersion::REVIEW_PUBLISHED, $fresh->review_status);
        $this->assertSame(1_700_000, $fresh->snapshot_payload['cma_valuation']['cma_middle']);
        $this->assertGreaterThan(0, $fresh->snapshot_payload['cma_valuation']['cma_lower']);
        $this->assertGreaterThan(0, $fresh->snapshot_payload['cma_valuation']['cma_upper']);
    }

    /** Audit #3 — a stale selection no longer freezes a blank price on Confirm. */
    public function test_confirm_freezes_a_price_even_when_the_stored_selection_is_stale(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_IN_ANALYSIS,
        ]);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id]])->save();
        $comps[0]->delete();

        $this->actingAs($user)->post(route('presentations.analysis.confirm', $version->presentation_id));

        $this->assertNotNull($version->fresh()->snapshot_payload['cma_valuation']['cma_middle']);
    }

    // ═══ E. The seller PDF / pack ══════════════════════════════════════════════

    public function test_pdf_download_is_refused_for_a_version_confirmed_without_a_price(): void
    {
        config(['features.presentation_pdf_v1' => true]);
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedFrozenVersion($agencyId, $user->id, null);

        $resp = $this->actingAs($user)->get(route('presentations.versions.pdf', [$version->presentation_id, $version->id]));

        $resp->assertRedirect(route('presentations.show', $version->presentation_id));
        $resp->assertSessionHas('error', PresentationPriceReadiness::message(PresentationPriceReadiness::FROZEN_WITHOUT_PRICE));
    }

    public function test_complete_pack_is_refused_for_a_version_confirmed_without_a_price(): void
    {
        config(['features.presentation_pdf_v1' => true]);
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedFrozenVersion($agencyId, $user->id, null);

        $resp = $this->actingAs($user)->get(route('presentations.versions.complete-pack', [$version->presentation_id, $version->id]));

        $resp->assertRedirect(route('presentations.show', $version->presentation_id));
        $resp->assertSessionHas('error', PresentationPriceReadiness::message(PresentationPriceReadiness::FROZEN_WITHOUT_PRICE));
    }

    // ═══ F. The seller web page ════════════════════════════════════════════════

    public function test_public_page_does_not_serve_a_version_without_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedFrozenVersion($agencyId, $user->id, null);
        $link = $this->makeLink($version, $user);

        $resp = $this->get(route('presentation.public.show', $link->token));

        $resp->assertStatus(404);
        $resp->assertSee('being finalised');
        $resp->assertDontSee('What we');
    }

    /** Facts the property does not have are left out, never printed as a dash. */
    public function test_public_page_omits_facts_it_does_not_know_instead_of_printing_a_dash(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedFrozenVersion($agencyId, $user->id, 1_700_000);
        $version->presentation->forceFill([
            'bedrooms' => 3, 'bathrooms' => null, 'floor_area_m2' => null, 'erf_size_m2' => null,
        ])->save();
        $link = $this->makeLink($version, $user);

        $resp = $this->get(route('presentation.public.show', $link->token));

        $resp->assertOk();
        $resp->assertSee('Bedrooms');
        $resp->assertDontSee('Bathrooms');
        $resp->assertDontSee('Floor area');
        $resp->assertDontSee('Erf size');
        $resp->assertDontSee('Municipal value');
        $html = $resp->getContent();
        $this->assertDoesNotMatchRegularExpression('/class="val missing">—</', $html);
    }

    public function test_public_page_shows_a_fact_when_it_is_known(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedFrozenVersion($agencyId, $user->id, 1_700_000);
        $version->presentation->forceFill(['bathrooms' => 2, 'erf_size_m2' => 800, 'floor_area_m2' => 150])->save();
        $link = $this->makeLink($version, $user);

        $this->get(route('presentation.public.show', $link->token))
            ->assertOk()
            ->assertSee('Bathrooms')
            ->assertSee('Erf size')
            ->assertSee('Floor area');
    }

    // ═══ G. Sending ════════════════════════════════════════════════════════════

    public function test_share_link_cannot_be_created_for_a_presentation_without_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $this->expectException(PresentationPriceMissingException::class);
        (new SnapshotLinkService())->createLink($version->presentation, ['created_by_user_id' => $user->id]);
    }

    public function test_share_link_is_created_when_there_is_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $this->seedComps($agencyId, $version->presentation_id, 3);

        $link = (new SnapshotLinkService())->createLink($version->presentation, ['created_by_user_id' => $user->id]);

        $this->assertSame($version->id, (int) $link->presentation_version_id);
    }

    public function test_share_link_endpoint_shows_the_plain_message(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $resp = $this->actingAs($user)->from('/back')->post(
            route('presentations.snapshot-links.store', $version->presentation_id),
            ['mode' => 'full'],
        );

        $resp->assertSessionHas('error', PresentationPriceReadiness::message(PresentationPriceReadiness::NO_COMPS));
        $this->assertSame(0, PresentationSnapshotLink::withoutGlobalScopes()->count());
    }

    public function test_delivery_send_is_refused_without_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $resp = $this->actingAs($user)->postJson(
            route('presentations.deliveries.send', $version->presentation_id),
            ['recipients' => [['name' => 'Sam Seller', 'email' => 'sam@example.test', 'channel' => 'copy']]],
        );

        $resp->assertStatus(422)->assertJson(['ok' => false, 'price_missing' => true]);
        $this->assertStringContainsString('no comparable sales', $resp->json('errors.presentation'));
        $this->assertSame(0, PresentationSnapshotLink::withoutGlobalScopes()->count());
    }

    public function test_delivery_preview_flags_a_missing_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $resp = $this->actingAs($user)->postJson(
            route('presentations.deliveries.preview', $version->presentation_id),
            ['recipients' => [['name' => 'Sam Seller', 'email' => 'sam@example.test', 'channel' => 'copy']]],
        );

        $resp->assertOk()->assertJson(['valid' => false]);
        $this->assertStringContainsString('no comparable sales', $resp->json('errors.presentation'));
    }

    // ═══ H. Seller Live ════════════════════════════════════════════════════════

    public function test_seller_live_is_refused_without_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $this->actingAs($user)->get(route('presentations.seller-live', $version->presentation_id))
            ->assertRedirect(route('presentations.show', $version->presentation_id))
            ->assertSessionHas('error');
    }

    /** Audit #11 — Seller Live used ALL comps and ignored the agent's picks. */
    public function test_seller_live_uses_the_agents_picks(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id]])->save();

        $resp = $this->actingAs($user)->get(route('presentations.seller-live', $version->presentation_id));

        $resp->assertOk();
        $this->assertSame(1_600_000, $resp->viewData('liveData')['cmaMiddle']);
    }

    // ═══ I. The agent is told, on every screen ═════════════════════════════════

    public function test_review_shows_a_banner_when_there_is_no_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $this->actingAs($user)->get(route('presentations.review.show', $version->id))
            ->assertOk()
            ->assertSee('No price yet')
            ->assertSee(PresentationPriceReadiness::message(PresentationPriceReadiness::NO_COMPS));
    }

    public function test_review_shows_no_banner_when_there_is_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $this->seedComps($agencyId, $version->presentation_id, 3);

        $this->actingAs($user)->get(route('presentations.review.show', $version->id))
            ->assertOk()
            ->assertDontSee('No price yet');
    }

    public function test_review_says_so_when_earlier_picks_were_replaced(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);
        $version->forceFill(['included_comp_ids_json' => [$comps[0]->id]])->save();
        $comps[0]->delete();

        $this->actingAs($user)->get(route('presentations.review.show', $version->id))
            ->assertOk()
            ->assertSee('earlier picks are no longer available');
    }

    public function test_analysis_replaces_the_vanished_cma_block_with_the_banner_and_hides_confirm(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_IN_ANALYSIS,
        ]);
        $this->seedSnapshot($version, $user);

        $resp = $this->actingAs($user)->get(route('presentations.analysis', $version->presentation_id));

        $resp->assertOk();
        $resp->assertSee('No price yet');
        $resp->assertSee(PresentationPriceReadiness::message(PresentationPriceReadiness::NO_COMPS));
        $resp->assertDontSee('Confirm &amp; Generate', false);
    }

    public function test_analysis_offers_confirm_when_there_is_a_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_IN_ANALYSIS,
        ]);
        $this->seedComps($agencyId, $version->presentation_id, 3);
        $this->seedSnapshot($version, $user);

        $this->actingAs($user)->get(route('presentations.analysis', $version->presentation_id))
            ->assertOk()
            ->assertSee('Confirm &amp; Generate', false)
            ->assertDontSee('No price yet');
    }

    public function test_overview_warns_when_the_latest_version_has_no_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $this->actingAs($user)->get(route('presentations.show', $version->presentation_id))
            ->assertOk()
            ->assertSee('No price yet');
    }

    // ═══ J. The shared check itself ════════════════════════════════════════════

    public function test_readiness_reasons(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $p = $version->presentation;

        $this->assertSame(PresentationPriceReadiness::NO_COMPS, PresentationPriceReadiness::forLiveVersion($p, $version)['reason']);

        $comps = $this->seedComps($agencyId, $p->id, 2);
        $comps->each(fn ($c) => $c->forceFill(['sold_price_inc' => 0])->save());
        $this->assertSame(PresentationPriceReadiness::NO_PRICED_COMPS, PresentationPriceReadiness::forLiveVersion($p->fresh(), $version)['reason']);

        $comps[0]->forceFill(['sold_price_inc' => 2_000_000])->save();
        $ready = PresentationPriceReadiness::forLiveVersion($p->fresh(), $version);
        $this->assertTrue($ready['ready']);
        $this->assertSame(2_000_000, $ready['price']);
        $this->assertNull($ready['message']);
    }

    public function test_readiness_judges_a_confirmed_version_on_its_frozen_price(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $noPrice = $this->seedFrozenVersion($agencyId, $user->id, null);
        $withPrice = $this->seedFrozenVersion($agencyId, $user->id, 1_700_000);

        $this->assertSame(
            PresentationPriceReadiness::FROZEN_WITHOUT_PRICE,
            PresentationPriceReadiness::forDocument($noPrice->presentation, $noPrice)['reason'],
        );
        $this->assertSame(1_700_000, PresentationPriceReadiness::forDocument($withPrice->presentation, $withPrice)['price']);
    }

    // ═══ Helpers ═══════════════════════════════════════════════════════════════

    private function compile(PresentationVersion $version): array
    {
        $presentation = Presentation::withoutGlobalScopes()->findOrFail($version->presentation_id);
        return (new AnalysisDataService())->compile($presentation, $version->fresh());
    }

    /** A confirmed version whose frozen payload carries $middle (null = frozen WITHOUT a price). */
    private function seedFrozenVersion(int $agencyId, int $userId, ?int $middle): PresentationVersion
    {
        $version = $this->seedPresentationWithVersion($agencyId, $userId, [
            'review_status'     => PresentationVersion::REVIEW_PUBLISHED,
            'published_at'      => now(),
            'ai_summary_text'   => 'A summary for the seller.',
            'snapshot_taken_at' => now(),
            'snapshot_payload'  => [
                'cma_valuation' => [
                    'cma_lower'  => $middle ? (int) round($middle * 0.93) : null,
                    'cma_middle' => $middle,
                    'cma_upper'  => $middle ? (int) round($middle * 1.07) : null,
                ],
                'subject_property' => [],
            ],
        ]);
        return $version;
    }

    /** A prior analysis run — without one the Analysis screen sends the agent back to the Overview. */
    private function seedSnapshot(PresentationVersion $version, User $user): void
    {
        \App\Models\PresentationSnapshot::create([
            'presentation_id'      => $version->presentation_id,
            'generated_by_user_id' => $user->id,
            'created_by_user_id'   => $user->id,
            'computed_json'        => '{}',
            'snapshot_json'        => '{}',
            'generated_at'         => now(),
        ]);
    }

    private function makeLink(PresentationVersion $version, User $user): PresentationSnapshotLink
    {
        return PresentationSnapshotLink::create([
            'presentation_id'         => $version->presentation_id,
            'presentation_version_id' => $version->id,
            'agency_id'               => $version->agency_id,
            'token'                   => Str::random(40),
            'mode'                    => 'full',
            'created_by_user_id'      => $user->id,
            'expires_at'              => now()->addDays(10),
        ]);
    }

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId,
            'agency_id'  => $agencyId,
            'name'       => 'Default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    private function seedPresentationWithVersion(int $agencyId, int $userId, array $versionOverrides = []): PresentationVersion
    {
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => $userId,
            'title'              => 'Always-price Test Presentation',
            'property_address'   => '123 Test Street',
            'suburb'             => 'Testville',
            'property_type'      => 'house',
            'bedrooms'           => 3,
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        return PresentationVersion::create(array_merge([
            'agency_id'          => $agencyId,
            'presentation_id'    => $presentation->id,
            'compiled_by'        => $userId,
            'blueprint_version'  => 'v1',
            'data_snapshot_json' => json_encode(['sections' => []]),
            'compiled_at'        => now(),
            'review_status'      => PresentationVersion::REVIEW_AWAITING,
            'awaiting_review_at' => now(),
        ], $versionOverrides));
    }

    /** Mimic MicSnapshotHydrator: retire every comp, insert fresh copies of the same sales. */
    private function rehydrate(\Illuminate\Support\Collection $old): \Illuminate\Support\Collection
    {
        $fresh = $old->map(fn (PresentationSoldComp $c) => PresentationSoldComp::create(
            collect($c->getAttributes())->except(['id', 'deleted_at'])->all()
        ));
        $old->each(fn (PresentationSoldComp $c) => $c->delete());
        return $fresh->values();
    }

    /** @return \Illuminate\Support\Collection<PresentationSoldComp> prices 1.6M, 1.7M, 1.8M … */
    private function seedComps(int $agencyId, int $presentationId, int $count): \Illuminate\Support\Collection
    {
        $existing = PresentationSoldComp::withoutGlobalScopes()->where('presentation_id', $presentationId)->count();
        $rows = collect();
        for ($i = 1; $i <= $count; $i++) {
            $n = $existing + $i;
            $rows->push(PresentationSoldComp::create([
                'agency_id'       => $agencyId,
                'presentation_id' => $presentationId,
                'sold_date'       => now()->subDays(30 * $n)->toDateString(),
                'sold_price_inc'  => 1_500_000 + ($n * 100_000),
                'suburb'          => 'Testville',
                'property_type'   => 'house',
                'beds'            => 3,
                'baths'           => 2,
                'size_m2'         => 200,
                'raw_row_json'    => json_encode([
                    'address'   => $n . ' Test Avenue',
                    'latitude'  => -30.84 + ($n * 0.001),
                    'longitude' => 30.39 + ($n * 0.001),
                ]),
                'parser_version'  => 'test-v1',
                'is_demo'         => false,
            ]));
        }
        return $rows;
    }
}
