<?php

namespace Tests\Feature\Properties;

use App\Http\Controllers\Tools\AdManagerController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Presentations\PresentationGeneratorService;
use App\Services\Properties\OtherAgencyStockActionBlockedException;
use App\Services\Properties\OtherAgencyStockActionRules as Rules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §5c (Johan, 2026-10-07): the consent an agent ticks on an
 * Other Agency Stock import is ONLY permission to take a buyer to the property. So four
 * actions on the property page are explicit OAS rules — Pitch Seller, Ad Builder, Market
 * Property, Generate Presentation — each greyed with a plain reason AND refused server-side,
 * independent of the marketing-compliance gate. The block follows the STATUS: when an
 * authorised user moves the property away from other_agency_stock, every block lifts.
 */
class OtherAgencyStockActionRulesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $manager; // branch_manager — holds other_agency_stock.change_status

    /** Actions the registry blocks, and the property-page route/service behind each. */
    private const BLOCKED = ['ad_builder', 'market_property', 'pitch_seller', 'generate_presentation'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency  = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent   = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager']);

        RolePermission::create([
            'role' => 'branch_manager', 'permission_key' => \App\Services\Properties\OtherAgencyStockStatusGate::PERMISSION_KEY,
            'agency_id' => $this->agency->id,
        ]);
        foreach (['agent', 'branch_manager'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
            foreach (['access_properties', 'create_presentations', 'access_presentations', 'outreach.compose', 'properties.share', 'compliance.whistleblow.create'] as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id]);
            }
        }
    }

    private function property(string $status): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->agent->id,
            'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Listing ' . Str::random(4),
            'suburb' => 'Uvongo',
            'property_type' => 'house',
            'status' => $status,
            'price' => 1500000,
            'description' => 'Sea views',
            'beds' => 3, 'baths' => 2, 'garages' => 1,
            'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);
    }

    private function oas(): Property
    {
        return $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
    }

    private function contactLinkedTo(Property $p): Contact
    {
        $c = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Sam', 'last_name' => 'Seller', 'phone' => '0821234567']);
        \App\Services\Property\ContactPropertyLinker::link($c->id, $p->id, 'seller');

        return $c;
    }

    private function reason(string $action): string
    {
        return Rules::reason($action);
    }

    // ── The registry + the "declare it or fail" guard ────────────────────────────

    public function test_an_action_with_no_declared_rule_fails_loudly_never_silently_allowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Rules::mode('some_brand_new_button');
    }

    public function test_every_blocked_rule_has_a_plain_reason_and_nothing_else_does_not(): void
    {
        foreach (Rules::keys() as $key) {
            if (Rules::mode($key) === Rules::BLOCKED) {
                $this->assertNotSame('', Rules::reason($key), "{$key} is blocked but has no reason to show");
                $this->assertStringContainsString('another agency', Rules::reason($key));
            }
        }
        $this->assertEqualsCanonicalizing(self::BLOCKED, array_values(array_filter(
            Rules::keys(), fn ($k) => Rules::mode($k) === Rules::BLOCKED
        )), 'the blocked set is the four Johan named — changing it is a decision, update the spec too');
    }

    /**
     * THE GUARD: every button on the property page's Actions panel must say what Other
     * Agency Stock does to it. Add a button without `data-oas-action="<key>"` (and a rule
     * in OtherAgencyStockActionRules::RULES) and this test fails.
     */
    public function test_every_button_on_the_actions_panel_declares_its_oas_behaviour(): void
    {
        $show  = file_get_contents(resource_path('views/corex/properties/show.blade.php'));
        $start = strpos($show, '{{-- Action stack --}}');
        $end   = strpos($show, '{{-- Readiness panel --}}');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $panel = substr($show, $start, $end - $start)
            . file_get_contents(resource_path('views/corex/properties/partials/share-actions.blade.php'));

        // Blade echoes can contain '>' (e.g. $property->id) — blank them so a tag can be read to its end.
        $flat = preg_replace('/\{\{.*?\}\}/s', 'X', $panel);
        preg_match_all('/<(?:button|a)\b[^>]*\bprop-action-btn\b[^>]*>/s', $flat, $tags);

        $this->assertNotEmpty($tags[0], 'found no action buttons — the panel markers moved, fix this guard');

        $declared = [];
        foreach ($tags[0] as $tag) {
            $this->assertMatchesRegularExpression(
                '/data-oas-action="([a-z_]+)"/', $tag,
                "An Actions-panel button has no data-oas-action. Declare how Other Agency Stock treats it:\n{$tag}"
            );
            preg_match('/data-oas-action="([a-z_]+)"/', $tag, $m);
            $this->assertContains($m[1], Rules::keys(), "'{$m[1]}' is not a key in OtherAgencyStockActionRules::RULES");
            $declared[] = $m[1];
        }

        $this->assertEqualsCanonicalizing(
            array_values(array_diff(Rules::keys(), Rules::NOT_ON_PANEL)), array_values(array_unique($declared)),
            'every rule in the registry must belong to a real button (no stale rules) — or be listed in NOT_ON_PANEL'
        );
    }

    // ── What the agent sees ──────────────────────────────────────────────────────

    public function test_the_four_buttons_are_greyed_with_their_reason_on_other_agency_stock(): void
    {
        $p = $this->oas();
        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $p))->assertOk()->getContent();

        foreach (self::BLOCKED as $key) {
            $this->assertStringContainsString(e($this->reason($key)), $html, "{$key}: reason not shown on hover");
            $this->assertMatchesRegularExpression(
                '/<button\b[^>]*data-oas-action="' . $key . '"[^>]*\bdisabled\b[^>]*>|<button\b[^>]*\bdisabled\b[^>]*data-oas-action="' . $key . '"[^>]*>/s',
                $html, "{$key}: not a disabled button"
            );
        }

        // No live link or generator behind any of them.
        $this->assertStringNotContainsString(route('corex.properties.ad', $p, false) . '"', $html);
        $this->assertStringNotContainsString(route('corex.properties.marketing.index', $p, false) . '"', $html);
        $this->assertStringNotContainsString(route('seller-outreach.entry.from-property', $p, false) . '"', $html);
        $this->assertStringNotContainsString('presentationGenerator({', $html);
    }

    public function test_the_actions_that_stay_available_are_not_blocked(): void
    {
        $p = $this->oas();
        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $p))->assertOk()->getContent();

        foreach (['live_preview', 'archive', 'report_non_compliance'] as $key) {
            $this->assertMatchesRegularExpression('/<button\b[^>]*data-oas-action="' . $key . '"/', $html, "{$key} should be on the panel");
            $this->assertDoesNotMatchRegularExpression(
                '/<button\b[^>]*data-oas-action="' . $key . '"[^>]*\bdisabled\b/s', $html, "{$key} must stay usable on Other Agency Stock"
            );
        }
        // Share is not drawn on the property page for OAS (share-actions.blade.php shows it from Core
        // Matches only, by design) — but the rule is declared: taking a buyer to it is what OAS is for.
        $this->assertSame(Rules::ALLOWED, Rules::mode('share_listing'));
    }

    public function test_the_four_blocks_do_not_depend_on_the_compliance_gate_and_a_normal_listing_is_untouched(): void
    {
        $normal = $this->property('draft');
        $html = $this->actingAs($this->agent)->get(route('corex.properties.show', $normal))->assertOk()->getContent();

        foreach (self::BLOCKED as $key) {
            $this->assertStringNotContainsString(e($this->reason($key)), $html, "{$key}: OAS reason leaked onto a normal listing");
        }
        // Pitch Seller and the presentation generator are plain live controls on a normal listing.
        $this->assertStringContainsString(route('seller-outreach.entry.from-property', $normal, false), $html);
        $this->assertStringContainsString('presentationGenerator({', $html);
    }

    public function test_the_properties_list_greys_the_ad_link_on_other_agency_stock(): void
    {
        $p = $this->oas();
        $html = $this->actingAs($this->agent)->get(route('corex.properties.index'))->assertOk()->getContent();

        $this->assertStringContainsString(e($this->reason('ad_builder')), $html);
        $this->assertStringNotContainsString(route('corex.properties.ad', $p, false) . '"', $html);
    }

    // ── Server-side: a direct address is refused, whatever the button looks like ─

    public function test_ad_builder_page_is_refused_on_a_direct_address(): void
    {
        $p = $this->oas();

        $this->actingAs($this->agent)->get(route('corex.properties.ad', $p))
            ->assertRedirect(route('corex.properties.show', $p))
            ->assertSessionHas('error', $this->reason('ad_builder'));

        $this->actingAs($this->agent)->getJson(route('corex.properties.ad', $p))
            ->assertStatus(403)->assertJson(['error' => $this->reason('ad_builder')]);
    }

    public function test_the_ad_manager_tool_refuses_to_build_an_ad_for_it_whoever_asks(): void
    {
        $p = $this->oas();
        $m = new \ReflectionMethod(AdManagerController::class, 'canAdvertise');
        $m->setAccessible(true);
        $c = app(AdManagerController::class);

        // Even the property's own agent, with the widest scope.
        $this->assertFalse($m->invoke($c, $this->agent, $p, 'all'));
        $this->assertTrue($m->invoke($c, $this->agent, $this->property('draft'), 'all'), 'a normal listing is unaffected');
    }

    public function test_market_property_page_copy_and_publish_are_all_refused(): void
    {
        $p = $this->oas();
        $this->actingAs($this->agent);

        $this->get(route('corex.properties.marketing.index', $p))
            ->assertRedirect(route('corex.properties.show', $p))
            ->assertSessionHas('error', $this->reason('market_property'));

        $this->postJson(route('corex.properties.marketing.generateCopy', $p), ['platform' => 'facebook'])
            ->assertStatus(403)->assertJson(['ok' => false, 'error' => $this->reason('market_property')]);

        $this->postJson(route('corex.properties.marketing.publish', $p), ['platforms' => ['facebook']])
            ->assertStatus(403)->assertJson(['ok' => false, 'error' => $this->reason('market_property')]);
    }

    public function test_generate_presentation_and_its_coverage_check_are_refused(): void
    {
        $p = $this->oas();
        $this->actingAs($this->agent);

        $this->postJson(route('corex.properties.generate-presentation', $p))
            ->assertStatus(403)->assertJson(['error' => $this->reason('generate_presentation')]);

        $this->getJson(route('corex.properties.presentation-coverage', $p))
            ->assertStatus(403)->assertJson(['error' => $this->reason('generate_presentation')]);

        $this->assertSame(0, \DB::table('presentations')->count(), 'nothing may be created');
    }

    public function test_the_presentation_service_itself_refuses_so_no_other_caller_can_generate(): void
    {
        $p = $this->oas();

        $this->expectException(OtherAgencyStockActionBlockedException::class);
        app(PresentationGeneratorService::class)->generateForProperty($p->id, $this->agent->id, $this->agency->id);
    }

    public function test_pitch_seller_entry_points_and_the_composer_are_refused(): void
    {
        $p = $this->oas();
        $contact = $this->contactLinkedTo($p);
        $this->actingAs($this->agent);

        $this->get(route('seller-outreach.entry.from-property', $p))
            ->assertRedirect(route('corex.properties.show', $p))
            ->assertSessionHas('error', $this->reason('pitch_seller'));

        $this->post(route('seller-outreach.entry.store-from-property', $p), ['first_name' => 'Pat', 'phone' => '0829999999'])
            ->assertRedirect(route('corex.properties.show', $p))
            ->assertSessionHas('error', $this->reason('pitch_seller'));

        // The composer addressed straight at the property.
        $this->get(route('seller-outreach.composer.show', ['contact' => $contact->id, 'property_id' => $p->id]))
            ->assertRedirect(route('corex.properties.show', $p))
            ->assertSessionHas('error', $this->reason('pitch_seller'));

        $this->postJson(route('seller-outreach.composer.submit', $contact->id), [
            'property_id' => $p->id, 'channel' => 'whatsapp', 'body' => 'Hello',
        ])->assertStatus(403)->assertJson(['error' => $this->reason('pitch_seller')]);

        $this->postJson(route('seller-outreach.composer.queue', $contact->id), [
            'property_id' => $p->id, 'channel' => 'whatsapp', 'body' => 'Hello',
        ])->assertStatus(403)->assertJson(['error' => $this->reason('pitch_seller')]);
    }

    public function test_linking_a_seller_to_other_agency_stock_is_still_allowed_and_the_composer_just_leaves_it_out(): void
    {
        $p = $this->oas();
        $contact = $this->contactLinkedTo($p);

        $this->assertSame(1, $p->contacts()->count(), 'the link itself stays allowed (spec §8b)');

        // Composer for that contact opens normally; the OAS property is simply not offered.
        $this->actingAs($this->agent)
            ->get(route('seller-outreach.composer.show', $contact->id))
            ->assertOk()
            ->assertViewHas('linkedProperties', fn ($list) => $list->isEmpty());
    }

    // ── The block is not forever ─────────────────────────────────────────────────

    public function test_every_block_lifts_when_an_authorised_user_changes_the_status_away(): void
    {
        $p = $this->oas();
        $contact = $this->contactLinkedTo($p);

        foreach (self::BLOCKED as $key) {
            $this->assertTrue(Rules::isBlocked($key, $p), "{$key} blocked while OAS");
        }

        // The agent cannot lift it; an authorised user can (OtherAgencyStockStatusGate, spec §7).
        $this->actingAs($this->agent);
        try {
            $p->update(['status' => 'draft']);
            $this->fail('an agent without the change-status permission must not be able to un-OAS it');
        } catch (ValidationException) {
        }
        $this->assertTrue($p->fresh()->isOtherAgencyStock());

        $this->actingAs($this->manager);
        $p->update(['status' => 'draft']);
        $p = $p->fresh();
        $this->assertFalse($p->isOtherAgencyStock());

        foreach (self::BLOCKED as $key) {
            $this->assertFalse(Rules::isBlocked($key, $p), "{$key} still blocked after status change");
        }

        $this->actingAs($this->agent);

        // Routes: no OAS refusal any more — they behave exactly as for any draft.
        $this->get(route('corex.properties.marketing.index', $p))->assertOk();
        // One linked seller: straight into the composer, exactly as for any property.
        $this->get(route('seller-outreach.entry.from-property', $p))
            ->assertRedirect(route('seller-outreach.composer.show', ['contact' => $contact->id, 'property_id' => $p->id]));
        $this->get(route('seller-outreach.composer.show', ['contact' => $contact->id, 'property_id' => $p->id]))->assertOk();
        $this->getJson(route('corex.properties.presentation-coverage', $p))->assertStatus(200);

        // The page: same buttons as an ordinary draft, and none carries the OAS reason.
        $html = $this->get(route('corex.properties.show', $p))->assertOk()->getContent();
        foreach (self::BLOCKED as $key) {
            $this->assertStringNotContainsString(e($this->reason($key)), $html);
        }
        $this->assertStringContainsString(route('seller-outreach.entry.from-property', $p, false), $html);
        $this->assertStringContainsString('presentationGenerator({', $html);
    }

    /** The real edit form, not just the model: who can move it, and what the saved property is afterwards. */
    public function test_the_edit_form_status_change_lifts_the_blocks_only_for_an_authorised_user(): void
    {
        $p = $this->oas();
        $payload = [
            'title' => $p->title, 'price' => (int) $p->price, 'suburb' => $p->suburb, 'city' => $p->city,
            'province' => $p->province, 'beds' => 3, 'baths' => 2, 'garages' => 1, 'agent_id' => $p->agent_id,
            'status' => 'draft',
        ];

        // An ordinary agent is refused and nothing changes.
        $this->actingAs($this->agent)->put(route('corex.properties.update', $p), $payload)
            ->assertSessionHasErrors(['status']);
        $this->assertTrue($p->fresh()->isOtherAgencyStock());
        $this->assertTrue(Rules::isBlocked('ad_builder', $p->fresh()));

        // An authorised user (branch manager) moves it; it is ordinary agency stock from that moment.
        $this->actingAs($this->manager)->put(route('corex.properties.update', $p), $payload)
            ->assertSessionHasNoErrors();
        $fresh = $p->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertFalse($fresh->isOtherAgencyStock());
        foreach (self::BLOCKED as $key) {
            $this->assertFalse(Rules::isBlocked($key, $fresh), "{$key} must be live again");
        }
    }

    public function test_the_ad_builder_page_opens_again_after_the_status_change(): void
    {
        $p = $this->oas();
        $this->actingAs($this->manager);
        $p->update(['status' => 'draft']);

        $resp = $this->actingAs($this->agent)->get(route('corex.properties.ad', $p->fresh()));
        $this->assertNotSame(403, $resp->getStatusCode());
        $this->assertNotSame(route('corex.properties.show', $p), $resp->headers->get('Location'), 'must not bounce back with the OAS refusal');
        $this->assertNull(session('error'));
    }
}
