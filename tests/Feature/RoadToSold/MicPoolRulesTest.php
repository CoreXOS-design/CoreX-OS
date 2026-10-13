<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Selections\LongOnMarketSelection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 piece 4 — the rules EVERY listing-based selection shares (address first, areas first, the lock,
 * the "never suggest" exclusions, twin collapse, the suggestion guard). "Long on market" is the vehicle
 * because it has no joins of its own: every row here is simply old enough to qualify.
 */
final class MicPoolRulesTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const OLD = ['first_seen_at' => '2026-03-01 08:00:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
    }

    private function pick(array $ctxArgs = [], int $limit = 50): array
    {
        return (new LongOnMarketSelection())->candidates($this->ctx(...$ctxArgs), $limit);
    }

    // ── address first / areas first / lock ──────────────────────────────

    public function test_properties_with_an_address_come_before_those_without(): void
    {
        $noAddr  = $this->listing(self::OLD);
        $addr    = $this->listing(self::OLD + ['address' => '5 Beach Road']);
        $tpAddr  = $this->listing(self::OLD, $this->tp(['street_name' => 'Hill Lane']));   // street only on the tracked property

        $c = $this->pick();

        $this->assertSame($noAddr, end($c)->prospectingListingId, 'the address-less one is last');
        $this->assertEqualsCanonicalizing([$addr, $tpAddr], array_slice($this->listingIds($c), 0, 2));
        $this->assertTrue($c[0]->hasAddress);
        $this->assertFalse(end($c)->hasAddress);
    }

    public function test_an_address_corrected_on_the_tracked_property_counts_as_having_one(): void
    {
        $tp = $this->tp(['street_name' => null]);
        $fixed = $this->listing(self::OLD, $tp);
        $plain = $this->listing(self::OLD);

        $this->assertSame([false, false], array_map(fn ($x) => $x->hasAddress, $this->pick()), 'no address anywhere yet');

        $tp->forceFill(['street_name' => 'Corrected Avenue'])->save();   // what editAddress's observer does

        $after = $this->pick();
        $this->assertSame($fixed, $after[0]->prospectingListingId);
        $this->assertTrue($after[0]->hasAddress);
    }

    public function test_the_agents_own_suburbs_come_first_then_the_rest(): void
    {
        $outAddr = $this->listing(self::OLD + ['address' => '1 Far Road', 'suburb' => 'Shelly Beach'], $this->tp(['suburb_normalised' => 'shelly beach']));
        $inNoAdr = $this->listing(self::OLD + ['suburb' => 'Uvongo'], $this->tp(['suburb_normalised' => 'uvongo']));
        $inAddr  = $this->listing(self::OLD + ['address' => '2 Near Road', 'suburb' => 'Uvongo'], $this->tp(['suburb_normalised' => 'uvongo']));

        $c = $this->pick(['areaNames' => ['uvongo']]);

        $this->assertSame([$inAddr, $inNoAdr, $outAddr], $this->listingIds($c), 'own area (addressed first), then outside');
        $this->assertSame([true, true, false], array_map(fn ($x) => $x->inArea, $c));
    }

    public function test_area_match_works_by_suburb_id_and_by_free_text_when_there_is_no_tracked_property(): void
    {
        $byId   = $this->listing(self::OLD + ['suburb' => 'Whatever'], $this->tp(['p24_suburb_id' => 6359, 'suburb_normalised' => 'elsewhere']));
        $byText = $this->listing(self::OLD + ['suburb' => ' UVONGO '], null);
        $other  = $this->listing(self::OLD + ['suburb' => 'Port Edward'], $this->tp(['suburb_normalised' => 'port edward']));

        $c = $this->pick(['areaIds' => [6359], 'areaNames' => ['uvongo']]);

        $this->assertEqualsCanonicalizing([$byId, $byText], array_slice($this->listingIds($c), 0, 2));
        $this->assertSame($other, end($c)->prospectingListingId);
    }

    public function test_lock_on_offers_only_the_agents_own_suburbs(): void
    {
        $in  = $this->listing(self::OLD + ['suburb' => 'Uvongo'], $this->tp(['suburb_normalised' => 'uvongo']));
        $out = $this->listing(self::OLD + ['suburb' => 'Margate'], $this->tp(['suburb_normalised' => 'margate']));

        $locked = $this->pick(['settings' => ['lockAgentsToAreas' => true], 'areaNames' => ['uvongo']]);
        $this->assertSame([$in], $this->listingIds($locked));

        $open = $this->pick(['settings' => ['lockAgentsToAreas' => false], 'areaNames' => ['uvongo']]);
        $this->assertSame([$in, $out], $this->listingIds($open), 'lock off: own suburb first, the rest after');
    }

    public function test_lock_on_with_no_areas_gives_nothing_but_lock_off_with_no_areas_gives_the_pool(): void
    {
        $a = $this->listing(self::OLD);

        $this->assertSame([], $this->pick(['settings' => ['lockAgentsToAreas' => true]]));
        $this->assertSame([$a], $this->listingIds($this->pick(['settings' => ['lockAgentsToAreas' => false]])));
    }

    // ── never suggest ────────────────────────────────────────────────────

    public function test_inactive_deleted_and_unusable_rows_are_never_offered(): void
    {
        $ok = $this->listing(self::OLD);
        $this->listing(self::OLD + ['is_active' => 0]);
        $this->listing(self::OLD + ['deleted_at' => now()]);
        $deletedTp = $this->tp();
        $deletedTp->delete();
        $this->listing(self::OLD, $deletedTp);
        $this->listing(self::OLD, $this->tp(['status' => 'duplicate']));

        $this->assertSame([$ok], $this->listingIds($this->pick()));
    }

    public function test_the_agencys_own_on_market_stock_is_never_offered(): void
    {
        $ours = $this->listing(self::OLD + ['portal_ref' => 'P24-777001']);
        $this->property($this->mate->id, ['p24_ref' => '777001', 'status' => 'active']);
        $free = $this->listing(self::OLD);
        \App\Services\Prospecting\OnMarketStockService::flushCache();

        $ids = $this->listingIds($this->pick());
        $this->assertContains($free, $ids);
        $this->assertNotContains($ours, $ids);
    }

    public function test_a_property_that_is_another_agents_corex_listing_is_never_offered_but_mine_or_dead_ones_are_not_blocked_by_this_rule(): void
    {
        $theirs    = $this->property($this->mate->id, ['status' => 'active']);
        $theirPros = $this->property($this->mate->id, ['status' => 'prospecting']);
        $theirOld  = $this->property($this->mate->id, ['status' => 'withdrawn']);
        $mine      = $this->property($this->agent->id, ['status' => 'prospecting']);

        $onTheirs = $this->listing(self::OLD + ['matched_property_id' => $theirs]);
        $onTheirPros = $this->listing(self::OLD, $this->tp(['promoted_to_property_id' => $theirPros]));
        $onTheirOld = $this->listing(self::OLD + ['matched_property_id' => $theirOld]);
        $onMine = $this->listing(self::OLD + ['matched_property_id' => $mine]);

        $ids = $this->listingIds($this->pick());
        $this->assertNotContains($onTheirs, $ids, "another agent's active property");
        $this->assertNotContains($onTheirPros, $ids, "another agent's prospect, found through the tracked property");
        $this->assertContains($onTheirOld, $ids, 'a withdrawn property is history, not a taken one');
        $this->assertContains($onMine, $ids);
    }

    public function test_a_listing_another_agent_has_claimed_or_is_pitching_is_never_offered(): void
    {
        $claimed = $this->listing(self::OLD);
        $pitched = $this->listing(self::OLD);
        $expiredLock = $this->listing(self::OLD);
        $myClaim = $this->listing(self::OLD);
        $released = $this->listing(self::OLD);
        $free = $this->listing(self::OLD);

        $claim = fn (int $lid, int $uid, array $x = []) => DB::table('prospecting_claims')->insert(array_merge([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $lid, 'user_id' => $uid, 'status' => 'claimed',
            'is_active' => 1, 'claimed_at' => now(), 'last_updated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $x));
        $claim($claimed, $this->mate->id);
        $claim($myClaim, $this->agent->id);
        $claim($released, $this->mate->id, ['is_active' => 0, 'released_at' => now()]);

        $lock = fn (int $lid, $expires) => DB::table('prospecting_pitch_locks')->insert([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $lid, 'user_id' => $this->mate->id,
            'locked_at' => now(), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lock($pitched, now()->addMinutes(20));
        $lock($expiredLock, now()->subMinute());

        $ids = $this->listingIds($this->pick());
        $this->assertNotContains($claimed, $ids);
        $this->assertNotContains($pitched, $ids);
        $this->assertEqualsCanonicalizing([$expiredLock, $myClaim, $released, $free], $ids);
    }

    public function test_the_suggestion_guard_removes_held_do_not_contact_and_own_held_properties(): void
    {
        $tpHeld = $this->tp(); $tpDnc = $this->tp(); $tpMine = $this->tp(); $tpOk = $this->tp();
        $held = $this->listing(self::OLD, $tpHeld);
        $dnc  = $this->listing(self::OLD, $tpDnc);
        $mine = $this->listing(self::OLD, $tpMine);
        $ok   = $this->listing(self::OLD, $tpOk);

        $guard = new class([$tpHeld->id, $tpDnc->id], [$tpMine->id]) implements SuggestionGuard {
            public function __construct(private array $blocked, private array $own) {}
            public function blockedFor(User $user, array $ids, string $track): array { return array_values(array_intersect($ids, $this->blocked)); }
            public function ownHeld(User $user, array $ids, string $track): array { return array_values(array_intersect($ids, $this->own)); }
        };

        $this->assertSame([$ok], $this->listingIds($this->pick(['guard' => $guard])));
    }

    public function test_properties_already_on_the_days_list_are_not_offered_again(): void
    {
        $tp = $this->tp();
        $a = $this->listing(self::OLD, $tp);
        $b = $this->listing(self::OLD);
        $c = $this->listing(self::OLD);

        $ids = $this->listingIds($this->pick(['excludeTracked' => [$tp->id], 'excludeListings' => [$c]]));
        $this->assertSame([$b], $ids);
    }

    public function test_a_portal_twin_collapses_to_one_candidate_on_the_tracked_property(): void
    {
        $tp = $this->tp(['street_name' => 'Hill Lane']);
        $p24 = $this->listing(self::OLD + ['portal_source' => 'p24'], $tp);
        $pp  = $this->listing(self::OLD + ['portal_source' => 'pp', 'portal_ref' => 'PP-T1234567'], $tp);

        $c = $this->pick();
        $this->assertCount(1, $c);
        $this->assertSame($tp->id, $c[0]->trackedPropertyId);
    }

    public function test_the_limit_is_respected_and_other_agencies_never_leak_in(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->listing(self::OLD);
        }
        $other = \App\Models\Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $ob = \App\Models\Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $otherUser = $this->makeAgent($other, $ob);
        $theirs = $this->listing(self::OLD, $this->tp([], $other), $other);

        $this->assertCount(3, $this->pick([], 3));
        $this->assertNotContains($theirs, $this->listingIds($this->pick([], 50)));
        $this->assertSame([$theirs], $this->listingIds((new LongOnMarketSelection())->candidates($this->ctx($otherUser), 50)), 'and they see only their own');
    }

    public function test_a_limit_of_zero_returns_nothing(): void
    {
        $this->listing(self::OLD);
        $this->assertSame([], $this->pick([], 0));
    }
}
