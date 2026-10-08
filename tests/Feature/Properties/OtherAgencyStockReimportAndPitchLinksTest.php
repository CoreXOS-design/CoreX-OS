<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\OtherAgencyStockConsent;
use App\Models\OtherAgencyStockUnlock;
use App\Models\Property;
use App\Models\PropertyExternalSource;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Properties\OtherAgencyStockActionRules as Rules;
use App\Services\Properties\OtherAgencyStockAlreadyAgencyStockException as AlreadyAgencyStock;
use App\Services\Properties\OtherAgencyStockStatusGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §5e (Johan's rulings, 2026-10-08):
 *  1. Once an authorised user has unlocked an Other Agency Stock property and moved its status away it is the
 *     agency's own stock — re-importing the same portal listing must NOT turn it back into Other Agency Stock or
 *     overwrite it. It is refused with a plain message and a link; a property still in OAS status updates in place.
 *  2. The standalone brochure stays allowed for Other Agency Stock — a declared decision in the action registry.
 *  3. Pitch Seller links on other screens (Market Intelligence, the prospecting list) are hidden for OAS properties.
 */
class OtherAgencyStockReimportAndPitchLinksTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'public/chrome-extension/portal-capture/tests/fixtures/p24/townhouse-uvongo-117580701.payload.json';

    private Agency $agency;
    private User $agent;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->agency  = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch        = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent   = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'branch_manager']);

        RolePermission::create([
            'role' => 'branch_manager', 'permission_key' => OtherAgencyStockStatusGate::PERMISSION_KEY,
            'agency_id' => $this->agency->id,
        ]);
        foreach (['agent', 'branch_manager'] as $role) {
            RolePermission::create(['role' => $role, 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'access_properties', 'agency_id' => $this->agency->id]);
        }
    }

    private function payload(): array
    {
        $d = json_decode(file_get_contents(base_path(self::FIXTURE)), true, 512, JSON_THROW_ON_ERROR);
        unset($d['_title'], $d['_expected_photo_count']);

        return $d + ['consent' => true];
    }

    private function import(array $payload)
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->agent);

        return $this->postJson('/api/v1/other-agency-stock/import', $payload);
    }

    private function importedProperty(): Property
    {
        $r = $this->import($this->payload())->assertOk();

        return Property::withoutGlobalScopes()->findOrFail($r->json('property_id'));
    }

    // ── 1. Re-import after unlock ────────────────────────────────────────────────

    public function test_reimporting_a_listing_that_is_now_agency_stock_is_refused_and_changes_nothing(): void
    {
        $p = $this->importedProperty();

        // An authorised user moves it on and the agency works it as its own.
        $this->actingAs($this->manager);
        $p->update(['status' => 'draft', 'title' => 'Our own wording', 'price' => 1750000]);
        $before = $p->fresh();
        Queue::fake(); // forget the jobs the first import and the status change queued
        $counts = [
            'consents' => OtherAgencyStockConsent::count(),
            'unlocks'  => OtherAgencyStockUnlock::count(),
            'sources'  => PropertyExternalSource::withoutGlobalScopes()->count(),
            'props'    => Property::withoutGlobalScopes()->count(),
        ];

        $r = $this->import($this->payload());

        $r->assertStatus(409)
          ->assertJsonPath('success', false)
          ->assertJsonPath('code', AlreadyAgencyStock::CODE)
          ->assertJsonPath('property_id', $p->id)
          ->assertJsonPath('url', url('/corex/properties/' . $p->id));
        $this->assertStringContainsString('already on CoreX as agency stock', $r->json('message'));

        $after = Property::withoutGlobalScopes()->findOrFail($p->id);
        $this->assertSame('draft', $after->status, 'not flipped back to Other Agency Stock');
        $this->assertSame('Our own wording', $after->title, 'not overwritten');
        $this->assertSame(1750000, (int) $after->price);
        $this->assertEquals($before->updated_at, $after->updated_at, 'not even touched');

        $this->assertSame($counts, [
            'consents' => OtherAgencyStockConsent::count(),
            'unlocks'  => OtherAgencyStockUnlock::count(),
            'sources'  => PropertyExternalSource::withoutGlobalScopes()->count(),
            'props'    => Property::withoutGlobalScopes()->count(),
        ], 'no consent, unlock, source or property row written by the refused import');
        Queue::assertNothingPushed();
    }

    public function test_a_property_still_in_other_agency_stock_status_updates_in_place_as_before(): void
    {
        $p = $this->importedProperty();

        $r = $this->import($this->payload())->assertOk();

        $this->assertSame($p->id, $r->json('property_id'));
        $this->assertSame(Property::STATUS_OTHER_AGENCY_STOCK, $p->fresh()->status);
        $this->assertSame(2, OtherAgencyStockConsent::count(), 'a fresh consent per (re-)import, as before');
    }

    public function test_the_same_listing_can_be_reimported_again_if_the_status_is_moved_back_to_other_agency_stock(): void
    {
        $p = $this->importedProperty();
        $this->actingAs($this->manager);
        $p->update(['status' => 'draft']);
        $p->fresh()->update(['status' => Property::STATUS_OTHER_AGENCY_STOCK]);

        $this->import($this->payload())->assertOk()->assertJsonPath('property_id', $p->id);
    }

    public function test_the_refusal_is_the_service_not_only_the_endpoint(): void
    {
        $p = $this->importedProperty();
        $this->actingAs($this->manager);
        $p->update(['status' => 'draft']);

        $this->expectException(AlreadyAgencyStock::class);
        app(\App\Services\Properties\OtherAgencyStockImportService::class)
            ->import($this->payload(), $this->agent);
    }

    public function test_the_extension_shows_the_refusal_with_a_link_and_does_not_treat_it_as_a_generic_error(): void
    {
        $bg = file_get_contents(base_path('public/chrome-extension/portal-capture/background.js'));
        $popup = file_get_contents(base_path('public/chrome-extension/portal-capture/popup.js'));

        $this->assertStringContainsString("response.status === 409", $bg);
        $this->assertStringContainsString("'already_agency_stock'", $bg);
        $this->assertStringContainsString('code: err.code', $bg);
        $this->assertStringContainsString("res.code === 'already_agency_stock'", $popup);
        $this->assertStringContainsString('Open it in CoreX', $popup);
        // The server text goes in as text, never as HTML.
        $this->assertStringContainsString('text.textContent = message', $popup);
        $this->assertDoesNotMatchRegularExpression('/oasMsg\.innerHTML/', $popup);
    }

    // ── 2. Standalone brochure: a declared decision ──────────────────────────────

    public function test_standalone_brochure_is_a_declared_allowed_decision_and_the_route_does_not_refuse_it(): void
    {
        $this->assertSame(Rules::ALLOWED, Rules::mode('brochure'));
        $this->assertContains('brochure', Rules::NOT_ON_PANEL, 'it is a route, not a panel button');
        $this->assertSame('', Rules::reason('brochure'));

        $p = $this->importedProperty();
        $this->assertFalse(Rules::isBlocked('brochure', $p));
        Rules::assertAllowed('brochure', $p); // does not throw

        // The route itself carries no Other Agency Stock refusal (it would be the registry call below).
        $src = file_get_contents(app_path('Http/Controllers/CoreX/PropertyController.php'));
        $start = strpos($src, 'public function brochure(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, strpos($src, 'public function ', $start + 10) - $start);
        $this->assertStringNotContainsString('OtherAgencyStockActionRules', $body, 'brochure is allowed for Other Agency Stock — Johan, 2026-10-08');
    }

    // ── 3. Pitch Seller links elsewhere are hidden, not left to refuse ───────────

    private function property(string $status): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'external_id' => (string) Str::uuid(),
            'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo', 'property_type' => 'house',
            'status' => $status, 'price' => 1500000, 'beds' => 3, 'baths' => 2, 'garages' => 1,
            'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);
    }

    public function test_blocked_property_ids_names_only_the_other_agency_stock_ones_in_one_query(): void
    {
        $oas = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $draft = $this->property('draft');

        \DB::enableQueryLog();
        $blocked = Rules::blockedPropertyIds('pitch_seller', [$oas->id, $draft->id, null, 0, (string) $oas->id]);
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertSame([$oas->id => true], $blocked);
        $this->assertSame(1, $queries);
        $this->assertSame([], Rules::blockedPropertyIds('pitch_seller', []));
        $this->assertSame([], Rules::blockedPropertyIds('archive', [$oas->id]), 'an allowed action blocks nothing');
    }

    public function test_the_prospecting_list_state_marks_matched_oas_properties_so_the_pitch_link_can_be_hidden(): void
    {
        $oas = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $draft = $this->property('draft');

        $listings = [
            (object) ['id' => 1, 'matched_property_id' => $oas->id, 'normalized_address' => null],
            (object) ['id' => 2, 'matched_property_id' => $draft->id, 'normalized_address' => null],
        ];
        $state = app(\App\Services\Prospecting\ProspectingListingStateEnricher::class)->enrich($listings, $this->agency->id);

        $this->assertSame([$oas->id => true], $state['oas_properties']);
        $this->assertArrayHasKey($oas->id, $state['promotions'], 'still "in stock" for every other purpose');
        $this->assertArrayHasKey('oas_properties', app(\App\Services\Prospecting\ProspectingListingStateEnricher::class)->enrich([], $this->agency->id));
    }

    public function test_the_prospecting_list_template_has_no_pitch_link_for_other_agency_stock(): void
    {
        $src = file_get_contents(resource_path('views/prospecting/index_legacy_body.blade.php'));
        $oasBranch = strpos($src, '@elseif($stOasStock)');
        $stockPitch = strpos($src, "route('seller-outreach.entry.from-property'");

        $this->assertNotFalse($oasBranch);
        $this->assertNotFalse($stockPitch);
        $this->assertLessThan($stockPitch, $oasBranch, 'the Other Agency Stock branch must come BEFORE the "Pitch (stock)" link');
    }

    public function test_the_market_intelligence_slide_over_hides_pitch_for_other_agency_stock(): void
    {
        $oas = $this->property(Property::STATUS_OTHER_AGENCY_STOCK);
        $draft = $this->property('draft');

        $render = function (Property $p, bool $isOas): string {
            return view('corex.market-intelligence._slideover-header', [
                'header' => [
                    'photo_url' => null, 'address' => '1 Test Road', 'suburb' => 'Uvongo', 'beds' => 3, 'baths' => 2, 'garages' => 1,
                    'property_type' => 'House', 'agency' => null, 'portal_ref' => 'P24-1', 'portal_source' => 'p24', 'portal_url' => null,
                    'price' => 1500000, 'in_stock' => true, 'matched_property_id' => $p->id, 'matched_is_oas' => $isOas,
                    'tracked_property_id' => null,
                ],
                'viewer' => ['is_manager' => false, 'can_pitch' => true, 'id' => $this->agent->id],
                'state' => [],
                'listing' => (object) ['id' => 99, 'portal_source' => 'p24'],
                'outreachWindow' => ['allowed' => true],
            ])->render();
        };

        $link = route('seller-outreach.entry.from-property', $oas->id, false);

        $this->assertStringNotContainsString($link, $render($oas, true), 'no Pitch link for Other Agency Stock');
        $this->assertStringContainsString(route('seller-outreach.entry.from-property', $draft->id, false), $render($draft, false), 'ordinary stock keeps its Pitch');
    }

    public function test_the_property_intelligence_panel_flags_a_matched_other_agency_stock_property(): void
    {
        $src = file_get_contents(app_path('Services/Prospecting/PropertyIntelligencePanelService.php'));
        $this->assertStringContainsString("'matched_is_oas'", $src);
        $this->assertStringContainsString("blockedPropertyIds('pitch_seller'", $src);
    }
    // ── The new "building" group must not break saving an Other Agency Stock imported before it existed ──

    public function test_an_older_other_agency_stock_property_still_saves_when_the_page_posts_the_new_empty_building_group(): void
    {
        $p = $this->importedProperty();

        // As stored before 8 Oct: four feature groups, no "building".
        $sj = $p->spaces_json;
        unset($sj['features']['building']);
        $flat = \App\Services\Properties\OtherAgencyStockFeatureMapper::flatFeatures($sj);
        \DB::table('properties')->where('id', $p->id)->update(['spaces_json' => json_encode($sj), 'features_json' => json_encode($flat)]);
        $p = Property::withoutGlobalScopes()->findOrFail($p->id);
        $this->assertArrayNotHasKey('building', $p->spaces_json['features']);

        // What the edit page posts: every group the page knows (so "building" is there, empty), plus an internal field edit.
        $posted = $sj;
        $posted['features'] = $sj['features'] + ['building' => []];
        $payload = [
            'title' => $p->title, 'price' => (int) $p->price, 'suburb' => $p->suburb, 'city' => $p->city, 'province' => $p->province,
            'beds' => $p->beds, 'baths' => (int) $p->baths, 'garages' => $p->garages, 'agent_id' => $p->agent_id,
            'status' => $p->status, 'street_number' => '42',
            'spaces_json' => json_encode($posted),
        ];

        $this->actingAs($this->agent)->put(route('corex.properties.update', $p), $payload)->assertSessionHasNoErrors();

        $fresh = Property::withoutGlobalScopes()->findOrFail($p->id);
        $this->assertSame('42', $fresh->street_number, 'the internal edit saved');
        $this->assertEquals($sj, $fresh->spaces_json, 'the locked column is exactly as it was');
    }
}
