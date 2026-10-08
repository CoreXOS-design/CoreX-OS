<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Properties\DaysOnMarket;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Days on market = days since the START OF THE CURRENT ON-MARKET PERIOD (the current advert
 * going live) - never created_at / the import day (Johan hotfix 2026-10-08: "a seller is sitting
 * with a link that shows 104 days"). ONE calculation (App\Services\Properties\DaysOnMarket)
 * feeds the seller live link, the Intelligence tab (tile + comparable listings) and every other
 * screen. No proof -> null: agents see a dash, the seller link hides the figure.
 */
final class DaysOnMarketStartDateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);
    }

    // ── imported stock ───────────────────────────────────────────────────

    public function test_imported_never_relisted_has_no_figure_whatever_stamps_it_carries(): void
    {
        // The real Staging shape: listed_date, p24_activated_at, pp_activated_at, first_marketed_at
        // are all the import day or a later refresh. The seller saw 104.
        $p = $this->imported([
            'listed_date' => now()->subDays(105)->toDateString(),
            'p24_activated_at' => now()->subDays(20),
            'pp_activated_at' => now()->subDays(95),
            'first_marketed_at' => now()->subDays(105),
        ]);
        $this->p24Log($p, 'submit', 200, now()->subDays(50));   // a refresh of an ad that was already live

        $this->assertNull(DaysOnMarket::for($p));
        $this->assertNull($this->tile($p));
    }

    public function test_imported_with_a_listing_date_that_is_not_the_import_day_counts_from_it(): void
    {
        $p = $this->imported(['listed_date' => now()->subDays(40)->toDateString()]);   // imported 105 days ago

        $this->assertSame(40, DaysOnMarket::for($p));
    }

    public function test_imported_stock_moved_onto_the_market_later_counts_from_that_day(): void
    {
        $p = $this->imported(['status' => 'active', 'listed_date' => now()->subDays(105)->toDateString()]);
        $this->statusChange($p, 'prospecting', 'active', now()->subDays(8));

        $this->assertSame(8, DaysOnMarket::for($p));
        $this->assertSame(8, $this->tile($p));
    }

    public function test_the_first_p24_submit_after_the_move_onto_the_market_is_the_go_live(): void
    {
        $p = $this->imported(['status' => 'active']);
        $this->statusChange($p, 'prospecting', 'active', now()->subDays(8));
        $this->p24Log($p, 'submit', 200, now()->subDays(6));    // the advert goes live
        $this->p24Log($p, 'submit', 200, now()->subDays(1));    // later refresh - must not move it
        $this->p24Log($p, 'submit', 500, now()->subDays(7));    // failed attempt - not a go-live

        $this->assertSame(6, DaysOnMarket::for($p));
    }

    public function test_released_takeover_counts_from_the_takeover_day(): void
    {
        $p = $this->imported(['listed_date' => now()->subDays(10)->toDateString()]);
        $p->forceFill(['imported_released_at' => now()->subDays(10)])->save();   // AT-422 takeover
        $this->assertSame(10, DaysOnMarket::for($p->fresh()));

        $this->p24Log($p, 'submit', 200, now()->subDays(8));
        $this->assertSame(8, DaysOnMarket::for($p->fresh()));
    }

    // ── re-published ─────────────────────────────────────────────────────

    public function test_republished_after_deactivation_counts_from_the_republish_not_the_refreshes(): void
    {
        $p = $this->native(['status' => 'active']);
        $this->p24Log($p, 'submit', 200, now()->subDays(60));             // first life
        $this->statusChange($p, 'active', 'withdrawn', now()->subDays(40));
        $this->statusChange($p, 'withdrawn', 'active', now()->subDays(20));
        $this->p24Log($p, 'submit', 200, now()->subDays(19));             // re-published
        $this->p24Log($p, 'submit', 200, now()->subDays(2));              // refresh

        $this->assertSame(19, DaysOnMarket::for($p));
    }

    // ── CoreX-created stock ──────────────────────────────────────────────

    public function test_native_listing_counts_from_its_real_go_live_not_the_capture_date(): void
    {
        $p = $this->native([
            'listed_date' => now()->subDays(30)->toDateString(),
            'pp_activated_at' => now()->subDays(25),
            'p24_activated_at' => now()->subDays(2),
        ]);
        $this->p24Log($p, 'submit', 200, now()->subDays(26));

        $this->assertSame(26, DaysOnMarket::for($p));
    }

    public function test_native_listing_with_no_go_live_proof_uses_the_listing_date_corex_stamped(): void
    {
        $this->assertSame(20, DaysOnMarket::for($this->native(['listed_date' => now()->subDays(20)->toDateString()])));
        $this->assertNull(DaysOnMarket::for($this->native(['listed_date' => null])));
    }

    public function test_rental_counts_from_its_go_live(): void
    {
        $p = $this->native(['listing_type' => 'rental', 'status' => 'to_let', 'listed_date' => now()->subDays(40)->toDateString(), 'pp_activated_at' => now()->subDays(12)]);

        $this->assertSame(12, DaysOnMarket::for($p));
    }

    // ── not on the market ────────────────────────────────────────────────

    public function test_off_market_properties_show_no_figure(): void
    {
        foreach (['prospecting', 'draft', 'sold', 'withdrawn', 'expired', 'archived', 'not_selling'] as $status) {
            $p = $this->native(['status' => $status, 'listed_date' => now()->subDays(30)->toDateString(), 'pp_activated_at' => now()->subDays(25)]);
            $this->assertNull(DaysOnMarket::for($p), "$status must show no days on market");
        }
    }

    public function test_deactivated_on_the_portal_shows_no_figure(): void
    {
        $p = $this->native(['status' => 'active', 'listed_date' => now()->subDays(30)->toDateString(), 'p24_syndication_status' => 'deactivated']);
        $this->assertNull(DaysOnMarket::for($p));

        $live = $this->native(['status' => 'active', 'listed_date' => now()->subDays(30)->toDateString(), 'p24_syndication_status' => 'deactivated', 'pp_syndication_status' => 'active']);
        $this->assertSame(30, DaysOnMarket::for($live), 'still live on the other portal');
    }

    // ── Other Agency Stock ───────────────────────────────────────────────

    public function test_other_agency_stock_uses_the_portals_own_listing_date(): void
    {
        $p = $this->native(['status' => Property::STATUS_OTHER_AGENCY_STOCK, 'listed_date' => now()->subDays(63)->toDateString()]);
        $this->assertSame(63, DaysOnMarket::for($p));

        $none = $this->native(['status' => Property::STATUS_OTHER_AGENCY_STOCK, 'listed_date' => null]);
        $this->assertNull(DaysOnMarket::for($none));
    }

    // ── batch form ───────────────────────────────────────────────────────

    public function test_batch_and_single_forms_agree(): void
    {
        $a = $this->imported(['status' => 'active']);
        $this->statusChange($a, 'prospecting', 'active', now()->subDays(77));
        $b = $this->native(['listed_date' => now()->subDays(9)->toDateString()]);
        $c = $this->imported([]);

        $this->assertSame([$a->id => 77, $b->id => 9, $c->id => null], app(DaysOnMarket::class)->forMany([$a, $b, $c]));
        $this->assertSame(77, DaysOnMarket::for($a));
        $this->assertSame(9, DaysOnMarket::for($b));
    }

    // ── every screen agrees ──────────────────────────────────────────────

    public function test_seller_link_intelligence_tile_and_property_header_show_the_same_figure(): void
    {
        $p = $this->imported(['status' => 'active', 'listed_date' => now()->subDays(105)->toDateString(), 'p24_activated_at' => now()->subDays(105)]);
        $this->statusChange($p, 'prospecting', 'active', now()->subDays(45));
        $link = $this->sellerLink($p);

        $seller = $this->get('/property/live/' . $link->token)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*45\s*<\/div>\s*<div[^>]*>\s*Days on market/u', $seller);
        $this->assertDoesNotMatchRegularExpression('/>\s*104\s*<\/div>\s*<div[^>]*>\s*Days on market/u', $seller);

        $agentHtml = $this->actingAs($this->agent)->get(route('corex.properties.show', $p))->assertOk()->getContent();
        $this->assertStringContainsString('45 days on market', $agentHtml);
        $this->assertMatchesRegularExpression('/>\s*45\s*<\/div>\s*<div[^>]*>\s*Days on Market/u', $agentHtml);
        $this->assertStringNotContainsString('104 days on market', $agentHtml);
    }

    public function test_no_proof_means_a_dash_for_agents_and_the_figure_is_hidden_from_the_seller(): void
    {
        $p = $this->imported(['status' => 'active', 'listed_date' => now()->subDays(105)->toDateString(), 'p24_activated_at' => now()->subDays(105)]);
        $link = $this->sellerLink($p);

        $seller = $this->get('/property/live/' . $link->token)->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/>\s*\d+\s*<\/div>\s*<div[^>]*>\s*Days on market/u', $seller);

        $agentHtml = $this->actingAs($this->agent)->get(route('corex.properties.show', $p))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*—\s*<\/div>\s*<div[^>]*>\s*Days on Market/u', $agentHtml);
        $this->assertStringNotContainsString('104 days on market', $agentHtml);
    }

    public function test_comparable_listings_use_the_same_calculation(): void
    {
        $shape = ['property_type' => 'apartment', 'beds' => 2, 'baths' => 1, 'price' => 1_000_000, 'suburb' => 'Uvongo'];
        $subject  = $this->native($shape + ['listed_date' => now()->subDays(10)->toDateString()]);
        $untouched = $this->imported($shape + ['status' => 'active', 'listed_date' => now()->subDays(105)->toDateString(), 'p24_activated_at' => now()->subDays(105)]);
        $moved     = $this->imported($shape + ['status' => 'active']);
        $this->statusChange($moved, 'prospecting', 'active', now()->subDays(33));
        $handSet   = $this->imported($shape + ['status' => 'active', 'listed_date' => now()->subDays(8)->toDateString()]);

        $rows = app(PropertyIntelligenceService::class)->getComparableListings($subject->id, 10)->keyBy('id');

        $this->assertTrue($rows->has($untouched->id) && $rows->has($moved->id) && $rows->has($handSet->id), 'the three comparables are found');
        $this->assertNull($rows[$untouched->id]['days_on_market'], 'an untouched import has no figure - not its import day');
        $this->assertSame(33, $rows[$moved->id]['days_on_market']);
        $this->assertSame(8, $rows[$handSet->id]['days_on_market']);
    }

    // ── a listing date set by hand to the portal's own date ──────────────

    public function test_a_real_listing_date_later_than_the_import_day_is_the_start_everywhere(): void
    {
        // Imported 25 Jun (105 days ago); the agent set the listing date to the portal's own date, 8 days ago.
        $p = $this->imported(['status' => 'active', 'listed_date' => now()->subDays(8)->toDateString(), 'p24_activated_at' => now()->subDays(105)]);
        $link = $this->sellerLink($p);

        $this->assertSame(8, DaysOnMarket::for($p));
        $this->assertSame(8, $this->tile($p));

        $seller = $this->get('/property/live/' . $link->token)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>\s*8\s*<\/div>\s*<div[^>]*>\s*Days on market/u', $seller);

        $agentHtml = $this->actingAs($this->agent)->get(route('corex.properties.show', $p))->assertOk()->getContent();
        $this->assertStringContainsString('8 days on market', $agentHtml);
        $this->assertMatchesRegularExpression('/>\s*8\s*<\/div>\s*<div[^>]*>\s*Days on Market/u', $agentHtml);
    }

    public function test_a_later_republish_beats_the_hand_set_listing_date_but_an_earlier_event_does_not(): void
    {
        $p = $this->imported(['status' => 'active', 'listed_date' => now()->subDays(20)->toDateString()]);
        $this->statusChange($p, 'prospecting', 'active', now()->subDays(50));      // earlier than the portal date
        $this->assertSame(20, DaysOnMarket::for($p), 'earlier on-market event does not override the real listing date');

        $this->p24Log($p, 'submit', 200, now()->subDays(3));                         // a later re-publish after the boundary
        $this->statusChange($p, 'active', 'withdrawn', now()->subDays(6));
        $this->statusChange($p, 'withdrawn', 'active', now()->subDays(4));
        $this->assertSame(3, DaysOnMarket::for($p), 'a later re-publish is the start of the current period');
    }

    public function test_a_hand_set_listing_date_on_a_property_not_on_the_market_still_shows_nothing(): void
    {
        $p = $this->imported(['status' => 'prospecting', 'listed_date' => now()->subDays(8)->toDateString()]);

        $this->assertNull(DaysOnMarket::for($p));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function tile(Property $p): ?int
    {
        return app(PropertyIntelligenceService::class)->getComplianceStatus($p->id)['days_on_market'];
    }

    /** Untouched imported stock: p24_imported_at set 105 days ago, not released. */
    private function imported(array $extra = []): Property
    {
        return $this->native(array_merge(['p24_imported_at' => now()->subDays(105)], $extra));
    }

    private function native(array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Unit ' . Str::random(4) . ' Glyndale Sands', 'suburb' => 'Uvongo',
            'property_type' => 'apartment', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1_000_000,
        ], $extra));
    }

    private function statusChange(Property $p, string $from, string $to, \DateTimeInterface $at): void
    {
        DB::table('property_audit_log')->insert([
            'property_id' => $p->id, 'agency_id' => $p->agency_id, 'branch_id' => $p->branch_id,
            'actor_type' => 'user', 'event_category' => 'property', 'event_type' => 'status_changed',
            'old_values' => json_encode(['status' => $from]), 'new_values' => json_encode(['status' => $to]),
            'human_summary' => "Status changed from $from to $to", 'created_at' => $at,
        ]);
    }

    private function p24Log(Property $p, string $action, int $code, \DateTimeInterface $at): void
    {
        DB::table('p24_syndication_logs')->insert([
            'property_id' => $p->id, 'action' => $action, 'status_code' => $code, 'created_at' => $at,
        ]);
    }

    private function sellerLink(Property $p): PropertySellerLink
    {
        $seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sam', 'last_name' => 'Seller' . Str::random(3),
            'phone' => '083' . random_int(1000000, 9999999), 'email' => 'sam-' . Str::random(5) . '@example.co.za',
        ]);

        return PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $p->id, 'contact_id' => $seller->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->agent->id, 'generated_at' => now(),
        ]);
    }
}
