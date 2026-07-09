<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Properties index — the per-property syndication control (AT-208).
 *
 * The control (card top-right, and before "View" in the list) opens a viewer
 * showing where the listing is published. It is present ONLY when both hold:
 *
 *   - the listing is allowed to market (compliance snapshot taken, or every
 *     readiness gate passed) — mirrors $isMarketable on the property page, and
 *   - the listing actually reaches at least one portal.
 *
 * Either condition failing removes the control entirely — a blocked listing,
 * or one syndicated nowhere, has no syndication to look at.
 */
final class PropertyIndexSyndicationControlTest extends TestCase
{
    use RefreshDatabase;

    /** The button carries this aria-label/title prefix — the marker we assert on. */
    private const MARKER = 'Syndication — live on';

    public function test_control_shows_for_a_marketable_listing_that_is_live_on_a_portal(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $p = $this->property($agencyId, $admin, 'ZZZ-Syndicated-House', [
            // Snapshot taken → marketing_status 'live' → marketable.
            'compliance_snapshot_at'  => now(),
            // Live on Property24 → portalLinks() reports a live portal + URL.
            'p24_ref'                 => '112233445',
            'p24_syndication_status'  => 'active',
        ]);

        $res = $this->get(route('corex.properties.index'))->assertOk();

        $res->assertSee('ZZZ-Syndicated-House');
        $res->assertSee(self::MARKER, false);
        $res->assertSee('Property24', false);
        // The viewer links straight out to the live listing.
        $res->assertSee('www.property24.com', false);
        $this->assertNotNull($p->fresh()->portalLinks()[1]['url']);
    }

    public function test_control_is_hidden_when_the_listing_reaches_no_portal(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Marketable, but published nowhere.
        $this->property($agencyId, $admin, 'ZZZ-Nowhere-House', [
            'compliance_snapshot_at' => now(),
        ]);

        $this->get(route('corex.properties.index'))
            ->assertOk()
            ->assertSee('ZZZ-Nowhere-House')
            ->assertDontSee(self::MARKER, false);
    }

    public function test_control_is_hidden_when_the_listing_may_not_be_published_yet(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Live on a portal, but no compliance snapshot and gates unmet → blocked.
        $this->property($agencyId, $admin, 'ZZZ-Blocked-House', [
            'p24_ref'                => '556677889',
            'p24_syndication_status' => 'active',
        ]);

        $res = $this->get(route('corex.properties.index'))->assertOk();
        $res->assertSee('ZZZ-Blocked-House');
        $res->assertSee('Blocked', false);
        $res->assertDontSee(self::MARKER, false);
    }

    public function test_panel_endpoint_serves_the_same_control_surface_as_the_property_page(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $p = $this->property($agencyId, $admin, 'ZZZ-Panel-House', [
            'compliance_snapshot_at'  => now(),
            'p24_ref'                 => '112233445',
            'p24_syndication_status'  => 'active',
        ]);

        $res = $this->getJson(route('api.v1.properties.syndication-panel', $p))->assertOk();
        $html = $res->json('html');

        // The live control surface — not a read-only summary.
        $this->assertStringContainsString('p24Syndication(', $html);
        $this->assertStringContainsString('ppSyndication(', $html);
        $this->assertStringContainsString('Deactivate', $html);
        $this->assertStringContainsString('Live preview', $html);
    }

    public function test_panel_endpoint_refuses_a_listing_that_may_not_be_marketed(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // No snapshot, gates unmet → the property page opens Compliance Status
        // instead of the panel; the endpoint must refuse for the same reason.
        $p = $this->property($agencyId, $admin, 'ZZZ-Blocked-Panel');

        $this->getJson(route('api.v1.properties.syndication-panel', $p))->assertForbidden();
    }

    /**
     * The panel is fetched by a cookie-authed browser, so its route MUST sit on
     * the `web` middleware stack. Registered under routes/api.php it 401s with
     * "Unauthenticated." in the browser, because bootstrap/app.php strips
     * Sanctum's EnsureFrontendRequestsAreStateful from the `api` group (mobile
     * is bearer-token only). A guest redirect proves the session stack is live.
     */
    public function test_panel_endpoint_runs_on_the_session_authenticated_web_stack(): void
    {
        $this->assertContains(
            'web',
            app('router')->getRoutes()->getByName('api.v1.properties.syndication-panel')->gatherMiddleware(),
            'The syndication-panel route left the web group — a browser fetch will be Unauthenticated.'
        );

        [$agencyId, $admin] = $this->agencyWithAdmin();
        $p = $this->property($agencyId, $admin, 'ZZZ-Guest-House', ['compliance_snapshot_at' => now()]);

        $this->get(route('api.v1.properties.syndication-panel', $p))->assertRedirect(route('login'));
    }

    public function test_property_page_still_renders_the_shared_panel(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $p = $this->property($agencyId, $admin, 'ZZZ-Show-House', [
            'compliance_snapshot_at' => now(),
        ]);

        $this->get(route('corex.properties.show', $p))
            ->assertOk()
            // Panel markup + its Alpine components both survive the extraction.
            ->assertSee('p24Syndication(', false)
            ->assertSee('function p24Syndication(config)', false)
            ->assertSee('Live preview', false);
    }

    /**
     * The Live badge means "advertised on a portal", NOT `published_at`.
     *
     * `published_at` is the legacy HFC-Premium publish flag; no syndication path
     * writes it. Keying the badge off it meant two listings syndicated
     * identically showed different badges — the bug this pins shut.
     */
    public function test_live_badge_follows_the_portals_not_the_legacy_published_at_flag(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        // Live on P24, never "published" → badge.
        $live = $this->property($agencyId, $admin, 'ZZZ-Portal-Live', [
            'p24_ref'                => '112233445',
            'p24_syndication_status' => 'active',
            'published_at'           => null,
        ]);

        // "Published" long ago, on no portal → no badge.
        $stale = $this->property($agencyId, $admin, 'ZZZ-Published-Only', [
            'published_at' => now(),
        ]);

        $this->assertTrue($live->isLiveOnAnyPortal());
        $this->assertSame(['Property24'], $live->livePortalLabels());
        $this->assertFalse($stale->isLiveOnAnyPortal());

        // The SQL twin must agree with the PHP predicate, or the KPI tile and
        // its filter would count different listings than the badge marks.
        $liveIds = Property::liveOnAnyPortal()->pluck('id')->all();
        $this->assertContains($live->id, $liveIds);
        $this->assertNotContains($stale->id, $liveIds);

        // The tile counts exactly the badged cards.
        $this->get(route('corex.properties.index'))->assertOk();
        $this->get(route('corex.properties.index', ['status' => 'published']))
            ->assertOk()
            ->assertSee('ZZZ-Portal-Live')
            ->assertDontSee('ZZZ-Published-Only');
    }

    public function test_live_badge_counts_the_agency_website_as_a_portal(): void
    {
        [$agencyId, $admin] = $this->agencyWithAdmin();
        $this->actingAs($admin);

        $p = $this->property($agencyId, $admin, 'ZZZ-Website-Live');

        $this->assertFalse($p->isLiveOnAnyPortal());

        $keyId = (int) DB::table('agency_api_keys')->insertGetId([
            'agency_id' => $agencyId,
            'name'        => 'Main site',
            'key_prefix'  => Str::random(8),
            'secret_hash' => hash('sha256', Str::random(16)),
            'created_at'  => now(), 'updated_at' => now(),
        ]);
        DB::table('property_website_syndication')->insert([
            'agency_id'         => $agencyId,
            'property_id'       => $p->id,
            'agency_api_key_id' => $keyId,
            'enabled'           => true,
            'status'            => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($p->fresh()->isLiveOnAnyPortal());
        $this->assertContains($p->id, Property::liveOnAnyPortal()->pluck('id')->all());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function agencyWithAdmin(): array
    {
        $agencyId = $this->makeAgency();

        return [$agencyId, User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'admin',
        ])];
    }

    private function property(int $agencyId, User $agent, string $title, array $attrs = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $agent->id,
            'title'         => $title,
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'house',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
        ], $attrs));
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }
}
