<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\PropertyPortalMetric;
use App\Models\PropertySellerLink;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Intelligence tab fixes (QA1, 2026-10-07):
 *   A — range buttons (7 days / Month to date / 30D / 90D / 6M) and the chart that
 *       actually redraws; B — the same buttons + chart on the seller live link;
 *   C — each buyer-interest signal names the buyer's primary agent, agent-facing only.
 *
 * The slicing rule itself is unit-tested in tests/js/engagement-ranges.test.mjs.
 * Spec: .ai/specs/seller-live-link.md ("Range buttons"), .ai/specs/portal-metrics.md.
 */
final class IntelligenceTabRangesAndBuyerAgentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $listingAgent;
    private User $buyerAgent;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        Bus::fake();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->listingAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin',
            'name' => 'Linda Listing',
        ]);
        $this->buyerAgent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
            'name' => 'Brian Buyeragent',
        ]);
    }

    // ── A / B — the payload both surfaces read ────────────────────────────

    public function test_engagement_series_carries_server_month_start_for_month_to_date(): void
    {
        $property = $this->makeProperty();

        $payload = app(PropertyIntelligenceService::class)->getPortalEngagementSeries($property->id);

        $this->assertSame(now()->startOfMonth()->format('Y-m-d'), $payload['month_start']);
        $this->assertCount(180, $payload['series'], 'the full 180-day series is still what both pages slice');
    }

    // ── A — the agent Intelligence tab ────────────────────────────────────

    public function test_agent_tab_chart_uses_shared_ranges_and_keeps_chart_out_of_alpine_reactivity(): void
    {
        $property = $this->makeProperty();
        $this->metric($property, now()->subDays(2)->format('Y-m-d'), 40);

        $html = $this->actingAs($this->listingAgent)
            ->get(route('corex.properties.show', $property))
            ->assertOk()
            ->getContent();

        // Buttons come from the one shared list; slicing from the one shared rule.
        $this->assertStringContainsString('portal-engagement-range-toggle', $html);
        $this->assertStringContainsString('NC().engagementRanges', $html);
        $this->assertStringContainsString('NC().engagementWindow(', $html);
        $this->assertMatchesRegularExpression(
            '/monthStart: \W?' . preg_quote(now()->startOfMonth()->format('Y-m-d'), '/') . '/',
            $html
        );

        // The bug: Chart.js instance stored on Alpine's reactive `this` → update() silently broke.
        $this->assertStringNotContainsString('this.chart', $html);
        $this->assertStringContainsString('let chart = null;', $html);
    }

    // ── C — buyer's primary agent on the agent-facing tab only ────────────

    public function test_buyer_signal_carries_the_buyers_primary_agent_name(): void
    {
        [$property, $buyer] = $this->propertyWithMatchingBuyer($this->buyerAgent->id);

        $row = app(PropertyIntelligenceService::class)->getBuyerInterestSignals($property->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($buyer->id, $row['id']);
        $this->assertSame('Brian Buyeragent', $row['agent_name'], "the buyer's agent — not the listing agent");
    }

    public function test_buyer_without_an_agent_has_null_agent_name_and_shows_unassigned(): void
    {
        [$property] = $this->propertyWithMatchingBuyer(null);

        $row = app(PropertyIntelligenceService::class)->getBuyerInterestSignals($property->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row['agent_name']);

        $html = $this->actingAs($this->listingAgent)->get(route('corex.properties.show', $property))->assertOk()->getContent();
        $this->assertStringContainsString('Agent: Unassigned', $html);
    }

    public function test_agent_tab_shows_the_agent_next_to_each_buyer(): void
    {
        [$property] = $this->propertyWithMatchingBuyer($this->buyerAgent->id);

        $html = $this->actingAs($this->listingAgent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertStringContainsString('data-buyer-agent', $html);
        $this->assertStringContainsString('Agent: Brian Buyeragent', $html);
    }

    // ── B + C — the seller live link: same chart, no buyer / agent detail ─

    public function test_seller_link_has_the_same_range_wiring_and_exposes_no_buyer_or_buyer_agent(): void
    {
        [$property] = $this->propertyWithMatchingBuyer($this->buyerAgent->id);
        $this->metric($property, now()->subDays(3)->format('Y-m-d'), 25);
        $link = $this->sellerLink($property);

        $html = $this->get('/property/live/' . $link->token)->assertOk()->getContent();

        // B — same server month start, buttons drawn from the shared list, range kept over the 60s reload.
        $this->assertStringContainsString('id="engagement-range-toggle"', $html);
        $this->assertStringContainsString('data-month-start="' . now()->startOfMonth()->format('Y-m-d') . '"', $html);
        $this->assertStringContainsString('NexusCharts.engagementRanges', $html);
        $this->assertStringContainsString('NexusCharts.engagementWindow(', $html);
        $this->assertStringContainsString('sessionStorage', $html);
        // The old hard-coded three buttons are gone (one definition, in engagement-ranges.js).
        $this->assertStringNotContainsString('data-range="90"', $html);

        // C — nothing about the buyer or the buyer's agent reaches the seller (counts only).
        $this->assertStringNotContainsString('Brian Buyeragent', $html);
        $this->assertStringNotContainsString('Bea Buyer', $html);
        $this->assertStringNotContainsString('data-buyer-agent', $html);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeProperty(array $extra = []): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->listingAgent->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active',
            'price' => 1_800_000, 'beds' => 3, 'published_at' => now(),
        ], $extra));
    }

    private function metric(Property $property, string $date, int $views): void
    {
        PropertyPortalMetric::withoutGlobalScopes()->create([
            'agency_id' => $property->agency_id, 'property_id' => $property->id,
            'portal' => PropertyPortalMetric::PORTAL_P24, 'portal_listing_number' => '12345678',
            'metric_date' => $date, 'view_count' => $views, 'alert_count' => 0, 'total_leads' => 0,
        ]);
    }

    /** @return array{0:Property,1:Contact} a listing + a buyer whose wishlist fully fits it */
    private function propertyWithMatchingBuyer(?int $buyerAgentId): array
    {
        $suburbId = $this->seedP24Suburb();
        $property = $this->makeProperty(['p24_suburb_id' => $suburbId]);

        $buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $this->listingAgent->id, 'agent_id' => $buyerAgentId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'Bea', 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999),
            'email' => 'bea-' . Str::random(5) . '@example.co.za',
        ]);
        // ContactObserver defaults a blank agent_id to the capturing user — clear it again
        // when the test wants a genuinely unassigned buyer.
        if ($buyerAgentId === null) {
            DB::table('contacts')->where('id', $buyer->id)->update(['agent_id' => null]);
        }
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $buyer->id,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_min' => 1_500_000, 'price_max' => 2_000_000, 'beds_min' => 3,
            'p24_suburb_ids' => [$suburbId],
        ]);

        return [$property, $buyer];
    }

    private function sellerLink(Property $property): PropertySellerLink
    {
        $seller = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sam', 'last_name' => 'Seller' . Str::random(3),
            'phone' => '083' . random_int(1000000, 9999999),
            'email' => 'sam-' . Str::random(5) . '@example.co.za',
        ]);

        return PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $property->id, 'contact_id' => $seller->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $this->listingAgent->id,
            'generated_at' => now(),
        ]);
    }

    private function seedP24Suburb(): int
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => random_int(1, 999999), 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_province_id' => $provinceId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('p24_suburbs')->insertGetId([
            'p24_id' => random_int(1, 999999), 'p24_city_id' => $cityId, 'name' => 'Uvongo',
            'slug' => 'uvongo-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
