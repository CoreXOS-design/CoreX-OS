<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Services\RoadToSold\Selections\BuyerSourcingSelection;
use App\Services\RoadToSold\Selections\LongOnMarketSelection;
use App\Services\RoadToSold\Selections\NewOnMarketSelection;
use App\Services\RoadToSold\Selections\OwnerKnownSelection;
use App\Services\RoadToSold\Selections\PriceDropSelection;
use App\Services\RoadToSold\SelectionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 piece 4 — what makes a property a candidate for selections 1, 2, 4, 5, 6 and 7
 * (3 is in HotPocketsTest; 8 and 9 in OwnStockSelectionsTest; the rules they all share are in MicPoolRulesTest).
 */
final class MicSelectionsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
    }

    public function test_the_registry_knows_all_nine_keys_in_the_default_order(): void
    {
        $this->assertSame(
            \App\Services\RoadToSold\Support\BuilderSettings::DEFAULT_SELECTION_ORDER,
            array_keys((new SelectionRegistry())->all())
        );
        foreach ((new SelectionRegistry())->all() as $key => $s) {
            $this->assertSame($key, $s->key());
            $this->assertNotSame('', $s->label());
        }
    }

    // ── 1 and 2: buyers ─────────────────────────────────────────────────

    public function test_my_buyers_sourcing_uses_only_my_active_buyers_strong_matches(): void
    {
        $myBuyer = $this->buyer($this->agent->id);
        $second  = $this->buyer($this->mate->id, 'warm', secondAgentId: $this->agent->id);
        $mateBuyer = $this->buyer($this->mate->id);
        $lost  = $this->buyer($this->agent->id, 'lost');
        $cold  = $this->buyer($this->agent->id, 'cold');

        $forMine   = $this->listing();
        $forBoth   = $this->listing();
        $forMate   = $this->listing();
        $forLost   = $this->listing();
        $weak      = $this->listing();
        $dismissed = $this->listing();
        $nobody    = $this->listing();

        $this->buyerMatch($forMine, $myBuyer->id);
        $this->buyerMatch($forBoth, $myBuyer->id);
        $this->buyerMatch($forBoth, $second->id, 'perfect', 95);
        $this->buyerMatch($forMate, $mateBuyer->id);
        $this->buyerMatch($forLost, $lost->id);
        $this->buyerMatch($forLost, $cold->id);
        $this->buyerMatch($weak, $myBuyer->id, 'approximate', 50);
        $this->buyerMatch($dismissed, $myBuyer->id, 'strong', 85, dismissed: true);

        $c = BuyerSourcingSelection::mine()->candidates($this->ctx(), 50);

        $this->assertSame([$forBoth, $forMine], $this->listingIds($c), 'two of my buyers beats one; mate\'s, lost, cold, weak, dismissed are out');
        $this->assertStringContainsString('2 of your buyers match: ', $c[0]->reason);
        $this->assertStringContainsString('1 of your buyers matches: ', $c[1]->reason);
    }

    public function test_agency_buyers_sourcing_uses_every_agents_active_buyers(): void
    {
        $mine = $this->buyer($this->agent->id);
        $mate = $this->buyer($this->mate->id);
        $noAgent = $this->buyer($this->agent->id, 'new');
        $noAgent->forceFill(['agent_id' => null])->save();

        $a = $this->listing();
        $b = $this->listing();
        $this->buyerMatch($a, $mine->id);
        $this->buyerMatch($a, $mate->id);
        $this->buyerMatch($b, $noAgent->id);

        $c = BuyerSourcingSelection::agency()->candidates($this->ctx(), 50);

        $this->assertSame([$a, $b], $this->listingIds($c));
        $this->assertStringContainsString('Agency has 2 buyers (', $c[0]->reason);
    }

    public function test_buyers_of_another_agency_never_count(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $theirBuyer = $this->buyer($this->agent->id, 'new', null, $other);
        $mine = $this->listing();
        $this->buyerMatch($mine, $theirBuyer->id, 'strong', 90, $other);

        $this->assertSame([], BuyerSourcingSelection::agency()->candidates($this->ctx(), 50));
    }

    public function test_buyer_sourcing_still_puts_addressed_properties_first(): void
    {
        $b = $this->buyer($this->agent->id);
        $none = $this->listing();
        $addr = $this->listing(['address' => '3 Sea Road']);
        $this->buyerMatch($none, $b->id, 'perfect', 99);   // better match, but no address
        $this->buyerMatch($addr, $b->id, 'strong', 80);

        $this->assertSame([$addr, $none], $this->listingIds(BuyerSourcingSelection::mine()->candidates($this->ctx(), 50)));
    }

    // ── 4: price drops ──────────────────────────────────────────────────

    private function history(int $listingId, int $old, int $new, string $when): void
    {
        DB::table('prospecting_price_history')->insert([
            'prospecting_listing_id' => $listingId, 'old_price' => $old, 'new_price' => $new,
            'changed_at' => $when, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_price_drops_picks_listings_whose_latest_change_in_the_window_was_a_drop(): void
    {
        $recent = $this->listing();
        $bigger = $this->listing();
        $rose   = $this->listing();
        $old    = $this->listing();
        $none   = $this->listing();

        $this->history($recent, 2_000_000, 1_900_000, '2026-10-12 09:00:00');    // -5%, newest
        $this->history($bigger, 2_000_000, 1_500_000, '2026-10-05 09:00:00');    // -25%, older
        $this->history($rose, 1_000_000, 900_000, '2026-10-01 09:00:00');         // dropped...
        $this->history($rose, 900_000, 1_100_000, '2026-10-10 09:00:00');         // ...then rose: latest is a rise
        $this->history($old, 2_000_000, 1_000_000, '2026-08-01 09:00:00');        // outside the 30-day window

        $c = (new PriceDropSelection())->candidates($this->ctx(), 50);

        $this->assertSame([$recent, $bigger], $this->listingIds($c), 'most recent drop first');
        $this->assertSame('Price drop R100k on 12 Oct', $c[0]->reason);
        $this->assertSame(5.0, (float) $c[0]->facts['pct']);
    }

    public function test_price_drop_window_is_the_agency_setting(): void
    {
        $l = $this->listing();
        $this->history($l, 2_000_000, 1_000_000, '2026-08-01 09:00:00');

        $this->assertSame([], (new PriceDropSelection())->candidates($this->ctx(), 50));
        $this->assertSame([$l], $this->listingIds((new PriceDropSelection())->candidates($this->ctx(settings: ['priceDropLookbackDays' => 90]), 50)));
    }

    public function test_a_drop_to_no_price_is_not_a_price_drop(): void
    {
        $l = $this->listing();
        DB::table('prospecting_price_history')->insert([
            'prospecting_listing_id' => $l, 'old_price' => 2_000_000, 'new_price' => null,
            'changed_at' => '2026-10-12 09:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], (new PriceDropSelection())->candidates($this->ctx(), 50));
    }

    // ── 5: new on market ────────────────────────────────────────────────

    public function test_new_on_market_uses_the_real_date_else_first_seen_and_says_which(): void
    {
        $new    = $this->listing(['listed_date' => '2026-10-10', 'listed_date_source' => 'portal', 'first_seen_at' => '2026-03-01 08:00:00']);   // captured long ago, listed 4 days ago
        $newer  = $this->listing(['listed_date' => '2026-10-13', 'listed_date_source' => 'portal']);
        $edge   = $this->listing(['listed_date' => '2026-09-30', 'listed_date_source' => 'portal']);   // exactly 14 days before the 14th
        $stale  = $this->listing(['listed_date' => '2026-09-29', 'listed_date_source' => 'portal']);
        $oldReal = $this->listing(['listed_date' => '2026-05-01', 'listed_date_source' => 'portal', 'first_seen_at' => '2026-10-12 06:00:00']); // captured yesterday but listed in May: NOT new
        $seenNew = $this->listing(['first_seen_at' => '2026-10-12 06:00:00']);                          // no real date: first seen yesterday
        $seenOld = $this->listing(['first_seen_at' => '2026-03-01 08:00:00']);                          // no real date: first seen in March

        $c = (new NewOnMarketSelection())->candidates($this->ctx(), 50);
        $ids = $this->listingIds($c);

        $this->assertSame([$newer, $seenNew, $new, $edge], $ids, 'newest first, real and first-seen dates ordered together');
        foreach ([$stale, $oldReal, $seenOld] as $never) {
            $this->assertNotContains($never, $ids);
        }
        $this->assertSame('New on market 1 day', $c[0]->reason);
        $this->assertSame('New on market 2 days (first seen by us)', $c[1]->reason);
        $this->assertSame('New on market 4 days', $c[2]->reason);
    }

    public function test_new_on_market_window_is_the_agency_setting(): void
    {
        $l = $this->listing(['listed_date' => '2026-09-20', 'listed_date_source' => 'portal']);

        $this->assertSame([], (new NewOnMarketSelection())->candidates($this->ctx(), 50));   // 24 days ago, window 14
        $this->assertSame([$l], $this->listingIds((new NewOnMarketSelection())->candidates($this->ctx(settings: ['newOnMarketDays' => 30]), 50)));
    }

    // ── 6: long on market ───────────────────────────────────────────────

    public function test_long_on_market_uses_the_real_date_else_first_sighting_longest_first(): void
    {
        $realOld   = $this->listing(['listed_date' => '2026-05-01', 'first_seen_at' => '2026-10-12 08:00:00']);   // captured yesterday, up 166 days
        $seenOld   = $this->listing(['first_seen_at' => '2026-06-01 08:00:00']);                                  // no real date, seen 135 days ago
        $realRecent = $this->listing(['listed_date' => '2026-10-01', 'first_seen_at' => '2026-03-01 08:00:00']);   // captured long ago but listed 13 days ago
        $young     = $this->listing(['first_seen_at' => '2026-09-01 08:00:00']);                                  // 43 days

        $c = (new LongOnMarketSelection())->candidates($this->ctx(), 50);

        $this->assertSame([$realOld, $seenOld], $this->listingIds($c));
        $this->assertSame('Long on market 166 days', $c[0]->reason);
        $this->assertStringContainsString('Long on market at least 135 days (first seen by us)', $c[1]->reason);
    }

    public function test_long_on_market_window_is_the_agency_setting(): void
    {
        $l = $this->listing(['first_seen_at' => '2026-09-01 08:00:00']);

        $this->assertSame([], (new LongOnMarketSelection())->candidates($this->ctx(), 50));
        $this->assertSame([$l], $this->listingIds((new LongOnMarketSelection())->candidates($this->ctx(settings: ['longOnMarketDays' => 30]), 50)));
    }

    // ── 7: owner already known ──────────────────────────────────────────

    private function contact(): int
    {
        return (int) \App\Models\Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Owner', 'last_name' => \Illuminate\Support\Str::random(5), 'phone' => '083' . random_int(1000000, 9999999),
        ])->id;
    }

    public function test_owner_known_recognises_all_four_paths_and_nothing_else(): void
    {
        // path 1 — owner_contact_id on the tracked property
        $p1 = $this->listing([], $this->tp(['owner_contact_id' => $this->contact()]));
        // path 2 — a tracked_property_owners row with a contact
        $tp2 = $this->tp();
        DB::table('tracked_property_owners')->insert(['tracked_property_id' => $tp2->id, 'contact_id' => $this->contact(), 'name' => 'O', 'created_at' => now(), 'updated_at' => now()]);
        $p2 = $this->listing([], $tp2);
        // path 3 — a seller on the CoreX property a pitch created
        $prop = $this->property($this->agent->id, ['status' => 'not_selling']);   // the agent's own pitch (another agent's would be theirs, never offered)
        DB::table('contact_property')->insert(['contact_id' => $this->contact(), 'property_id' => $prop, 'role' => 'seller', 'created_at' => now(), 'updated_at' => now()]);
        $p3 = $this->listing(['matched_property_id' => $prop]);
        // path 4 — an owner on the deed the agent linked
        $deedTp = $this->tp(['capture_kind' => 'deeds_capture']);
        DB::table('tracked_property_owners')->insert(['tracked_property_id' => $deedTp->id, 'contact_id' => $this->contact(), 'name' => 'D', 'created_at' => now(), 'updated_at' => now()]);
        $p4 = $this->listing(['linked_deed_tracked_property_id' => $deedTp->id]);
        // none
        $this->listing();
        // owner row WITHOUT a contact does not count
        $tpNo = $this->tp();
        DB::table('tracked_property_owners')->insert(['tracked_property_id' => $tpNo->id, 'contact_id' => null, 'name' => 'Unmatched', 'created_at' => now(), 'updated_at' => now()]);
        $this->listing([], $tpNo);

        $ids = $this->listingIds((new OwnerKnownSelection())->candidates($this->ctx(settings: ['ownerKnownQuantity' => 50]), 50));

        $this->assertEqualsCanonicalizing([$p1, $p2, $p3, $p4], $ids);
    }

    public function test_owner_known_is_capped_by_the_agency_quantity(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->listing([], $this->tp(['owner_contact_id' => $this->contact()]));
        }

        $this->assertCount(3, (new OwnerKnownSelection())->candidates($this->ctx(), 50), 'default quantity is 3');
        $this->assertCount(5, (new OwnerKnownSelection())->candidates($this->ctx(settings: ['ownerKnownQuantity' => 5]), 50));
        $this->assertCount(0, (new OwnerKnownSelection())->candidates($this->ctx(settings: ['ownerKnownQuantity' => 0]), 50));
        $this->assertCount(2, (new OwnerKnownSelection())->candidates($this->ctx(settings: ['ownerKnownQuantity' => 5]), 2), 'and by the caller\'s limit');
    }

    public function test_owner_known_shows_the_agents_own_held_property_again_but_still_not_a_blocked_one(): void
    {
        $tpHeldByMe = $this->tp(['owner_contact_id' => $this->contact()]);
        $tpBlocked  = $this->tp(['owner_contact_id' => $this->contact()]);
        $mine    = $this->listing([], $tpHeldByMe);
        $blocked = $this->listing([], $tpBlocked);

        $guard = new class([$tpBlocked->id], [$tpHeldByMe->id]) implements \App\Services\RoadToSold\Contracts\SuggestionGuard {
            public function __construct(private array $blocked, private array $own) {}
            public function blockedFor(\App\Models\User $u, array $ids, string $t, ?\Carbon\CarbonInterface $on = null): array { return array_values(array_intersect($ids, $this->blocked)); }
            public function ownHeld(\App\Models\User $u, array $ids, string $t): array { return array_values(array_intersect($ids, $this->own)); }
        };

        $this->assertSame([$mine], $this->listingIds((new OwnerKnownSelection())->candidates($this->ctx(guard: $guard), 50)));
    }
}
