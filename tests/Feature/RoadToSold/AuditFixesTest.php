<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\ProspectingListing;
use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use App\Services\RoadToSold\Contracts\AreaProvider;
use App\Services\RoadToSold\Contracts\FollowUp;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\Selections\Candidate;
use App\Services\RoadToSold\Selections\MicPoolSelection;
use App\Services\RoadToSold\Selections\MyExpiredStockSelection;
use App\Services\RoadToSold\Selections\NewOnMarketSelection;
use App\Services\RoadToSold\Selections\OwnerKnownSelection;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\Support\BuilderSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\RoadToSold\Concerns\NullSuggestionGuard;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 - the audit gaps in the list builder (cc4's findings A-H, 13 Oct):
 *   A other agents' stock never leaks, on any path   B no silent fallback   C owner-known quantity per day
 *   D selection 8 / 9 rules   E settings instead of constants   F track filter   G keyed + de-duplicated rows
 *   H the day index is usable
 */
final class AuditFixesTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';
    private const OLD = ['first_seen_at' => '2026-03-01 08:00:00'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        Cache::flush();
        SettingsService::forget();
    }

    private function builder(array $o = []): ListBuilderService
    {
        $settings = (new BuilderSettings())->with($o['settings'] ?? []);
        $calendar = new class($o['target'] ?? 5) implements WorkCalendar {
            public function __construct(private int $t) {}
            public function targetFor(User $u, CarbonInterface $d, string $track): int { return $this->isWorkday($u, $d, $track) ? $this->t : 0; }
            public function isWorkday(User $u, CarbonInterface $d, string $track): bool { return ! $d->isWeekend(); }
            public function nextWorkday(User $u, CarbonInterface $from, string $track): ?Carbon {
                $x = Carbon::instance($from)->startOfDay();
                do { $x = $x->addDay(); } while ($x->isWeekend());
                return $x;
            }
        };
        $areas = new class implements AreaProvider {
            public function suburbIdsFor(User $u, string $t): array { return []; }
            public function suburbNamesNormalisedFor(User $u, string $t): array { return []; }
            public function suburbNamesLowerTrimFor(User $u, string $t): array { return []; }
            public function hasAreas(User $u, string $t): bool { return false; }
        };
        $followUps = new class($o['followUps'] ?? []) implements FollowUpSource {
            public function __construct(private array $f) {}
            public function dueOn(User $u, CarbonInterface $d, string $t): array { return $this->f[$u->id] ?? []; }
        };

        return new ListBuilderService(
            calendar: $calendar, areas: $areas, guard: new NullSuggestionGuard(), followUps: $followUps,
            settingsResolver: fn (User $u, string $t) => $settings,
        );
    }

    /** A property the canvass-pool exclusion for "our live stock" ignores (off the market and untouched for a month). */
    private function staleProperty(int $agentId, array $attrs = []): int
    {
        return $this->property($agentId, $attrs + ['updated_at' => now()->subDays(30), 'created_at' => now()->subDays(60)]);
    }

    private function pickNew(?User $user = null, array $settings = []): array
    {
        return $this->listingIds((new NewOnMarketSelection())->candidates($this->ctx($user, $settings), 50));
    }

    // ── A: another agent's stock, on every path ─────────────────────────

    public function test_an_unlinked_property_of_another_agent_found_by_portal_reference_is_never_offered(): void
    {
        // No matched_property_id, a status outside any "taken" list: only the reference ties them together.
        $this->staleProperty($this->mate->id, ['status' => 'withdrawn', 'p24_ref' => '555111']);
        $theirs = $this->listing(['portal_ref' => 'P24-555111']);
        $free = $this->listing();

        $this->assertSame([$free], $this->pickNew());
        $this->assertEqualsCanonicalizing([$theirs, $free], $this->pickNew($this->mate), 'it is still the owning agent\'s own to work');
    }

    public function test_an_unlinked_property_of_another_agent_found_by_address_is_never_offered_whatever_its_status(): void
    {
        foreach (['sold', 'let_out', 'withdrawn', 'expired', 'prospecting'] as $i => $status) {
            $addr = ($i + 1) . ' Beach Road';
            $this->staleProperty($this->mate->id, ['status' => $status, 'address' => $addr, 'suburb' => 'Margate']);
            $this->listing(['normalized_address' => ProspectingListing::normalizeAddress($addr, 'Margate'), 'address' => $addr]);
        }
        $free = $this->listing();

        $this->assertSame([$free], $this->pickNew());
    }

    public function test_the_other_agency_stock_status_is_not_another_agents_stock(): void
    {
        $this->property($this->mate->id, ['status' => 'other_agency_stock', 'p24_ref' => '555222']);

        $guard = new \App\Services\RoadToSold\Support\OtherAgentStock($this->agency->id, $this->agent->dataIdentityIds());

        $this->assertFalse($guard->ownedByAnother(['portal_ref' => 'P24-555222']), 'another agency\'s imported listing belongs to nobody here');
        $this->staleProperty($this->mate->id, ['status' => 'withdrawn', 'p24_ref' => '555223']);
        $this->assertTrue((new \App\Services\RoadToSold\Support\OtherAgentStock($this->agency->id, $this->agent->dataIdentityIds()))->ownedByAnother(['portal_ref' => 'P24-555223']));
    }

    public function test_the_twin_of_a_listing_that_is_another_agents_stock_is_not_offered_either(): void
    {
        $tp = $this->tp();
        $prop = $this->staleProperty($this->mate->id, ['status' => 'withdrawn']);
        $this->listing(['matched_property_id' => $prop], $tp);                         // the P24 advert, linked
        $this->listing(['portal_source' => 'pp', 'portal_ref' => 'PP-T1'], $tp);        // its PP twin, not linked

        $this->assertSame([], $this->pickNew());
    }

    public function test_a_follow_up_on_a_property_that_has_since_become_another_agents_is_not_put_on_the_list(): void
    {
        $tp = $this->tp();
        $prop = $this->staleProperty($this->mate->id, ['status' => 'withdrawn']);
        $listing = $this->listing(['matched_property_id' => $prop], $tp);
        $ok = $this->tp();
        $okListing = $this->listing([], $ok);

        $items = $this->builder([
            'target' => 5,
            'settings' => ['enabledSelectionKeys' => []],
            'followUps' => [$this->agent->id => [
                new FollowUp($tp->id, $listing, null, 'Follow-up: call back'),
                new FollowUp($ok->id, $okListing, null, 'Follow-up: call back'),
            ]],
        ])->buildFor($this->agent, self::WED);

        $this->assertSame([$ok->id], $items->pluck('tracked_property_id')->all());
    }

    public function test_add_to_my_day_never_adds_another_agents_stock(): void
    {
        $this->staleProperty($this->mate->id, ['status' => 'sold', 'p24_ref' => '555333']);
        $this->listing(['portal_ref' => 'P24-555333']);
        $free = $this->listing();

        $added = $this->builder(['settings' => ['enabledSelectionKeys' => ['new_on_market']]])->addToDay($this->agent, 'new_on_market', 10, self::WED);

        $this->assertSame([$free], $added->pluck('prospecting_listing_id')->all());
    }

    public function test_a_rolled_over_item_that_became_another_agents_stock_is_closed_as_missed_not_carried(): void
    {
        $prop = $this->staleProperty($this->mate->id, ['status' => 'withdrawn']);
        $tp = $this->tp();
        $listing = $this->listing(['matched_property_id' => $prop], $tp);
        $item = RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id,
            'list_date' => '2026-10-13', 'track' => 'sales', 'tracked_property_id' => $tp->id, 'prospecting_listing_id' => $listing,
            'selection' => 'new_on_market', 'origin' => 'auto', 'position' => 1, 'status' => 'open',
        ]));

        $r = $this->builder(['settings' => ['rollsOver' => true]])->closePastDays($this->agent, self::WED);

        $this->assertSame(['missed' => 1, 'rolled' => 0], $r);
        $this->assertSame('missed', $item->fresh()->status);
        $this->assertSame(0, RtsListItem::query()->where('origin', 'rollover')->count());
    }

    public function test_an_agents_own_stock_is_never_blocked_by_the_rule(): void
    {
        $own = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-09-01', 'address' => '9 Own Road']);
        // another agent has a property at the very same address (a duplicate record)
        $this->staleProperty($this->mate->id, ['status' => 'withdrawn', 'address' => '9 Own Road']);

        $c = (new MyExpiredStockSelection())->candidates($this->ctx(), 10);
        $this->assertSame([$own], array_map(fn ($x) => $x->propertyId, $c));

        $items = $this->builder(['settings' => ['enabledSelectionKeys' => ['my_expired_stock']]])->buildFor($this->agent, self::WED);
        $this->assertSame([$own], $items->pluck('property_id')->all());
    }

    // ── B: nothing fails quietly ─────────────────────────────────────────

    public function test_an_enabled_selection_with_no_implementation_is_logged_as_an_error(): void
    {
        Log::spy();
        $this->builder(['settings' => ['enabledSelectionKeys' => ['not_a_real_selection', 'new_on_market']]])->buildFor($this->agent, self::WED);

        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'no implementation'))->once();
    }

    public function test_the_builder_settings_come_from_the_settings_service_with_no_fallback(): void
    {
        DB::table('rts_settings')->insert([
            'agency_id' => $this->agency->id, 'track' => 'sales', 'owner_known_quantity' => 7, 'add_to_day_max' => 9, 'scan_page_size' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SettingsService::forget();

        $s = BuilderSettings::forUser($this->agent);
        $this->assertSame(7, $s->ownerKnownQuantity);
        $this->assertSame(9, $s->addToDayMax);
        $this->assertSame(50, $s->scanPageSize);
        $this->assertSame(['perfect', 'strong'], $s->buyerMatchTiers);
    }

    public function test_a_failed_build_on_the_page_shows_a_visible_message_instead_of_an_empty_page(): void
    {
        $this->app->bind(ListBuilderService::class, fn () => new class extends ListBuilderService {
            public function buildFor(User $user, CarbonInterface|string $date, string $track = 'sales'): \Illuminate\Support\Collection
            {
                throw new \RuntimeException('boom');
            }
        });
        Carbon::setTestNow('2026-10-14 09:00:00');

        $res = $this->actingAs($this->agent)->get(route('road-to-sold.index'));

        $res->assertOk();
        $res->assertSee('We could not build your call list just now');
        Carbon::setTestNow();
    }

    // ── C: owner known - per agent per day ───────────────────────────────

    public function test_owner_known_is_capped_per_day_across_builds_and_add_to_day(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $contactId = (int) \App\Models\Contact::withoutGlobalScopes()->create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
                'first_name' => 'O', 'last_name' => "N{$i}", 'phone' => '083' . random_int(1000000, 9999999),
            ])->id;
            $this->listing(self::OLD, $this->tp(['owner_contact_id' => $contactId]));
        }
        $b = $this->builder(['target' => 5, 'settings' => ['enabledSelectionKeys' => ['owner_known'], 'ownerKnownQuantity' => 2]]);

        $first = $b->buildFor($this->agent, self::WED);
        $this->assertCount(2, $first, 'the build stops at the agency quantity, not the target');

        Cache::flush();   // a later build the same day
        $this->assertCount(2, $b->buildFor($this->agent, self::WED));

        $added = $b->addToDay($this->agent, 'owner_known', 5, self::WED);
        $this->assertCount(0, $added, 'asking again cannot exceed the daily quantity');
        $this->assertSame(2, RtsListItem::query()->forDay($this->agent, self::WED)->where('selection', 'owner_known')->count());
    }

    // ── D: selections 8 and 9 ────────────────────────────────────────────

    public function test_expired_stock_also_takes_a_live_listing_whose_mandate_expiry_date_has_passed(): void
    {
        $lapsed = $this->property($this->agent->id, ['status' => 'active', 'expiry_date' => '2026-09-30']);
        $future = $this->property($this->agent->id, ['status' => 'active', 'expiry_date' => '2026-12-31']);
        $noDate = $this->property($this->agent->id, ['status' => 'active', 'expiry_date' => null]);
        $sold = $this->property($this->agent->id, ['status' => 'sold', 'expiry_date' => '2026-01-01']);
        $status = $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-10-01']);

        $c = (new MyExpiredStockSelection())->candidates($this->ctx(), 50);

        $this->assertEqualsCanonicalizing([$lapsed, $status], array_map(fn ($x) => $x->propertyId, $c));
        $reasons = collect($c)->keyBy('propertyId')->map->reason;
        $this->assertStringContainsString('still showing as live', $reasons[$lapsed]);
        $this->assertStringNotContainsString('still showing as live', $reasons[$status]);
    }

    // ── E: settings, not constants ───────────────────────────────────────

    public function test_buyer_match_levels_and_states_are_agency_settings(): void
    {
        $listing = $this->listing(self::OLD);
        $buyer = $this->buyer($this->agent->id, 'cold');
        $this->buyerMatch($listing, $buyer->id, 'approximate', 60);

        $sel = \App\Services\RoadToSold\Selections\BuyerSourcingSelection::mine();
        $this->assertSame([], $sel->candidates($this->ctx(), 10), 'standard: only perfect/strong, only new/warm buyers');
        $this->assertCount(1, $sel->candidates($this->ctx(settings: ['buyerMatchTiers' => ['approximate'], 'buyerActiveStates' => ['cold']]), 10));
    }

    public function test_hot_pocket_weights_change_the_ranking(): void
    {
        $a = $this->listing(self::OLD + ['suburb' => 'Alpha']);
        $b = $this->listing(self::OLD + ['suburb' => 'Beta']);
        // Alpha: 1 buyer. Beta: 1 own lead.
        $this->buyerMatch($a, $this->buyer($this->agent->id)->id);
        $prop = $this->property($this->agent->id, ['suburb' => 'Beta']);
        DB::table('portal_leads')->insert([
            'agency_id' => $this->agency->id, 'portal' => 'p24', 'lead_type' => 'Email', 'listing_id' => $prop, 'name' => 'Enquirer',
            'received_at' => '2026-10-10 10:00:00', 'lead_source_raw' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = new \App\Services\RoadToSold\HotPocketService();
        $asOf = Carbon::parse(self::WED);
        $equal = array_keys($svc->rank($this->agency->id, 'sales', $asOf, 180));
        $leadsHeavy = array_keys($svc->rank($this->agency->id, 'sales', $asOf, 180, (new BuilderSettings())->with(['hotPocketWeightLeads' => 5])));

        $this->assertSame('beta', $leadsHeavy[0]);
        $this->assertEqualsCanonicalizing(['alpha', 'beta'], $equal);
    }

    public function test_the_statuses_that_count_as_on_the_market_are_an_agency_setting(): void
    {
        $this->property($this->agent->id, [
            'status' => 'under_offer', 'listed_date' => now()->subDays(200)->toDateString(), 'created_at' => now()->subDays(205),
        ]);
        $sel = new \App\Services\RoadToSold\Selections\MyStockNotSellingSelection();

        $this->assertSame([], $sel->candidates($this->ctx(), 10));
        $this->assertCount(1, $sel->candidates($this->ctx(settings: ['onMarketStatuses' => ['active', 'under_offer']]), 10));
    }

    public function test_add_to_day_maximum_is_an_agency_setting(): void
    {
        $this->listing();
        $b = $this->builder(['settings' => ['enabledSelectionKeys' => ['new_on_market'], 'addToDayMax' => 3]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and 3');
        $b->addToDay($this->agent, 'new_on_market', 4, self::WED);
    }

    public function test_the_scan_keeps_reading_until_the_day_is_filled_or_the_pool_is_exhausted(): void
    {
        $listings = [];
        for ($i = 0; $i < 14; $i++) {
            $listings[] = $this->listing(['first_seen_at' => now()->subDays(1)->subMinutes($i)]);
        }
        $blockedTracked = array_map(fn ($id) => (int) DB::table('prospecting_listings')->where('id', $id)->value('tracked_property_id'), array_slice($listings, 0, 11));
        $guard = new class($blockedTracked) implements \App\Services\RoadToSold\Contracts\SuggestionGuard {
            public function __construct(private array $b) {}
            public function blockedFor(User $u, array $ids, string $t, ?\Carbon\CarbonInterface $on = null): array { return array_values(array_intersect($ids, $this->b)); }
            public function ownHeld(User $u, array $ids, string $t): array { return []; }
        };
        // a tiny first window: 2 rows, then 4, then 8 - the first 11 are blocked, so the 3 left live in the third window
        $sel = new class extends MicPoolSelection {
            public function key(): string { return 'probe'; }
            public function label(): string { return 'Probe'; }
            protected function firstPage(\App\Services\RoadToSold\Selections\SelectionContext $ctx): int { return 2; }
            protected function filter(Builder $q, \App\Services\RoadToSold\Selections\SelectionContext $ctx): void {}
            protected function order(Builder $q, \App\Services\RoadToSold\Selections\SelectionContext $ctx): void { $q->orderByDesc('pl.first_seen_at'); }
            protected function reason(object $row, \App\Services\RoadToSold\Selections\SelectionContext $ctx): string { return 'probe'; }
        };

        $c = $sel->candidates($this->ctx(guard: $guard), 3);

        $this->assertSame(array_slice($listings, 11, 3), $this->listingIds($c));
    }

    // ── F: the track ─────────────────────────────────────────────────────

    public function test_the_market_pool_is_filtered_by_track(): void
    {
        $sale = $this->listing();
        $rental = $this->listing(['listing_type' => 'rental']);

        $sales = (new NewOnMarketSelection())->candidates($this->ctx(track: 'sales'), 10);
        $rentals = (new NewOnMarketSelection())->candidates($this->ctx(track: 'rentals'), 10);

        $this->assertSame([$sale], $this->listingIds($sales));
        $this->assertSame([$rental], $this->listingIds($rentals));
    }

    // ── G: every row has a tracked property, and the database refuses duplicates ──

    public function test_a_listing_with_no_tracked_property_is_given_one_before_it_reaches_the_list(): void
    {
        $id = $this->listing(['address' => '4 Palm Road', 'suburb' => 'Margate'], null);
        $this->assertNull(DB::table('prospecting_listings')->where('id', $id)->value('tracked_property_id'));

        $items = $this->builder(['settings' => ['enabledSelectionKeys' => ['new_on_market']]])->buildFor($this->agent, self::WED);

        $this->assertCount(1, $items);
        $this->assertNotNull($items[0]->tracked_property_id);
        $this->assertSame($items[0]->tracked_property_id, (int) DB::table('prospecting_listings')->where('id', $id)->value('tracked_property_id'));
    }

    public function test_a_hold_or_do_not_contact_sees_a_listing_that_had_no_tracked_property(): void
    {
        $id = $this->listing(['address' => '5 Palm Road', 'suburb' => 'Margate'], null);
        $blockAll = new class implements \App\Services\RoadToSold\Contracts\SuggestionGuard {
            public function blockedFor(User $u, array $ids, string $t, ?\Carbon\CarbonInterface $on = null): array { return $ids; }
            public function ownHeld(User $u, array $ids, string $t): array { return []; }
        };

        $this->assertSame([], (new NewOnMarketSelection())->candidates($this->ctx(guard: $blockAll), 10));
        $this->assertNotNull(DB::table('prospecting_listings')->where('id', $id)->value('tracked_property_id'));
    }

    public function test_the_database_refuses_the_same_listing_twice_on_a_day_even_with_no_tracked_property(): void
    {
        $row = [
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-14', 'track' => 'sales',
            'tracked_property_id' => null, 'prospecting_listing_id' => 777, 'selection' => 'new_on_market', 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('rts_list_items')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('rts_list_items')->insert($row);
    }

    public function test_an_archived_duplicate_frees_the_key_and_a_different_day_or_agent_is_fine(): void
    {
        $row = [
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-14', 'track' => 'sales',
            'tracked_property_id' => null, 'prospecting_listing_id' => 778, 'selection' => 'new_on_market', 'created_at' => now(), 'updated_at' => now(),
        ];
        $id = DB::table('rts_list_items')->insertGetId($row);
        DB::table('rts_list_items')->where('id', $id)->update(['deleted_at' => now()]);

        DB::table('rts_list_items')->insert($row);                                                    // archived twin no longer holds the key
        DB::table('rts_list_items')->insert(['list_date' => '2026-10-15'] + $row);                    // another day
        DB::table('rts_list_items')->insert(['user_id' => $this->mate->id] + $row);                   // another agent

        $this->assertSame(4, DB::table('rts_list_items')->count());
    }

    // ── H: the day lookup uses the index ─────────────────────────────────

    public function test_for_day_is_a_range_on_the_date_column_not_a_function_of_it(): void
    {
        $sql = strtolower(RtsListItem::query()->forDay($this->agent, '2026-10-14 15:30:00')->toSql());

        $this->assertStringNotContainsString('date(', $sql);
        $this->assertStringContainsString('`rts_list_items`.`list_date` >=', $sql);
        $this->assertStringContainsString('`rts_list_items`.`list_date` <', $sql);

        RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-14', 'track' => 'sales',
            'prospecting_listing_id' => 1, 'tracked_property_id' => 1, 'selection' => 'x',
        ]));
        $this->assertSame(1, RtsListItem::query()->forDay($this->agent, '2026-10-14 15:30:00')->count());
        $this->assertSame(0, RtsListItem::query()->forDay($this->agent, '2026-10-15')->count());
    }
}
