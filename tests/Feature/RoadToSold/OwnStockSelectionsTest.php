<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Selections\MyExpiredStockSelection;
use App\Services\RoadToSold\Selections\MyStockNotSellingSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 piece 4 — selections 8 and 9, the agent's OWN stock. The rule under test: an agent NEVER gets
 * another agent's stock, whatever the settings, areas or guard say.
 */
final class OwnStockSelectionsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
    }

    private function propertyIds(array $cands): array
    {
        return array_map(fn ($c) => $c->propertyId, $cands);
    }

    // ── 8: my expired stock ─────────────────────────────────────────────

    public function test_expired_stock_is_only_my_own_expired_sale_listings_latest_first_addressed_first(): void
    {
        $recent  = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-01']);
        $older   = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-05-01']);
        $noDate  = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => null]);
        $noAddr  = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-10', 'address' => '']);
        $mates   = $this->property($this->mate->id, ['status' => 'expired', 'expiry_date' => '2026-10-12']);
        $active  = $this->property($this->agent->id, ['status' => 'active']);
        $rental  = $this->property($this->agent->id, ['status' => 'expired', 'listing_type' => 'rental', 'expiry_date' => '2026-10-02']);
        $deleted = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-03', 'deleted_at' => now()]);

        $c = (new MyExpiredStockSelection())->candidates($this->ctx(), 50);

        $this->assertSame([$recent, $older, $noDate, $noAddr], $this->propertyIds($c));
        $this->assertSame('Your mandate expired on 1 Oct 2026 — pitch again', $c[0]->reason);
        $this->assertSame('Your mandate has expired — pitch again', $c[2]->reason);
        $this->assertFalse($c[3]->hasAddress);
        foreach ([$mates, $active, $rental, $deleted] as $never) {
            $this->assertNotContains($never, $this->propertyIds($c));
        }
    }

    public function test_expired_stock_carries_the_tracked_property_when_there_is_one(): void
    {
        $p = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-01']);
        $q = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-09-01']);
        $tp = $this->tp(['promoted_to_property_id' => $p, 'status' => 'promoted']);

        $c = (new MyExpiredStockSelection())->candidates($this->ctx(), 50);

        $this->assertSame($tp->id, $c[0]->trackedPropertyId);
        $this->assertSame($p, $c[0]->propertyId);
        $this->assertNotNull($c[1]->trackedPropertyId, 'never tracked: it is given a tracked property on the way in, so holds can see it');
        $this->assertSame($q, $c[1]->propertyId);
        $this->assertNotSame($tp->id, $c[1]->trackedPropertyId);
        $this->assertNull($c[0]->prospectingListingId);
    }

    public function test_expired_stock_honours_the_guard_exclusions_and_the_limit(): void
    {
        $a = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-05']);
        $b = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-04']);
        $c3 = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-03']);
        $d = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-02']);
        $tpB = $this->tp(['promoted_to_property_id' => $b]);

        $guard = new class([$tpB->id]) implements SuggestionGuard {
            public function __construct(private array $blocked) {}
            public function blockedFor(\App\Models\User $u, array $ids, string $t, ?\Carbon\CarbonInterface $on = null): array { return array_values(array_intersect($ids, $this->blocked)); }
            public function ownHeld(\App\Models\User $u, array $ids, string $t): array { return []; }
        };

        $got = (new MyExpiredStockSelection())->candidates($this->ctx(guard: $guard, excludeProperties: [$c3]), 50);
        $this->assertSame([$a, $d], $this->propertyIds($got), 'blocked tracked property and already-listed property are dropped');

        $this->assertCount(1, (new MyExpiredStockSelection())->candidates($this->ctx(), 1));
        $this->assertSame([], (new MyExpiredStockSelection())->candidates($this->ctx(), 0));
    }

    public function test_each_agent_gets_only_their_own_expired_stock(): void
    {
        $mine = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-01']);
        $theirs = $this->property($this->mate->id, ['status' => 'expired', 'expiry_date' => '2026-10-02']);

        $this->assertSame([$mine], $this->propertyIds((new MyExpiredStockSelection())->candidates($this->ctx($this->agent), 50)));
        $this->assertSame([$theirs], $this->propertyIds((new MyExpiredStockSelection())->candidates($this->ctx($this->mate), 50)));
    }

    // ── 9: my stock that is not selling ─────────────────────────────────

    private function metrics(int $propertyId, int $views, int $leads, string $date = '2026-10-05'): void
    {
        DB::table('property_portal_metrics')->insert([
            'agency_id' => $this->agency->id, 'property_id' => $propertyId, 'portal' => 'p24', 'metric_date' => $date,
            'portal_listing_number' => (string) $propertyId, 'view_count' => $views, 'total_leads' => $leads, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** An on-market property with a REAL listed date this many days ago. */
    private function stock(int $agentId, int $daysOn, array $x = []): int
    {
        return $this->property($agentId, array_merge([
            'status' => 'active', 'listed_date' => now()->subDays($daysOn)->toDateString(),
            'created_at' => now()->subDays($daysOn + 5), 'updated_at' => now(),
        ], $x));
    }

    public function test_stuck_stock_is_my_long_on_market_low_views_low_enquiries_listings_longest_first(): void
    {
        $stuck1 = $this->stock($this->agent->id, 120);
        $stuck2 = $this->stock($this->agent->id, 70);
        $fresh  = $this->stock($this->agent->id, 10);        // not on long enough
        $busy   = $this->stock($this->agent->id, 90);        // too many views
        $asked  = $this->stock($this->agent->id, 90);        // too many enquiries
        $mates  = $this->stock($this->mate->id, 200);        // someone else's, perfect candidate otherwise
        foreach ([$stuck1, $stuck2, $fresh, $mates] as $id) { $this->metrics($id, 12, 1); }
        $this->metrics($busy, 400, 0);
        $this->metrics($asked, 5, 6);

        $c = (new MyStockNotSellingSelection())->candidates($this->ctx(), 50);

        $this->assertSame([$stuck1, $stuck2], $this->propertyIds($c));
        $this->assertSame('120 days on the market, only 12 views and 1 enquiry in the last 30 days', $c[0]->reason);
        $this->assertNotContains($mates, $this->propertyIds($c));
    }

    public function test_stuck_stock_thresholds_and_window_are_the_agency_settings(): void
    {
        $p = $this->stock($this->agent->id, 45);
        $this->metrics($p, 50, 3);

        $this->assertSame([], (new MyStockNotSellingSelection())->candidates($this->ctx(), 50));
        $this->assertSame([$p], $this->propertyIds((new MyStockNotSellingSelection())->candidates($this->ctx(settings: [
            'stuckStockMinDaysOnMarket' => 30, 'stuckStockMaxViews' => 60, 'stuckStockMaxLeads' => 3,
        ]), 50)));
    }

    public function test_views_outside_the_lookback_window_are_not_counted_and_no_numbers_means_zero_views(): void
    {
        $quietNow = $this->stock($this->agent->id, 100);
        $this->metrics($quietNow, 5, 0, '2026-10-05');
        $this->metrics($quietNow, 9000, 40, '2026-06-01');     // a busy spell long ago: outside the 30-day window
        $noStats = $this->stock($this->agent->id, 100);        // no portal statistics at all

        $c = (new MyStockNotSellingSelection())->candidates($this->ctx(), 50);

        // no portal statistics at all = zero views and zero leads, so it qualifies too (longest on the market first: both 100 days)
        $this->assertEqualsCanonicalizing([$quietNow, $noStats], $this->propertyIds($c));
    }

    public function test_enquiries_received_in_corex_count_even_when_the_portal_stat_is_lower(): void
    {
        $p = $this->stock($this->agent->id, 100);
        $this->metrics($p, 5, 0);
        foreach ([1, 2, 3] as $_) {
            DB::table('portal_leads')->insert([
                'agency_id' => $this->agency->id, 'portal' => 'p24', 'lead_type' => 'Email', 'listing_id' => $p,
                'name' => 'E', 'received_at' => '2026-10-08 10:00:00', 'lead_source_raw' => '{}', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame([], (new MyStockNotSellingSelection())->candidates($this->ctx(), 50), '3 received enquiries > the default max of 2');
    }

    public function test_stuck_stock_never_leaks_between_agents_and_only_covers_on_market_sale_listings(): void
    {
        $mine = $this->stock($this->agent->id, 100);
        $theirs = $this->stock($this->mate->id, 100);
        $expired = $this->stock($this->agent->id, 100, ['status' => 'expired']);
        $rental = $this->stock($this->agent->id, 100, ['listing_type' => 'rental']);
        foreach ([$mine, $theirs, $expired, $rental] as $id) { $this->metrics($id, 3, 0); }

        $this->assertSame([$mine], $this->propertyIds((new MyStockNotSellingSelection())->candidates($this->ctx($this->agent), 50)));
        $this->assertSame([$theirs], $this->propertyIds((new MyStockNotSellingSelection())->candidates($this->ctx($this->mate), 50)));
    }

    public function test_an_agent_with_no_stock_gets_nothing_from_8_or_9(): void
    {
        $loner = $this->makeAgent();
        $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-01']);

        $this->assertSame([], (new MyExpiredStockSelection())->candidates($this->ctx($loner), 50));
        $this->assertSame([], (new MyStockNotSellingSelection())->candidates($this->ctx($loner), 50));
    }
}
