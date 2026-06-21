<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Http\Controllers\CoreX\MarketIntelligenceController;
use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-75 — MIC canonical scoring + threshold-anchored KPI.
 *
 * The prospecting recompute now scores via the CANONICAL engine (only the
 * criteria the buyer specified; price drift decays, deal-breakers hard-exclude)
 * instead of Engine B's no-signal ~85-on-everything. The MIC "Buyer matched"
 * KPI counts DISTINCT countable buyers (and listings) at/above the agency
 * threshold — reconciling with the pipeline truth.
 */
final class MicCanonicalScoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
    }

    public function test_canonical_scoring_excludes_out_of_band_listing(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        // Specific wishlist: 700k–900k, 2 beds, House.
        $this->match($agencyId, $buyer->id, [
            'price_min' => 700_000, 'price_max' => 900_000, 'beds_min' => 2, 'property_types' => ['House'],
        ]);

        $inBand  = $this->listing($agencyId, $agent->id, ['price' => 800_000,   'bedrooms' => 2, 'property_type' => 'House']);
        $outBand = $this->listing($agencyId, $agent->id, ['price' => 3_000_000, 'bedrooms' => 2, 'property_type' => 'House']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $inScore = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $inBand)->value('score');
        $this->assertNotNull($inScore, 'in-band listing is matched');
        $this->assertGreaterThanOrEqual(75, (int) $inScore, 'in-band scores strong');

        $outRow = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $outBand)->exists();
        $this->assertFalse($outRow, 'a wildly out-of-band listing is NOT cached (no more 85-on-everything)');
    }

    public function test_price_band_tolerance_and_drift_decay(): void
    {
        [$agencyId, $agent] = $this->fixture(); // default mic_price_band_pct = 10
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, ['price_min' => 700_000, 'price_max' => 900_000, 'beds_min' => 2, 'property_types' => ['House']]);

        $withinBand = $this->listing($agencyId, $agent->id, ['price' => 950_000,   'bedrooms' => 2, 'property_type' => 'House']); // +5.5% ≤ 10% band → full
        $drifted    = $this->listing($agencyId, $agent->id, ['price' => 1_200_000, 'bedrooms' => 2, 'property_type' => 'House']); // beyond band → decays

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);

        $within = (int) DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $withinBand)->value('score');
        $drift  = DB::table('prospecting_buyer_matches')->where('contact_id', $buyer->id)->where('prospecting_listing_id', $drifted)->value('score');

        $this->assertSame(100, $within, 'within the ±10% tolerance → full price score');
        $this->assertNotNull($drift, 'drifted listing still shows (no hard cutoff)');
        $this->assertLessThan(75, (int) $drift, 'drift decays it below the strong threshold (sorts to bottom)');
    }

    public function test_kpi_counts_distinct_countable_buyers_at_threshold(): void
    {
        [$agencyId, $agent] = $this->fixture();
        // Two countable buyers who match an in-band listing strongly.
        foreach (['Ann', 'Bea'] as $name) {
            $b = $this->buyer($agencyId, $agent->id, $name);
            $this->match($agencyId, $b->id, ['price_min' => 700_000, 'price_max' => 900_000, 'beds_min' => 2, 'property_types' => ['House']]);
        }
        $this->listing($agencyId, $agent->id, ['price' => 800_000, 'bedrooms' => 2, 'property_type' => 'House']);
        $this->listing($agencyId, $agent->id, ['price' => 820_000, 'bedrooms' => 2, 'property_type' => 'House']);

        // Recompute the whole pool.
        DB::table('prospecting_listings')->where('agency_id', $agencyId)->pluck('id')->each(
            fn ($lid) => app(PropertyMatchScoringService::class)->recomputeProspectingMatches((int) $lid)
        );

        $kpis = $this->callSnapshotKpis($agencyId);

        $this->assertSame(2, $kpis['buyers_matched'], 'two distinct countable buyers matched at threshold');
        $this->assertSame(2, $kpis['properties_matched'], 'two canvass listings matched at threshold');
        $this->assertSame(75, $kpis['match_threshold']);
    }

    public function test_kpi_excludes_buyers_below_threshold(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $buyer = $this->buyer($agencyId, $agent->id);
        $this->match($agencyId, $buyer->id, ['price_min' => 700_000, 'price_max' => 900_000, 'beds_min' => 2, 'property_types' => ['House']]);
        // Only a drifted listing (scores ~69, below the 75 threshold).
        $this->listing($agencyId, $agent->id, ['price' => 1_200_000, 'bedrooms' => 2, 'property_type' => 'House']);

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($buyer->id);
        $kpis = $this->callSnapshotKpis($agencyId);

        $this->assertSame(0, $kpis['buyers_matched'], 'a buyer whose best match is below threshold is not counted');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function callSnapshotKpis(int $agencyId): array
    {
        $ctrl = app(MarketIntelligenceController::class);
        $m = new \ReflectionMethod($ctrl, 'computeSnapshotKpis');
        $m->setAccessible(true);
        return $m->invoke($ctrl, $agencyId, false);
    }

    /** @return array{0:int,1:User} */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        return [$agencyId, $agent];
    }

    private function buyer(int $agencyId, int $agentId, string $first = 'Bea'): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $agentId,
            'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => $first, 'last_name' => 'Buyer ' . Str::random(3),
            'phone' => '082' . random_int(1000000, 9999999), 'email' => strtolower($first) . '-' . Str::random(5) . '@e.co.za',
        ]);
    }

    private function match(int $agencyId, int $contactId, array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ], $extra));
    }

    private function listing(int $agencyId, int $agentId, array $extra): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agencyId, 'captured_by_user_id' => $agentId,
            'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8),
            'portal_url' => 'https://example.com/' . Str::random(6),
            'address' => Str::random(6) . ' Test Road', 'suburb' => 'Uvongo',
            'price' => 800_000, 'bedrooms' => 2, 'property_type' => 'House',
            'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }
}
