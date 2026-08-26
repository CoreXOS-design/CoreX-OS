<?php

namespace Tests\Feature\Website;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Models\WebsiteStatBatch;
use App\Services\Website\WebsiteListingStatsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * POST /api/v1/website/listings/stats — inbound website engagement counters.
 *
 * The behaviours asserted here are the ones the WEBSITE depends on, not merely
 * the ones CoreX would like: it retries every non-2xx and advances its watermark
 * only on 2xx, so "a stale listing still returns 200" and "a replayed batch does
 * not double-count" are correctness, not politeness.
 *
 * Spec: .ai/specs/website-listing-stats.md §7
 */
class WebsiteListingStatsIngestTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    private function mintKey(array $scopes, ?Agency $agency = null): string
    {
        $minted = AgencyApiKey::mintSecret();
        AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'   => ($agency ?? $this->agency)->id,
            'name'        => 'Site',
            'key_prefix'  => $minted['prefix'],
            'secret_hash' => $minted['hash'],
            'scopes'      => $scopes,
        ]);

        return $minted['plaintext'];
    }

    private function listing(array $attributes = []): Property
    {
        $property = new Property();
        $property->forceFill(array_merge([
            'title'     => 'Beachfront Villa',
            'agent_id'  => $this->agent->id,
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'status'    => 'active',
        ], $attributes))->save();

        return $property;
    }

    private function payload(array $listings, string $batchId = 'batch-0001', string $site = 'home-finders-coastal'): array
    {
        return [
            'source'       => 'website',
            'site'         => $site,
            'batch_id'     => $batchId,
            'generated_at' => now()->toIso8601String(),
            'listings'     => $listings,
        ];
    }

    private function statCount(int $propertyId, string $date, string $metric): int
    {
        return (int) DB::table('listing_website_stats')
            ->where('property_id', $propertyId)
            ->where('stat_date', $date)
            ->where('metric', $metric)
            ->value('metric_count');
    }

    // ── Auth / scope ────────────────────────────────────────────────────────

    public function test_missing_api_key_is_401(): void
    {
        $this->postJson('/api/v1/website/listings/stats', $this->payload([]))
             ->assertStatus(401);
    }

    public function test_key_without_stats_write_scope_is_403(): void
    {
        $token = $this->mintKey([AgencyApiKey::SCOPE_LISTINGS_READ]);

        $this->withToken($token)
             ->postJson('/api/v1/website/listings/stats', $this->payload([['listing_id' => '1']]))
             ->assertStatus(403);
    }

    // ── Happy path ──────────────────────────────────────────────────────────

    public function test_batch_stores_a_daily_row_per_listing_date_and_metric(): void
    {
        $listing = $this->listing();
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);

        $today     = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $response = $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload([
            [
                'listing_id' => (string) $listing->id,
                'reference'  => 'HFC42',
                'days'       => [
                    ['date' => $yesterday, 'metrics' => ['impression' => 140, 'detail_view' => 12, 'unique_detail_view' => 9]],
                    ['date' => $today,     'metrics' => ['detail_view' => 4, 'phone_click' => 1]],
                ],
                'delta'  => ['detail_view' => 16, 'impression' => 140, 'phone_click' => 1, 'unique_detail_view' => 9],
                'totals' => ['detail_view' => 480, 'impression' => 5120, 'phone_click' => 23, 'unique_detail_view' => 310],
            ],
        ]))->assertOk();

        $response->assertJson([
            'batch_id' => 'batch-0001',
            'accepted' => 1,
            'skipped'  => [],
        ]);

        $this->assertSame(140, $this->statCount($listing->id, $yesterday, 'impression'));
        $this->assertSame(12, $this->statCount($listing->id, $yesterday, 'detail_view'));
        $this->assertSame(9, $this->statCount($listing->id, $yesterday, 'unique_detail_view'));
        $this->assertSame(4, $this->statCount($listing->id, $today, 'detail_view'));
        $this->assertSame(1, $this->statCount($listing->id, $today, 'phone_click'));

        // delta is by construction the SUM of days — consuming both would double
        // every count. days[] wins; delta is only a fallback.
        $this->assertSame(16, $this->statCount($listing->id, $yesterday, 'detail_view')
            + $this->statCount($listing->id, $today, 'detail_view'));

        // Lifetime totals are recorded as reported, for reconciliation.
        $this->assertDatabaseHas('listing_website_stat_totals', [
            'property_id'    => $listing->id,
            'metric'         => 'detail_view',
            'reported_total' => 480,
        ]);

        $batch = WebsiteStatBatch::withoutGlobalScopes()->where('batch_id', 'batch-0001')->first();
        $this->assertNotNull($batch);
        $this->assertSame($this->agency->id, (int) $batch->agency_id);
        $this->assertSame('home-finders-coastal', $batch->site);
        $this->assertSame(1, (int) $batch->accepted_count);
    }

    public function test_counts_increment_across_batches_for_the_same_day(): void
    {
        $listing = $this->listing();
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);
        $today   = now()->format('Y-m-d');

        $entry = fn () => [['listing_id' => (string) $listing->id, 'days' => [['date' => $today, 'metrics' => ['detail_view' => 5]]]]];

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload($entry(), 'batch-a'))->assertOk();
        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload($entry(), 'batch-b'))->assertOk();

        // 5 + 5, not 5. Assignment instead of increment would silently lose an hour.
        $this->assertSame(10, $this->statCount($listing->id, $today, 'detail_view'));
    }

    public function test_replaying_a_batch_id_does_not_double_count(): void
    {
        $listing = $this->listing();
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);
        $today   = now()->format('Y-m-d');

        $payload = $this->payload([
            ['listing_id' => (string) $listing->id, 'days' => [['date' => $today, 'metrics' => ['detail_view' => 7]]]],
        ], 'batch-replay');

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $payload)->assertOk();
        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $payload)
             ->assertOk()
             ->assertJson(['batch_id' => 'batch-replay', 'accepted' => 1, 'skipped' => []]);

        $this->assertSame(7, $this->statCount($listing->id, $today, 'detail_view'));
        $this->assertSame(1, WebsiteStatBatch::withoutGlobalScopes()->where('batch_id', 'batch-replay')->count());
    }

    // ── Absorption: the batch must survive bad entries ──────────────────────

    public function test_unknown_listing_is_skipped_and_the_rest_of_the_batch_still_applies(): void
    {
        $listing = $this->listing();
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);
        $today   = now()->format('Y-m-d');

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload([
            ['listing_id' => '9182', 'days' => [['date' => $today, 'metrics' => ['detail_view' => 99]]]],
            ['listing_id' => (string) $listing->id, 'days' => [['date' => $today, 'metrics' => ['detail_view' => 3]]]],
        ], 'batch-skip'))
            ->assertOk()
            ->assertJson(['accepted' => 1, 'skipped' => ['9182']]);

        $this->assertSame(3, $this->statCount($listing->id, $today, 'detail_view'));
    }

    public function test_a_listing_in_another_agency_is_skipped_not_credited(): void
    {
        $other        = Agency::create(['name' => 'Rival', 'slug' => 'rival', 'website_enabled' => true]);
        $otherBranch  = Branch::forceCreate(['agency_id' => $other->id, 'name' => 'Main']);
        $otherAgent   = User::factory()->create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'agent',
        ]);
        $otherListing = new Property();
        $otherListing->forceFill([
            'title' => 'Not ours', 'agent_id' => $otherAgent->id, 'agency_id' => $other->id,
            'branch_id' => $otherBranch->id, 'status' => 'active',
        ])->save();

        $token = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);
        $today = now()->format('Y-m-d');

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload([
            ['listing_id' => (string) $otherListing->id, 'days' => [['date' => $today, 'metrics' => ['detail_view' => 50]]]],
        ], 'batch-tenant'))
            ->assertOk()
            ->assertJson(['accepted' => 0, 'skipped' => [(string) $otherListing->id]]);

        $this->assertSame(0, $this->statCount($otherListing->id, $today, 'detail_view'));
    }

    public function test_an_unknown_metric_key_is_stored_not_rejected(): void
    {
        $listing = $this->listing();
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);
        $today   = now()->format('Y-m-d');

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload([
            ['listing_id' => (string) $listing->id, 'days' => [['date' => $today, 'metrics' => [
                'detail_view'    => 2,
                'video_play'     => 6,   // a metric the website added without a CoreX deploy
                'bad metric key' => 4,   // unstorable — dropped alone, never fails the batch
            ]]]],
        ], 'batch-open'))->assertOk()->assertJson(['accepted' => 1]);

        $this->assertSame(6, $this->statCount($listing->id, $today, 'video_play'));
        $this->assertSame(2, $this->statCount($listing->id, $today, 'detail_view'));
        $this->assertSame(0, DB::table('listing_website_stats')->where('metric', 'bad metric key')->count());
    }

    public function test_delta_is_used_when_an_entry_carries_no_days(): void
    {
        $listing = $this->listing();
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload([
            ['listing_id' => (string) $listing->id, 'delta' => ['detail_view' => 11, 'enquiry' => 1]],
        ], 'batch-delta'))->assertOk()->assertJson(['accepted' => 1]);

        $today = now()->format('Y-m-d');
        $this->assertSame(11, $this->statCount($listing->id, $today, 'detail_view'));
        $this->assertSame(1, $this->statCount($listing->id, $today, 'enquiry'));
    }

    public function test_reference_resolves_a_listing_when_listing_id_is_unknown(): void
    {
        $listing = $this->listing(['external_id' => 'HFC42']);
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);
        $today   = now()->format('Y-m-d');

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload([
            ['listing_id' => '999999', 'reference' => 'HFC42', 'days' => [['date' => $today, 'metrics' => ['detail_view' => 8]]]],
        ], 'batch-ref'))->assertOk()->assertJson(['accepted' => 1, 'skipped' => []]);

        $this->assertSame(8, $this->statCount($listing->id, $today, 'detail_view'));
    }

    public function test_structurally_malformed_body_is_422(): void
    {
        $token = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);

        // No site, no batch_id, no listings — nothing to be idempotent about.
        $this->withToken($token)
             ->postJson('/api/v1/website/listings/stats', ['source' => 'website'])
             ->assertStatus(422);
    }

    // ── Read side (what the UI renders) ─────────────────────────────────────

    public function test_report_service_returns_the_panel_figures(): void
    {
        $listing = $this->listing();
        $token   = $this->mintKey([AgencyApiKey::SCOPE_STATS_WRITE]);
        $today   = now()->format('Y-m-d');

        $this->withToken($token)->postJson('/api/v1/website/listings/stats', $this->payload([
            [
                'listing_id' => (string) $listing->id,
                'days'       => [['date' => $today, 'metrics' => [
                    'detail_view'        => 100,
                    'unique_detail_view' => 80,
                    'impression'         => 900,
                    'enquiry'            => 5,
                    'phone_click'        => 3,
                    'email_click'        => 2,
                    'share_click'        => 1,
                ]]],
                'totals'     => ['detail_view' => 1000],
            ],
        ], 'batch-report'))->assertOk();

        $report = app(WebsiteListingStatsReportService::class)
            ->performanceFor($listing->id, $this->agency->id);

        $this->assertTrue($report['has_data']);
        $this->assertSame(100, $report['views']);
        $this->assertSame(80, $report['unique_views']);
        $this->assertSame(900, $report['impressions']);
        $this->assertSame(5, $report['enquiries']);
        $this->assertSame(6, $report['contact_clicks']);        // phone + email + share
        $this->assertSame(5.0, $report['conversion']);          // 5 enquiries / 100 views
        $this->assertSame(1000, $report['lifetime']['detail_view']);  // the website's own figure
        $this->assertCount(WebsiteListingStatsReportService::WINDOW_DAYS, $report['series']);
        $this->assertNotNull($report['last_received_at']);

        $this->assertTrue(app(WebsiteListingStatsReportService::class)->agencyHasStats($this->agency->id));
        $this->assertSame(
            [$listing->id => 100],
            app(WebsiteListingStatsReportService::class)->viewsForProperties([$listing->id], $this->agency->id)
        );

        $totals = app(WebsiteListingStatsReportService::class)->agentTotals($this->agent->id, $this->agency->id);
        $this->assertSame(100, $totals['views']);
        $this->assertSame(5, $totals['enquiries']);
        $this->assertSame($listing->id, $totals['top'][0]['id']);
    }

    public function test_conversion_is_null_rather_than_zero_when_nothing_has_been_viewed(): void
    {
        $listing = $this->listing();

        $report = app(WebsiteListingStatsReportService::class)
            ->performanceFor($listing->id, $this->agency->id);

        $this->assertFalse($report['has_data']);
        $this->assertNull($report['conversion']);
    }
}
