<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use App\Services\RoadToSold\Adapters\NullSuggestionGuard;
use App\Services\RoadToSold\Contracts\AreaProvider;
use App\Services\RoadToSold\Contracts\FollowUp;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\Support\BuilderSettings;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 piece 5 — ListBuilderService: the day's list, add-to-day, and closing earlier days.
 * Uses fake calendar / areas / follow-ups / guard (the other lanes' pieces) against the REAL selections
 * and the real database.
 */
final class ListBuilderTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';   // a Wednesday
    private const OLD = ['first_seen_at' => '2026-03-01 08:00:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        Cache::flush();
    }

    /** @param array $o settings, target, workdays(list of Y-m-d that are NOT workdays), followUps, guard, areaNames, areaIds */
    private function builder(array $o = []): ListBuilderService
    {
        $settings = (new BuilderSettings())->with($o['settings'] ?? []);
        $target = $o['target'] ?? 5;
        $off = $o['off'] ?? [];

        $calendar = new class($target, $off) implements WorkCalendar {
            public function __construct(private int $t, private array $off) {}
            public function targetFor(User $u, CarbonInterface $d, string $track): int { return $this->isWorkday($u, $d, $track) ? $this->t : 0; }
            public function isWorkday(User $u, CarbonInterface $d, string $track): bool { return ! $d->isWeekend() && ! in_array($d->toDateString(), $this->off, true) && $this->t > 0; }
            public function nextWorkday(User $u, CarbonInterface $from, string $track): ?Carbon {
                $x = Carbon::instance($from)->startOfDay();
                for ($i = 0; $i < 60; $i++) { $x = $x->addDay(); if ($this->isWorkday($u, $x, $track)) { return $x; } }
                return null;
            }
        };
        $areas = new class($o['areaNames'] ?? [], $o['areaIds'] ?? []) implements AreaProvider {
            public function __construct(private array $n, private array $i) {}
            public function suburbIdsFor(User $u, string $t): array { return $this->i; }
            public function suburbNamesNormalisedFor(User $u, string $t): array { return $this->n; }
            public function suburbNamesLowerTrimFor(User $u, string $t): array { return array_map(fn ($x) => mb_strtolower(trim($x)), $this->n); }
            public function hasAreas(User $u, string $t): bool { return $this->i !== [] || $this->n !== []; }
        };
        $followUps = new class($o['followUps'] ?? []) implements FollowUpSource {
            public function __construct(private array $f) {}
            public function dueOn(User $u, CarbonInterface $d, string $t): array { return $this->f[$u->id] ?? []; }
        };

        return new ListBuilderService(
            calendar: $calendar, areas: $areas,
            guard: $o['guard'] ?? new NullSuggestionGuard(),
            followUps: $followUps,
            settingsResolver: fn (User $u, string $t) => $settings,
        );
    }

    private function trackedIds($items): array
    {
        return $items->pluck('tracked_property_id')->all();
    }

    private function longListing(array $attrs = [], $tp = true): int
    {
        return $this->listing(self::OLD + $attrs, $tp);
    }

    // ── filling the day ──────────────────────────────────────────────────

    public function test_the_list_fills_to_the_target_from_the_selections_in_the_agencys_order(): void
    {
        // 2 long-on-market, 2 new-on-market, target 3. Order [new, long] -> both "new" first, then ONE long.
        $new1 = $this->listing(['listed_date' => '2026-10-13']);
        $new2 = $this->listing(['listed_date' => '2026-10-12']);
        $long1 = $this->longListing(['address' => '1 Old Rd']);
        $long2 = $this->longListing(['address' => '2 Old Rd']);

        $items = $this->builder(['target' => 3, 'settings' => ['enabledSelectionKeys' => ['new_on_market', 'long_on_market']]])
            ->buildFor($this->agent, self::WED);

        $this->assertCount(3, $items);
        $this->assertSame(['new_on_market', 'new_on_market', 'long_on_market'], $items->pluck('selection')->all());
        $this->assertSame([1, 2, 3], $items->pluck('position')->all());
        $this->assertSame([$new1, $new2], $items->take(2)->pluck('prospecting_listing_id')->all());
        $this->assertSame(['auto', 'auto', 'auto'], $items->pluck('origin')->all());
        $this->assertSame(['open'], $items->pluck('status')->unique()->values()->all());
        $this->assertNotNull($items[0]->reason);
        $this->assertSame((int) $this->agent->id, (int) $items[0]->user_id);
        $this->assertSame((int) $this->agency->id, (int) $items[0]->agency_id);
        $this->assertSame((int) $this->branch->id, (int) $items[0]->branch_id);
        $this->assertSame('sales', $items[0]->track);
        $this->assertSame(self::WED, $items[0]->list_date->toDateString());
        $this->assertNotNull($items[0]->tracked_property_id, 'the canonical key is always carried');
    }

    public function test_reordering_the_selections_changes_which_properties_lead(): void
    {
        $new = $this->listing(['listed_date' => '2026-10-13']);
        $long = $this->longListing();

        $a = $this->builder(['target' => 1, 'settings' => ['enabledSelectionKeys' => ['new_on_market', 'long_on_market']]])->buildFor($this->agent, self::WED);
        $this->assertSame([$new], $a->pluck('prospecting_listing_id')->all());

        $b = $this->builder(['target' => 1, 'settings' => ['enabledSelectionKeys' => ['long_on_market', 'new_on_market']]])->buildFor($this->mate, self::WED);
        $this->assertSame([$long], $b->pluck('prospecting_listing_id')->all());
    }

    public function test_a_switched_off_selection_is_never_used(): void
    {
        $this->listing(['listed_date' => '2026-10-13']);                       // would be a "new on market"
        $long = $this->longListing();

        $items = $this->builder(['settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->agent, self::WED);

        $this->assertSame([$long], $items->pluck('prospecting_listing_id')->all());
    }

    public function test_the_target_is_respected_and_a_short_pool_gives_a_short_list(): void
    {
        for ($i = 0; $i < 8; $i++) { $this->longListing(); }
        $this->assertCount(5, $this->builder(['target' => 5])->buildFor($this->agent, self::WED));

        $this->assertCount(3, $this->builder(['target' => 10])->buildFor($this->mate, self::WED)->take(3), 'sanity');
        $short = $this->makeAgent();
        $items = $this->builder(['target' => 20, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($short, self::WED);
        $this->assertCount(8, $items, 'only 8 eligible properties exist: no padding');
    }

    public function test_a_property_in_two_selections_is_listed_once_under_the_first(): void
    {
        $both = $this->listing(['listed_date' => '2026-05-01']);               // old AND we say new? no: just old
        $b = $this->buyer($this->agent->id);
        $this->buyerMatch($both, $b->id);

        $items = $this->builder(['target' => 5, 'settings' => ['enabledSelectionKeys' => ['my_buyers_sourcing', 'agency_buyers_sourcing', 'long_on_market']]])
            ->buildFor($this->agent, self::WED);

        $this->assertCount(1, $items);
        $this->assertSame('my_buyers_sourcing', $items[0]->selection);
    }

    public function test_properties_with_an_address_are_listed_before_those_without_even_across_one_selection(): void
    {
        $none1 = $this->longListing();
        $addr1 = $this->longListing(['address' => '1 Beach Rd']);
        $none2 = $this->longListing();
        $addr2 = $this->longListing(['address' => '2 Beach Rd']);

        $items = $this->builder(['target' => 2, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->agent, self::WED);

        $this->assertEqualsCanonicalizing([$addr1, $addr2], $items->pluck('prospecting_listing_id')->all(), 'the address-less ones are only used when there are not enough addressed');

        $more = $this->builder(['target' => 4, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->mate, self::WED);
        $this->assertEqualsCanonicalizing([$addr1, $addr2, $none1, $none2], $more->pluck('prospecting_listing_id')->all());
        $this->assertEqualsCanonicalizing([$addr1, $addr2], $more->take(2)->pluck('prospecting_listing_id')->all());
    }

    public function test_every_property_counts_toward_the_target_with_or_without_an_owner_on_file(): void
    {
        $owner = \App\Models\Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'O', 'last_name' => 'Wner', 'phone' => '0821234567',
        ]);
        $this->longListing([], $this->tp(['owner_contact_id' => $owner->id]));
        $this->longListing();
        $this->longListing();

        $this->assertCount(3, $this->builder(['target' => 3, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->agent, self::WED));
    }

    // ── follow-ups ───────────────────────────────────────────────────────

    public function test_follow_ups_come_first_and_count_toward_the_target(): void
    {
        $tpA = $this->tp(); $tpB = $this->tp();
        $lA = $this->longListing([], $tpA);
        $lB = $this->longListing([], $tpB);
        $others = [$this->longListing(), $this->longListing(), $this->longListing()];

        $items = $this->builder([
            'target' => 4, 'settings' => ['enabledSelectionKeys' => ['long_on_market']],
            'followUps' => [$this->agent->id => [
                new FollowUp($tpA->id, $lA, null, 'No answer last week - try again'),
                new FollowUp($tpB->id, $lB, null, 'Asked to call back'),
            ]],
        ])->buildFor($this->agent, self::WED);

        $this->assertCount(4, $items, '2 follow-ups + 2 new = the target of 4');
        $this->assertSame(['follow_up', 'follow_up', 'auto', 'auto'], $items->pluck('origin')->all());
        $this->assertSame([$tpA->id, $tpB->id], array_slice($this->trackedIds($items), 0, 2));
        $this->assertSame('follow_up', $items[0]->selection);
        $this->assertSame('No answer last week - try again', $items[0]->reason);
        $this->assertNotContains($lA, $items->slice(2)->pluck('prospecting_listing_id')->all(), 'a follow-up is not suggested again as new');
    }

    public function test_follow_ups_are_never_trimmed_even_past_the_target_and_then_nothing_new_is_added(): void
    {
        $fu = [];
        for ($i = 0; $i < 4; $i++) { $tp = $this->tp(); $fu[] = new FollowUp($tp->id, null, null, 'due'); }
        $this->longListing();

        $items = $this->builder(['target' => 3, 'followUps' => [$this->agent->id => $fu]])->buildFor($this->agent, self::WED);

        $this->assertCount(4, $items);
        $this->assertSame(['follow_up'], $items->pluck('origin')->unique()->values()->all());
    }

    public function test_a_follow_up_the_guard_now_blocks_is_dropped(): void
    {
        $ok = $this->tp(); $dnc = $this->tp();
        $guard = new class([$dnc->id]) implements SuggestionGuard {
            public function __construct(private array $b) {}
            public function blockedFor(User $u, array $ids, string $t): array { return array_values(array_intersect($ids, $this->b)); }
            public function ownHeld(User $u, array $ids, string $t): array { return []; }
        };

        $items = $this->builder(['target' => 0 + 2, 'guard' => $guard, 'settings' => ['enabledSelectionKeys' => []],
            'followUps' => [$this->agent->id => [new FollowUp($ok->id), new FollowUp($dnc->id)]]])->buildFor($this->agent, self::WED);

        $this->assertSame([$ok->id], $this->trackedIds($items), 'do-not-contact is never called');
    }

    public function test_follow_ups_belong_to_their_agent_only(): void
    {
        $tp = $this->tp();
        $b = $this->builder(['settings' => ['enabledSelectionKeys' => []], 'followUps' => [$this->agent->id => [new FollowUp($tp->id)]]]);

        $this->assertCount(1, $b->buildFor($this->agent, self::WED));
        $this->assertCount(0, $b->buildFor($this->mate, self::WED));
    }

    // ── working days, idempotence ───────────────────────────────────────

    public function test_no_list_on_a_non_working_day_or_a_zero_target(): void
    {
        $this->longListing();

        $this->assertCount(0, $this->builder()->buildFor($this->agent, '2026-10-17'), 'a Saturday');
        $this->assertCount(0, $this->builder(['off' => [self::WED]])->buildFor($this->agent, self::WED), 'a public holiday');
        $this->assertCount(0, $this->builder(['target' => 0])->buildFor($this->agent, self::WED));
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->count());
    }

    public function test_building_twice_adds_nothing_and_a_short_list_tops_up_later(): void
    {
        $this->longListing();
        $b = $this->builder(['target' => 3, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]]);

        $this->assertCount(1, $b->buildFor($this->agent, self::WED));
        $this->assertCount(1, $b->buildFor($this->agent, self::WED), 'second run: same list, no duplicates');

        $this->longListing(); $this->longListing(); $this->longListing();
        Cache::flush();     // the page's 10-minute guard has lapsed
        $items = $b->buildFor($this->agent, self::WED);
        $this->assertCount(3, $items, 'topped up to the target, never beyond it');
        $this->assertSame(3, RtsListItem::withoutGlobalScopes()->count());
        $this->assertSame([1, 2, 3], $items->pluck('position')->all());
    }

    public function test_an_agent_with_no_agency_context_gets_nothing_written(): void
    {
        $orphan = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'agent']);

        $this->assertCount(0, $this->builder()->buildFor($orphan, self::WED));
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->count());
    }

    // ── areas and the lock, end to end ──────────────────────────────────

    public function test_the_agents_suburbs_fill_first_and_the_rest_only_if_the_day_is_not_full(): void
    {
        $in1 = $this->longListing(['suburb' => 'Uvongo', 'address' => '1 A Rd'], $this->tp(['suburb_normalised' => 'uvongo']));
        $in2 = $this->longListing(['suburb' => 'Uvongo', 'address' => '2 A Rd'], $this->tp(['suburb_normalised' => 'uvongo']));
        $out = $this->longListing(['suburb' => 'Margate', 'address' => '3 A Rd']);

        $two = $this->builder(['target' => 2, 'areaNames' => ['uvongo'], 'settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->agent, self::WED);
        $this->assertEqualsCanonicalizing([$in1, $in2], $two->pluck('prospecting_listing_id')->all());

        $three = $this->builder(['target' => 3, 'areaNames' => ['uvongo'], 'settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->mate, self::WED);
        $this->assertSame($out, $three->last()->prospecting_listing_id, 'spills into the agency\'s other suburbs only because the day was not full');
    }

    public function test_lock_on_keeps_an_agent_inside_their_suburbs_lock_off_does_not(): void
    {
        $in = $this->longListing(['suburb' => 'Uvongo'], $this->tp(['suburb_normalised' => 'uvongo']));
        $out = $this->longListing(['suburb' => 'Margate']);

        $locked = $this->builder(['areaNames' => ['uvongo'], 'settings' => ['lockAgentsToAreas' => true, 'enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->agent, self::WED);
        $this->assertSame([$in], $locked->pluck('prospecting_listing_id')->all());

        $open = $this->builder(['areaNames' => ['uvongo'], 'settings' => ['lockAgentsToAreas' => false, 'enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->mate, self::WED);
        $this->assertSame([$in, $out], $open->pluck('prospecting_listing_id')->all());

        $none = $this->makeAgent();
        $this->assertCount(0, $this->builder(['settings' => ['lockAgentsToAreas' => true, 'enabledSelectionKeys' => ['long_on_market']]])->buildFor($none, self::WED), 'locked with no areas: no list');
    }

    // ── sharing a suburb ─────────────────────────────────────────────────

    public function test_two_agents_sharing_a_suburb_never_get_the_same_property_while_a_hold_exists(): void
    {
        $tp1 = $this->tp(); $tp2 = $this->tp();
        $this->longListing(['address' => '1 Shared Rd'], $tp1);
        $this->longListing(['address' => '2 Shared Rd'], $tp2);
        $agentId = $this->agent->id;

        // cc3's rule in miniature: once the first agent holds tp1, it is blocked for every other agent.
        $holds = [$tp1->id => $agentId];
        $guard = new class($holds) implements SuggestionGuard {
            public function __construct(private array $holds) {}
            public function blockedFor(User $u, array $ids, string $t): array { return array_values(array_filter($ids, fn ($i) => isset($this->holds[$i]) && $this->holds[$i] !== $u->id)); }
            public function ownHeld(User $u, array $ids, string $t): array { return array_values(array_filter($ids, fn ($i) => ($this->holds[$i] ?? null) === $u->id)); }
        };
        $opts = ['target' => 5, 'guard' => $guard, 'areaNames' => ['margate'], 'settings' => ['enabledSelectionKeys' => ['long_on_market']]];

        $mate = $this->builder($opts)->buildFor($this->mate, self::WED);
        $mine = $this->builder($opts)->buildFor($this->agent, self::WED);

        $this->assertSame([$tp2->id], $this->trackedIds($mate), 'the held property is not offered to the other agent');
        $this->assertSame([$tp2->id], $this->trackedIds($mine), 'and the holder is not offered it as "new" either (a follow-up brings it back)');
    }

    public function test_without_a_hold_two_agents_can_be_handed_the_same_property(): void
    {
        // Documents spec question Q7: the brief only forbids HELD properties; there is no same-day de-duplication.
        $tp = $this->tp();
        $this->longListing([], $tp);
        $opts = ['target' => 5, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]];

        $this->assertSame([$tp->id], $this->trackedIds($this->builder($opts)->buildFor($this->agent, self::WED)));
        $this->assertSame([$tp->id], $this->trackedIds($this->builder($opts)->buildFor($this->mate, self::WED)));
    }

    // ── own stock never leaks ────────────────────────────────────────────

    public function test_one_agents_own_stock_never_appears_on_another_agents_list(): void
    {
        $mine = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-01']);
        $theirs = $this->property($this->mate->id, ['status' => 'expired', 'expiry_date' => '2026-10-02']);
        $opts = ['target' => 5, 'settings' => ['enabledSelectionKeys' => ['my_expired_stock', 'my_stock_not_selling']]];

        $mateItems = $this->builder($opts)->buildFor($this->mate, self::WED);
        $myItems = $this->builder($opts)->buildFor($this->agent, self::WED);

        $this->assertSame([$theirs], $mateItems->pluck('property_id')->all());
        $this->assertSame([$mine], $myItems->pluck('property_id')->all());
    }

    // ── add to my day ────────────────────────────────────────────────────

    public function test_add_to_day_adds_extras_that_do_not_count_toward_the_target(): void
    {
        for ($i = 0; $i < 6; $i++) { $this->longListing(); }
        $b = $this->builder(['target' => 2, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]]);
        $b->buildFor($this->agent, self::WED);

        $added = $b->addToDay($this->agent, 'long_on_market', 3, self::WED);

        $this->assertCount(3, $added);
        $this->assertSame(['added'], $added->pluck('origin')->unique()->values()->all());
        $day = $b->listFor($this->agent, self::WED);
        $this->assertCount(5, $day);
        $this->assertSame(2, $day->where('origin', '!=', 'added')->count());
        $this->assertSame(5, $day->pluck('tracked_property_id')->unique()->count(), 'no property twice');

        // and a later build does not "make up" for the extras
        Cache::flush();
        $this->assertCount(5, $b->buildFor($this->agent, self::WED));
    }

    public function test_add_to_day_gives_fewer_when_the_selection_runs_dry(): void
    {
        $this->longListing(); $this->longListing();
        $b = $this->builder(['target' => 1, 'settings' => ['enabledSelectionKeys' => ['long_on_market']]]);
        $b->buildFor($this->agent, self::WED);

        $this->assertCount(1, $b->addToDay($this->agent, 'long_on_market', 10, self::WED));
    }

    public function test_add_to_day_refuses_nonsense_with_a_plain_message(): void
    {
        $b = $this->builder(['settings' => ['enabledSelectionKeys' => ['long_on_market']]]);

        foreach ([[0, 'long_on_market'], [-3, 'long_on_market'], [101, 'long_on_market']] as [$n, $key]) {
            try { $b->addToDay($this->agent, $key, $n, self::WED); $this->fail("count {$n} should be refused"); }
            catch (InvalidArgumentException $e) { $this->assertStringContainsString('between 1 and 100', $e->getMessage()); }
        }
        foreach (['new_on_market', 'nonsense', ''] as $key) {   // switched off / unknown / blank
            try { $b->addToDay($this->agent, $key, 3, self::WED); $this->fail("selection '{$key}' should be refused"); }
            catch (InvalidArgumentException $e) { $this->assertStringContainsString('not switched on', $e->getMessage()); }
        }
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->count());
    }

    public function test_add_to_day_from_the_agents_own_stock_selection_is_only_their_stock(): void
    {
        $mine = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-01']);
        $this->property($this->mate->id, ['status' => 'expired', 'expiry_date' => '2026-10-02']);
        $b = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_expired_stock']]]);

        $added = $b->addToDay($this->agent, 'my_expired_stock', 10, self::WED);

        $this->assertSame([$mine], $added->pluck('property_id')->all());
    }

    // ── closing earlier days ─────────────────────────────────────────────

    private function openItem(User $u, string $date, array $x = []): RtsListItem
    {
        return RtsListItem::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $u->agency_id, 'user_id' => $u->id, 'list_date' => $date, 'track' => 'sales',
            'tracked_property_id' => random_int(10_000, 9_999_999), 'selection' => 'long_on_market', 'origin' => 'auto',
            'position' => 1, 'status' => 'open', 'reason' => 'Old for a long time',
        ], $x));
    }

    public function test_by_default_open_items_from_earlier_days_become_missed(): void
    {
        $old = $this->openItem($this->agent, '2026-10-12');
        $worked = $this->openItem($this->agent, '2026-10-12', ['status' => 'worked']);
        $today = $this->openItem($this->agent, self::WED);
        $mates = $this->openItem($this->mate, '2026-10-12');

        $r = $this->builder()->closePastDays($this->agent, self::WED);

        $this->assertSame(['missed' => 1, 'rolled' => 0], $r);
        $this->assertSame('missed', $old->fresh()->status);
        $this->assertNotNull($old->fresh()->status_changed_at);
        $this->assertSame('worked', $worked->fresh()->status);
        $this->assertSame('open', $today->fresh()->status, 'today is not "earlier"');
        $this->assertSame('open', $mates->fresh()->status, 'only this agent\'s items');
    }

    public function test_in_roll_over_mode_missed_items_move_to_the_next_working_day_once(): void
    {
        $auto = $this->openItem($this->agent, '2026-10-12', ['tracked_property_id' => 555, 'reason' => 'Price dropped 5%']);
        $fu = $this->openItem($this->agent, '2026-10-12', ['origin' => 'follow_up', 'selection' => 'follow_up']);
        $added = $this->openItem($this->agent, '2026-10-12', ['origin' => 'added']);
        $b = $this->builder(['settings' => ['rollsOver' => true]]);

        $r = $b->closePastDays($this->agent, self::WED);

        $this->assertSame(['missed' => 2, 'rolled' => 1], $r);
        $this->assertSame('rolled', $auto->fresh()->status);
        $this->assertSame('missed', $fu->fresh()->status, 'follow-ups come back by themselves while still due');
        $this->assertSame('missed', $added->fresh()->status, 'extras are not carried');

        $copy = RtsListItem::withoutGlobalScopes()->where('rolled_from_item_id', $auto->id)->first();
        $this->assertNotNull($copy);
        $this->assertSame(self::WED, $copy->list_date->toDateString());
        $this->assertSame('rollover', $copy->origin);
        $this->assertSame('open', $copy->status);
        $this->assertSame(555, (int) $copy->tracked_property_id);
        $this->assertSame('long_on_market', $copy->selection);
        $this->assertStringContainsString('Carried over from 12 Oct', $copy->reason);

        // a second sweep changes nothing
        $this->assertSame(['missed' => 0, 'rolled' => 0], $b->closePastDays($this->agent, self::WED));

        // and when THIS one is also missed tomorrow it is marked missed, not carried forever
        $this->assertSame(['missed' => 1, 'rolled' => 0], $b->closePastDays($this->agent, '2026-10-15'));
        $this->assertSame('missed', $copy->fresh()->status);
    }

    public function test_roll_over_lands_on_the_first_working_day_on_or_after_today(): void
    {
        $fri = $this->openItem($this->agent, '2026-10-16', ['tracked_property_id' => 777]);

        // closing on Sunday: the next working day is Monday the 19th
        $this->builder(['settings' => ['rollsOver' => true]])->closePastDays($this->agent, '2026-10-18');

        $copy = RtsListItem::withoutGlobalScopes()->where('rolled_from_item_id', $fri->id)->first();
        $this->assertSame('2026-10-19', $copy->list_date->toDateString());
    }

    public function test_roll_over_with_no_working_day_to_land_on_marks_missed(): void
    {
        $item = $this->openItem($this->agent, '2026-10-12');
        $b = $this->builder(['target' => 0, 'settings' => ['rollsOver' => true]]);

        $this->assertSame(['missed' => 1, 'rolled' => 0], $b->closePastDays($this->agent, self::WED));
        $this->assertSame('missed', $item->fresh()->status);
    }

    public function test_rolled_items_count_toward_todays_target_so_fewer_new_ones_are_added(): void
    {
        for ($i = 0; $i < 6; $i++) { $this->longListing(); }
        $this->openItem($this->agent, '2026-10-12', ['tracked_property_id' => 888]);
        $this->openItem($this->agent, '2026-10-12', ['tracked_property_id' => 889]);
        $b = $this->builder(['target' => 5, 'settings' => ['rollsOver' => true, 'enabledSelectionKeys' => ['long_on_market']]]);

        $b->closePastDays($this->agent, self::WED);
        $items = $b->buildFor($this->agent, self::WED);

        $this->assertCount(5, $items);
        $this->assertSame(2, $items->where('origin', 'rollover')->count());
        $this->assertSame(3, $items->where('origin', 'auto')->count());
    }

    // ── marking worked ───────────────────────────────────────────────────

    public function test_mark_worked_updates_that_agents_open_item_for_that_property_that_day(): void
    {
        $a = $this->openItem($this->agent, self::WED, ['tracked_property_id' => 321]);
        $other = $this->openItem($this->agent, self::WED, ['tracked_property_id' => 322]);
        $mates = $this->openItem($this->mate, self::WED, ['tracked_property_id' => 321]);
        $b = $this->builder();

        $this->assertSame(1, $b->markWorked($this->agent, 321, self::WED));
        $this->assertSame('worked', $a->fresh()->status);
        $this->assertSame('open', $other->fresh()->status);
        $this->assertSame('open', $mates->fresh()->status);
        $this->assertSame(0, $b->markWorked($this->agent, 321, self::WED), 'already worked');
        $this->assertSame(0, $b->markWorked($this->agent, null, self::WED));
    }

    // ── agencies ─────────────────────────────────────────────────────────

    public function test_a_second_agency_builds_only_from_its_own_data_and_settings(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Cape Agency ' . uniqid(), 'slug' => 'cape-' . uniqid()]);
        $ob = \App\Models\Branch::create(['agency_id' => $other->id, 'name' => 'Cape']);
        $capeAgent = $this->makeAgent($other, $ob);
        $ours = $this->longListing();
        $theirs = $this->listing(self::OLD, $this->tp([], $other), $other);

        $cape = $this->builder(['settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($capeAgent, self::WED);
        $us = $this->builder(['settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->agent, self::WED);

        $this->assertSame([$theirs], $cape->pluck('prospecting_listing_id')->all());
        $this->assertSame([$ours], $us->pluck('prospecting_listing_id')->all());
        $this->assertSame((int) $other->id, (int) $cape[0]->agency_id);
    }

    public function test_a_manager_building_for_an_agent_stamps_the_agents_agency_not_their_own(): void
    {
        $this->longListing();
        $manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($manager);

        $items = $this->builder(['settings' => ['enabledSelectionKeys' => ['long_on_market']]])->buildFor($this->agent, self::WED);

        $this->assertSame((int) $this->agent->id, (int) $items[0]->user_id);
        $this->assertSame((int) $this->agency->id, (int) $items[0]->agency_id);
    }

    // ── the nightly command, with the real defaults ─────────────────────

    public function test_the_nightly_command_builds_lists_with_the_real_adapters_and_default_settings(): void
    {
        for ($i = 0; $i < 7; $i++) { $this->longListing(['address' => "{$i} Night Rd"]); }

        $this->artisan('rts:build-lists', ['--user' => $this->agent->id, '--date' => self::WED])
            ->expectsOutputToContain('1 agents processed, 0 failed')
            ->assertExitCode(0);

        $items = RtsListItem::withoutGlobalScopes()->where('user_id', $this->agent->id)->get();
        $this->assertCount(5, $items, 'default target of 5 on a weekday');
        $this->assertSame(self::WED, $items[0]->list_date->toDateString());

        // re-running adds nothing
        $this->artisan('rts:build-lists', ['--user' => $this->agent->id, '--date' => self::WED])->assertExitCode(0);
        $this->assertSame(5, RtsListItem::withoutGlobalScopes()->where('user_id', $this->agent->id)->count());
    }

    public function test_the_nightly_command_closes_earlier_days_first(): void
    {
        $this->openItem($this->agent, '2026-10-12');

        $this->artisan('rts:build-lists', ['--user' => $this->agent->id, '--date' => self::WED])->assertExitCode(0);

        $this->assertSame('missed', RtsListItem::withoutGlobalScopes()->where('list_date', '2026-10-12')->first()->status);
    }
}
