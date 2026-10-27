<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsAgentArea;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsHold;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsPropertyFlag;
use App\Models\RoadToSold\RtsSetting;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\HoldService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\SummaryBarService;
use App\Services\RoadToSold\TargetService;
use App\Services\RoadToSold\WorkdayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\Feature\RoadToSold\Concerns\SeedsDerivedAreas;
use Tests\TestCase;

/**
 * AT-454 - ONE end-to-end scenario for Road to Sold (Johan's rules, .ai/specs/road-to-sold.md section 1).
 *
 * No stubs: the real nightly command, the real list builder, the real outcome / hold / workday / summary / report /
 * drill-down services, and the real HTTP routes (the page, "Contacted client", "Add to my day", the report, the drill-down).
 *
 * The world (October 2026): one agency, three agents (Anna and Ben share Uvongo, Cara has Manaba), Anna's assistant, a branch
 * manager and an admin, their own weekday targets, a public holiday on Wed 14 Oct and approved leave for Ben on Thu 15 Oct.
 * A second agency has its own agent, manager and stock.
 *
 *   Fri 09 Oct  Cara called two properties: one "No answer" (it is now hers for 30 days) and one "Do not contact".
 *   Mon 12 Oct  DAY 1 - nightly build, the pages, every one of the 8 seeded outcomes through the page route.
 *   Tue 13 Oct  DAY 2 - follow-ups due come back first and count; missed vs rolled over under both settings.
 *   Wed 14 Oct  public holiday   Thu 15 Oct  Ben on leave   Fri 16 Oct  the week's figures are checked.
 *
 * Every outcome captured through the route is written into $this->ledger (who, which outcome, which day, which list item) and the
 * summary bar and the report are checked against that LEDGER - not against the code under test.
 */
final class AgentWeekScenarioTest extends TestCase
{
    use RefreshDatabase, RtsFixtures, SeedsDerivedAreas;

    private const FRI_BEFORE = '2026-10-09';
    private const MON = '2026-10-12';
    private const TUE = '2026-10-13';
    private const WED = '2026-10-14';   // public holiday
    private const THU = '2026-10-15';   // Ben on approved leave
    private const FRI = '2026-10-16';

    /** weekday (ISO 1=Mon) => target, per person. Nobody has a weekend number. */
    private const WEEKS = [
        'anna' => [1 => 8, 2 => 6, 3 => 6, 4 => 6, 5 => 6],
        'ben'  => [1 => 4, 2 => 4, 3 => 4, 4 => 4, 5 => 4],
        'cara' => [1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3],
    ];

    private const KIND = [
        'no_answer' => 'attempt', 'wrong_number' => 'attempt',
        'spoke_evaluation_booked' => 'conversation', 'spoke_call_back' => 'conversation', 'spoke_not_interested' => 'conversation',
        'listed_other_agency_open' => 'conversation', 'mandated_other_agency_sole' => 'conversation', 'do_not_contact' => 'conversation',
    ];

    private User $anna;
    private User $ben;
    private User $cara;
    private User $asha;      // Anna's assistant
    private User $dan;       // another agent whose CoreX stock must never be offered to the others (he has no target, so no list)
    private User $mo;        // branch manager
    private User $adi;       // admin
    private int $leaveTypeId;

    /** @var array<string,TrackedProperty> keyed 'U01'.. 'R01'.. 'M01'.. 'H' (held), 'D' (do not contact), 'S' (another agent's stock) */
    private array $p = [];
    /** @var array<string,Contact> */
    private array $owner = [];
    /** @var array<int,array{user:int,key:string,kind:string,valuation:bool,dropped:bool,day:string,item:?int,origin:?string}> */
    private array $ledger = [];
    /** @var array<string,string> last outcome key per "tp" (to follow the no-answer run) */
    private array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();

        Carbon::setTestNow(self::FRI_BEFORE . ' 08:00:00');
        $this->agency = Agency::create(['name' => 'Scenario Realty ' . uniqid(), 'slug' => 'scn-' . uniqid()]);
        $this->agency->forceFill(['assistants_enabled' => true])->save();
        \App\Services\Prospecting\OnMarketStockService::flushCache();
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->anna = $this->person('Anna Agent', 'agent');
        $this->ben = $this->person('Ben Agent', 'agent');
        $this->cara = $this->person('Cara Agent', 'agent');
        $this->dan = $this->person('Dan Agent', 'agent');
        $this->asha = $this->person('Asha Assistant', 'assistant', ['is_assistant' => true]);
        $this->mo = $this->person('Mo Manager', 'branch_manager');
        $this->adi = $this->person('Adi Admin', 'admin');
        $assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->asha->id, 'agent_user_id' => $this->anna->id, 'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
        // What Anna has handed to her assistant (the assistant's permissions come only from this matrix, capped by Anna's own).
        foreach ([['access_road_to_sold', null], ['road_to_sold.view', 'own']] as [$key, $scope]) {
            AssistantAssignmentPermission::create([
                'agency_id' => $this->agency->id, 'assistant_assignment_id' => $assignment->id,
                'permission_key' => $key, 'granted' => true, 'scope' => $scope, 'is_locked' => false, 'is_new' => false,
            ]);
        }
        $this->asha = $this->asha->fresh();

        $this->grants($this->agency->id);
        $this->weekdayTargets();
        $this->calendar();
        $this->stock();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  DAY 1 - the nightly build, and the page for everyone who opens it
    // ═════════════════════════════════════════════════════════════════════════

    public function test_day_one_every_agents_list_meets_the_target_with_their_own_area_first_and_nobody_elses_stock(): void
    {
        $this->nightly(self::MON);

        $anna = $this->listOf($this->anna, self::MON);
        $ben = $this->listOf($this->ben, self::MON);
        $cara = $this->listOf($this->cara, self::MON);

        $this->assertCount(8, $anna, "Anna's Monday target is 8");
        $this->assertCount(4, $ben, "Ben's Monday target is 4");
        $this->assertCount(3, $cara, "Cara's Monday target is 3");

        // Own area first: Uvongo is Anna's and Ben's. The Ramsgate stock is OLDER, so without "areas come first" it would lead.
        $this->assertSame($this->ids('U01', 'U02', 'U03', 'U04', 'U05', 'U06', 'U07', 'U08'), $anna->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all(), 'Anna: her own suburb, longest on the market first');
        $this->assertSame($this->ids('B01', 'B02', 'B03', 'B04'), $ben->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all(), 'Ben: his own suburb, in the agency order');
        // Cara has only two properties in Manaba: the day carries on into the agency's other suburbs.
        $this->assertSame($this->ids('M01', 'M02', 'R01'), $cara->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all(), 'Cara: Manaba first, then the rest of the agency');

        // Never suggested: held by another agent (H), do-not-contact (D), another agent's CoreX stock (S) - for ANY agent.
        $never = $this->ids('H', 'D', 'S');
        foreach ([$anna, $ben, $cara] as $list) {
            $this->assertSame([], array_values(array_intersect($never, $list->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all())), 'no held / do-not-contact / other-agent property on a list');
        }

        // Day-one rows are plain new suggestions, each with a reason, in order, once each.
        foreach ([$anna, $ben, $cara] as $list) {
            $this->assertSame(['auto'], $list->pluck('origin')->unique()->values()->all());
            $this->assertSame(['open'], $list->pluck('status')->unique()->values()->all());
            $this->assertSame(range(1, $list->count()), $list->pluck('position')->map(fn ($v) => (int) $v)->all());
            $this->assertCount($list->count(), $list->pluck('tracked_property_id')->unique());
            $this->assertNotContains('', $list->pluck('reason')->all());
        }

        // Nobody who has no target (the assistant, the manager, the admin) gets a list of their own; the assistant works Anna's.
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->whereIn('user_id', [$this->asha->id, $this->mo->id, $this->adi->id, $this->dan->id])->count());

        // Running the night again adds nothing.
        $this->nightly(self::MON);
        $this->assertSame(15, RtsListItem::withoutGlobalScopes()->where('list_date', self::MON)->count());
    }

    public function test_day_one_the_page_renders_for_the_agent_the_assistant_and_the_manager(): void
    {
        $this->nightly(self::MON);
        $this->at(self::MON . ' 08:30:00');
        $shown = ['Uvongo Ave 01', 'Uvongo Ave 02', 'Uvongo Ave 03', 'Uvongo Ave 04', 'Uvongo Ave 05', 'Uvongo Ave 06', 'Uvongo Ave 07', 'Uvongo Ave 08'];
        $hidden = ['Uvongo Ave 09', 'Held Ave', 'Never Ave', 'Stock Ave', 'Ramsgate Ave', 'Manaba Ave'];

        // The agent: eight rows, target 0 of 8, a button on every row.
        $res = $this->actingAs($this->anna)->get(route('road-to-sold.index'))->assertOk();
        foreach ($shown as $s) {
            $res->assertSee($s);
        }
        foreach ($hidden as $s) {
            $res->assertDontSee($s);
        }
        $res->assertSee('0 of 8');
        $this->assertSame(8, substr_count($res->getContent(), 'data-tp='));

        // The assistant sees the AGENT's list - the same eight - and can work it.
        $asst = $this->actingAs($this->asha)->get(route('road-to-sold.index'))->assertOk();
        foreach ($shown as $s) {
            $asst->assertSee($s);
        }
        $asst->assertSee('0 of 8');
        $this->assertSame(8, substr_count($asst->getContent(), 'data-tp='));

        // The branch manager opens Anna's list read-only (no "Contacted client" buttons), and the other agents' lists.
        $bm = $this->actingAs($this->mo)->get(route('road-to-sold.index', ['agent' => $this->anna->id]))->assertOk();
        foreach ($shown as $s) {
            $bm->assertSee($s);
        }
        $this->assertSame(0, substr_count($bm->getContent(), 'data-tp='));
        $this->actingAs($this->mo)->get(route('road-to-sold.index', ['agent' => $this->cara->id]))->assertOk()
            ->assertSee('Manaba Ave 01')->assertSee('Ramsgate Ave 01');
        // The manager's own page is empty: no target, no list.
        $this->actingAs($this->mo)->get(route('road-to-sold.index'))->assertOk()->assertSee('No list for today.');

        // An agent cannot open a colleague's list by id; the admin can open anyone's.
        $this->actingAs($this->anna)->get(route('road-to-sold.index', ['agent' => $this->ben->id]))->assertNotFound();
        $this->actingAs($this->adi)->get(route('road-to-sold.index', ['agent' => $this->ben->id]))->assertOk()->assertSee('Central Ave 01');
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  DAY 1 - all eight seeded outcomes, through the page route
    // ═════════════════════════════════════════════════════════════════════════

    public function test_day_one_every_seeded_outcome_is_captured_through_the_page_route_with_the_right_effects(): void
    {
        $this->mondayWork();

        // The call log holds exactly what was captured (the two Friday calls by Cara, then Monday's).
        $this->assertSame(count($this->ledger), RtsContactOutcome::withoutGlobalScopes()->count());
        $keys = collect($this->ledger)->pluck('key')->unique()->sort()->values()->all();
        $this->assertSame(array_keys(self::KIND), array_values(array_filter(array_keys(self::KIND), fn ($k) => in_array($k, $keys, true))), 'all eight seeded outcomes were captured');

        $row = fn (int $tp, string $key) => RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $tp)->where('outcome_key', $key)->orderByDesc('id')->firstOrFail();

        // 1. No answer (attempt, +7 days) - a fresh property for Cara.
        $m1 = $row($this->tpid('M01'), 'no_answer');
        $this->assertSame('attempt', $m1->kind);
        $this->assertSame('2026-10-19', $m1->next_action_at->toDateString());
        $this->assertNull($this->owner['M01']->fresh()?->last_contacted_at);

        // 2. Spoke - evaluation booked: a calendar appointment is created and the property leaves the list.
        $val = $row($this->tpid('B02'), 'spoke_evaluation_booked');
        $this->assertTrue((bool) $val->valuation_booked);
        $this->assertSame('calendar_appointment', $val->next_action);
        $event = DB::table('calendar_events')->where('id', $val->calendar_event_id)->first();
        $this->assertNotNull($event, 'the evaluation is in the calendar');
        $this->assertSame('property_evaluation', $event->category);
        $this->assertSame('2026-10-20 10:00:00', (string) $event->event_date);
        $this->assertStringStartsWith('Evaluation: ', $event->title, 'the appointment title says evaluation');
        $this->assertSame('Spoke - evaluation booked', $val->outcome_label);
        $this->assertSame($this->ben->id, (int) $event->user_id);

        // 3-6. The conversation outcomes that come back: their dates, and the owner's "last contacted" moved.
        $this->assertSame('2026-10-13', $row($this->tpid('B03'), 'spoke_call_back')->next_action_at->toDateString());
        $this->assertSame('2027-04-12', $row($this->tpid('B04'), 'spoke_not_interested')->next_action_at->toDateString(), '"Not now, maybe later" follows up in 6 months by default (road-to-sold.md 22.7)');
        $this->assertSame('2027-01-10', $row($this->tpid('U05'), 'listed_other_agency_open')->next_action_at->toDateString(), 'no mandate end date is known, so the standard 90 days (road-to-sold.md 22.7)');
        $this->assertSame('2026-10-30', $row($this->tpid('U06'), 'mandated_other_agency_sole')->next_action_at->toDateString(), 'no expiry is known for another agency\'s mandate, so the agent\'s date is used');
        foreach (['B03', 'B04', 'U05', 'U06'] as $k) {
            $this->assertNotNull($this->owner[$k]->fresh()->last_contacted_at, "a conversation moves {$k}'s owner's last contacted");
        }

        // 7. Wrong number (attempt, tagged): made by the assistant, recorded as Anna's, owner's last contacted NOT moved.
        $wrong = $row($this->tpid('U07'), 'wrong_number');
        $this->assertSame('Find the right contact', $wrong->list_tag);
        $this->assertSame($this->anna->id, (int) $wrong->user_id, 'the assistant\'s call is the agent\'s figure');
        $this->assertSame($this->asha->id, (int) $wrong->recorded_by_user_id);
        $this->assertNull($this->owner['U07']->fresh()->last_contacted_at, 'an attempt does not move last contacted');
        $this->assertSame($this->owner['U07']->id, (int) $wrong->contact_id, 'RULE: every outcome is history on the owner contact - also when the assistant made the call');
        $this->assertStringContainsString('call made by Asha Assistant', (string) ContactNote::withoutGlobalScopes()->where('contact_id', $this->owner['U07']->id)->value('body'));

        // 8. Do not contact: never again for any agent, the owner's call opt-out is set, and no hold is created.
        $this->assertTrue((bool) $this->owner['U08']->fresh()->opt_out_call);
        $this->assertNotNull(RtsPropertyFlag::withoutGlobalScopes()->where('tracked_property_id', $this->tpid('U08'))->where('flag', RtsPropertyFlag::NEVER_SUGGEST)->first());
        $this->assertNull(RtsHold::withoutGlobalScopes()->where('tracked_property_id', $this->tpid('U08'))->first(), 'do not contact gives nobody a hold');
        $this->assertTrue((bool) $this->owner['D']->fresh()->opt_out_call, 'Cara\'s Friday do-not-contact did the same');

        // HOLD: every other captured outcome gave that agent the property for 30 days (an agency setting).
        $hold = RtsHold::withoutGlobalScopes()->where('tracked_property_id', $this->tpid('B03'))->firstOrFail();
        $this->assertSame($this->ben->id, (int) $hold->user_id);
        $this->assertSame('2026-11-11', $hold->expires_at->toDateString());

        // 3 no-answers in a row: dropped, and the capture said so; it is on the manager's report.
        $b01 = RtsContactOutcome::withoutGlobalScopes()->where('tracked_property_id', $this->tpid('B01'))->orderBy('id')->get();
        $this->assertSame([1, 2, 3], $b01->pluck('no_answer_streak')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([false, false, true], $b01->pluck('dropped')->map(fn ($v) => (bool) $v)->all());
        $drops = $this->actingAs($this->mo)->getJson(route('road-to-sold.drilldown', ['metric' => 'dropped', 'period' => 'today']))->assertOk();
        $this->assertSame(1, $drops->json('total'));
        $this->assertStringContainsString('Central Ave 01', $drops->json('rows.0.property'));
        $this->assertSame('Ben Agent', $drops->json('rows.0.agent'));
        $this->actingAs($this->mo)->get(route('road-to-sold.report', ['period' => 'today']))->assertOk()->assertSee('Dropped after no-answers');

        // The "No answer" list finds it again for when the owner phones back; the wrong-number list holds the tagged one.
        $this->actingAs($this->ben)->get(route('road-to-sold.index', ['view' => 'no_answer']))->assertOk()->assertSee('Central Ave 01')->assertDontSee('Central Ave 03');
        $this->actingAs($this->anna)->get(route('road-to-sold.index', ['view' => 'wrong_number']))->assertOk()->assertSee('Uvongo Ave 07')->assertSee('Find the right contact')->assertDontSee('Uvongo Ave 05');
        $this->actingAs($this->asha)->get(route('road-to-sold.index', ['view' => 'wrong_number']))->assertOk()->assertSee('Uvongo Ave 07');
    }

    public function test_day_one_a_property_another_agent_holds_cannot_be_called_by_anyone_else_but_do_not_contact_always_can(): void
    {
        $this->mondayWork();
        $this->at(self::MON . ' 15:00:00');

        // Ben holds B01-B04 (until 11 Nov); Cara holds H (until 8 Nov). Anna is refused, in plain words, and nothing is saved.
        $before = RtsContactOutcome::withoutGlobalScopes()->count();
        $this->actingAs($this->anna)->post(route('road-to-sold.outcomes.record'), ['tracked_property_id' => $this->tpid('B01'), 'outcome_definition_id' => $this->def('no_answer')->id])
            ->assertSessionHas('rts_error', fn ($m) => str_contains($m, 'Ben Agent is working this property until 11 Nov 2026'));
        $this->actingAs($this->anna)->post(route('road-to-sold.outcomes.record'), ['tracked_property_id' => $this->tpid('H'), 'outcome_definition_id' => $this->def('no_answer')->id])
            ->assertSessionHas('rts_error', fn ($m) => str_contains($m, 'Cara Agent is working this property until 8 Nov 2026'));
        $this->assertSame($before, RtsContactOutcome::withoutGlobalScopes()->count());

        // Another agent's hold does not stop a do-not-contact request: the owner must never be called again.
        $this->actingAs($this->anna)->post(route('road-to-sold.outcomes.record'), ['tracked_property_id' => $this->tpid('B03'), 'outcome_definition_id' => $this->def('do_not_contact')->id])
            ->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertTrue((bool) $this->owner['B03']->fresh()->opt_out_call);
    }

    public function test_day_one_add_to_my_day_is_extra_and_reported_apart(): void
    {
        $this->mondayWork();

        $added = RtsListItem::withoutGlobalScopes()->where('user_id', $this->anna->id)->where('list_date', self::MON)->where('origin', 'added')->get();
        $this->assertEqualsCanonicalizing($this->ids('U09', 'U10'), $added->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all(), 'two more from the same selection, never the held / taken ones');
        $this->assertSame(8, RtsListItem::withoutGlobalScopes()->where('user_id', $this->anna->id)->where('list_date', self::MON)->where('origin', 'auto')->count(), 'the target list is untouched');

        // U09 was called (over and above the target): it shows as "Extra over target", not as "Worked".
        $this->at(self::MON . ' 18:00:00');
        $rep = $this->actingAs($this->adi)->get(route('road-to-sold.report', ['period' => 'today']))->assertOk()->viewData('rows');
        $anna = collect($rep)->firstWhere('agent_id', $this->anna->id);
        $this->assertSame(4, $anna['cells']['worked']['value'], 'U05-U08 worked toward the target of 8');
        $this->assertSame(1, $anna['cells']['added_worked']['value']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  DAY 2 - follow-ups, missed items under both settings
    // ═════════════════════════════════════════════════════════════════════════

    public function test_day_two_follow_ups_come_back_first_and_count_toward_the_target_and_missed_items_are_shown_as_missed(): void
    {
        $this->mondayWork();
        $this->nightly(self::TUE);

        $ben = $this->listOf($this->ben, self::TUE);
        $anna = $this->listOf($this->anna, self::TUE);
        $cara = $this->listOf($this->cara, self::TUE);

        // Ben: B03 was "call back on 13 Oct" - it leads, is counted apart (road-to-sold.md 22.3), and his four NEW properties follow.
        $this->assertCount(5, $ben);
        $first = $ben->first();
        $this->assertSame($this->tpid('B03'), (int) $first->tracked_property_id);
        $this->assertSame('follow_up', $first->origin);
        $this->assertStringContainsString('Follow-up: Spoke - call back, due 13 Oct', $first->reason);
        $this->assertSame(['follow_up', 'auto', 'auto', 'auto', 'auto'], $ben->pluck('origin')->all());
        // ...and nothing Ben has parked, dropped or booked comes back as "new": B01 dropped, B02 evaluation, B04 back on 20 Oct.
        $this->assertSame([], array_values(array_intersect($this->ids('B01', 'B02', 'B04'), $ben->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all())));

        // Anna: 6 on Tuesday - her own U01-U04 she never reached are offered to her again (and to nobody else), then U10-U11;
        // never Ben's, never hers-and-parked, never do-not-contact, never another agent's stock.
        $this->assertCount(6, $anna);
        $this->assertSame($this->ids('U01', 'U02', 'U03', 'U04', 'U10', 'U11'), $anna->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['auto'], $anna->pluck('origin')->unique()->values()->all());

        // Cara: M01 was "no answer, try again in 7 days" - parked until 19 Oct, so the day is M02, R01, R02.
        $this->assertSame($this->ids('M02', 'R01', 'R02'), $cara->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all());

        // The held, do-not-contact and other-agent properties are on nobody's list.
        $all = collect([$ben, $anna, $cara])->flatten(1)->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
        foreach (['H', 'D', 'S', 'U08'] as $k) {
            $this->assertNotContains($this->tpid($k), $all, "{$k} is on nobody's list");
        }
        $this->assertNotContains($this->tpid('B01'), $this->listIds($this->anna, self::TUE), 'Ben holds B01 - not offered to Anna');

        // MISSED (the agency default): Monday's open items are closed as missed - and nothing is carried.
        $monAnna = RtsListItem::withoutGlobalScopes()->where('user_id', $this->anna->id)->where('list_date', self::MON)->get()->groupBy('status')->map->count()->all();
        $this->assertEquals(['worked' => 5, 'missed' => 5], $monAnna, '4 + the added U09 worked; U01-U04 and the added U10 never reached');
        $monBen = RtsListItem::withoutGlobalScopes()->where('user_id', $this->ben->id)->where('list_date', self::MON)->pluck('status')->unique()->all();
        $this->assertSame(['worked'], $monBen);
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->where('origin', 'rollover')->count());

        // The page shows Tuesday's follow-up on top; counted in the day's "of 4".
        $this->at(self::TUE . ' 08:30:00');
        $html = $this->actingAs($this->ben)->get(route('road-to-sold.index'))->assertOk()->assertSee('0 of 4')->getContent();
        $this->assertLessThan(strpos($html, 'Central Ave 05'), strpos($html, 'Central Ave 03'), 'the follow-up leads the list');
        $this->actingAs($this->ben)->get(route('road-to-sold.index', ['date' => self::MON, 'status' => 'all']))->assertOk()->assertSee('Central Ave 04');
        $missed = $this->actingAs($this->mo)->getJson(route('road-to-sold.drilldown', ['metric' => 'missed', 'period' => 'custom', 'start' => self::MON, 'end' => self::MON]))->assertOk();
        $this->assertSame(4 + 2, $missed->json('total'), "Anna's U01-U04 and Cara's M02 and R01 (the extra U10 is not part of the target)");
    }

    public function test_day_two_with_roll_over_switched_on_missed_items_move_to_the_next_working_day_but_never_to_a_property_someone_else_now_holds(): void
    {
        RtsSetting::create(['agency_id' => $this->agency->id, 'track' => 'sales', 'missed_items_mode' => 'roll_over']);
        SettingsService::forget();
        $this->mondayWork();
        $this->nightly(self::TUE);

        // Cara's missed M02 and R01 are free: they roll to Tuesday, count toward her 3, and one new property fills the day.
        $cara = $this->listOf($this->cara, self::TUE);
        $this->assertCount(3, $cara);
        $this->assertEqualsCanonicalizing($this->ids('M02', 'R01'), $cara->where('origin', 'rollover')->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['rolled'], RtsListItem::withoutGlobalScopes()->where('user_id', $this->cara->id)->where('list_date', self::MON)->whereIn('tracked_property_id', $this->ids('M02', 'R01'))->pluck('status')->unique()->all());

        // Ben's B01-B04 are held and owned by Ben. Nothing of his may ever turn up on Anna's list, rolled over or not.
        $anna = $this->listOf($this->anna, self::TUE);
        $rolled = $anna->where('origin', 'rollover')->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([], array_values(array_intersect($rolled, $this->ids('B01', 'B02', 'B03', 'B04'))), 'RULE: never suggest a property another agent holds - not even as a rolled-over item');
        $this->assertCount(6, $anna, 'the day still meets the target');
        foreach ($anna as $item) {
            $this->assertFalse(app(HoldService::class)->isHeldByOther((int) $item->tracked_property_id, $this->anna), 'nothing on Anna\'s list is held by someone else');
        }

        // The extra Anna asked for is never carried over.
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->where('user_id', $this->anna->id)->where('origin', 'rollover')->whereIn('tracked_property_id', $this->ids('U10'))->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Holiday and leave carry no target
    // ═════════════════════════════════════════════════════════════════════════

    public function test_a_public_holiday_and_approved_leave_carry_no_target_and_no_list(): void
    {
        $this->mondayWork();
        $this->nightly(self::TUE);
        $wd = app(WorkdayService::class);

        // Wednesday 14 Oct is a public holiday: no target for anybody, no list built.
        foreach ([$this->anna, $this->ben, $this->cara] as $u) {
            $this->assertSame(0, $wd->targetFor($u, self::WED));
            $this->assertFalse($wd->isWorkday($u, self::WED));
        }
        $this->nightly(self::WED);
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->where('list_date', self::WED)->count());
        $this->at(self::WED . ' 09:00:00');
        $this->actingAs($this->anna)->get(route('road-to-sold.index'))->assertOk()->assertSee('No list for today.');

        // Thursday 15 Oct: Ben has approved leave. Anna and Cara work; Ben has no target and no list; Cara's pending leave on Friday changes nothing.
        $this->assertSame(0, $wd->targetFor($this->ben, self::THU));
        $this->assertSame(6, $wd->targetFor($this->anna, self::THU));
        $this->assertSame(3, $wd->targetFor($this->cara, self::FRI), 'leave that is not approved yet carries the full target');
        $this->nightly(self::THU);
        $this->assertCount(0, $this->listOf($this->ben, self::THU));
        $this->assertCount(7, $this->listOf($this->anna, self::THU), '6 new properties, plus the wrong number Asha called on Monday: find the owner again after 3 days, counted apart');
        $this->assertCount(3, $this->listOf($this->cara, self::THU));
        $this->at(self::THU . ' 09:00:00');
        $this->actingAs($this->ben)->get(route('road-to-sold.index'))->assertOk()->assertSee('No list for today.');

        // The month's "should be by today" skips both days: Anna Thu 15 = Thu1+Fri2 + 5 days of Oct 5-9 + 12-13 + 15.
        $this->assertSame($this->expectedMonthToDate('anna', self::THU), $wd->monthTargetToDate($this->anna, self::THU));
        $this->assertSame($this->expectedMonthToDate('ben', self::THU), $wd->monthTargetToDate($this->ben, self::THU));
        $this->assertSame($this->expectedMonthToDate('ben', self::FRI) , $wd->monthTargetToDate($this->ben, self::FRI), 'the leave day stays out of the month so far');
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  The week's figures: summary bar, report, drill-down, scope
    // ═════════════════════════════════════════════════════════════════════════

    public function test_the_report_has_no_row_for_the_assistant_so_the_agents_target_is_not_counted_twice(): void
    {
        $this->mondayWork();
        $this->at(self::MON . ' 18:00:00');

        foreach (['admin' => $this->adi, 'manager' => $this->mo] as $who => $viewer) {
            $res = $this->actingAs($viewer)->get(route('road-to-sold.report', ['period' => 'today']))->assertOk();
            $this->assertNotContains($this->asha->id, collect($res->viewData('rows'))->pluck('agent_id')->all(), "{$who}: an assistant has no figures of their own - their work is the agent's");
            $this->assertSame(8 + 4 + 3, $res->viewData('totals')['cells']['target']['value'], "{$who}: Monday's targets are Anna 8 + Ben 4 + Cara 3 - the assistant adds nothing");
        }
    }

    public function test_the_target_in_the_report_equals_the_target_rows_behind_it_for_everyone_who_can_open_it(): void
    {
        $this->at(self::MON . ' 12:00:00');

        foreach (['manager' => $this->mo, 'admin' => $this->adi, 'agent' => $this->anna] as $who => $viewer) {
            $res = $this->actingAs($viewer)->get(route('road-to-sold.report', ['period' => 'today']))->assertOk();
            $targets = collect($res->viewData('rows'))->mapWithKeys(fn ($r) => [$r['name'] => $r['cells']['target']['value']])->all();
            $drill = $this->actingAs($viewer)->getJson(route('road-to-sold.drilldown', ['metric' => 'target', 'period' => 'today']))->assertOk();
            $this->assertSame(
                $res->viewData('totals')['cells']['target']['value'], $drill->json('total'),
                "{$who}: the Target figure and the rows behind it must agree - report " . json_encode($targets) . ' drill ' . json_encode(array_map(fn ($r) => [$r['agent'], $r['target']], $drill->json('rows')))
            );
        }
    }

    public function test_the_summary_bar_and_the_report_equal_what_was_captured_and_every_figure_equals_its_drill_down_rows(): void
    {
        $this->theWeek();
        $this->at(self::FRI . ' 17:00:00');
        $this->assertSame(self::FRI, app(\App\Services\Performance\PeriodResolver::class)->resolve('today')->start->toDateString(), 'the report\'s "today" is the test day');

        // ── the summary bar: month so far, per agent, against the ledger ─────────────
        foreach (['anna' => $this->anna, 'ben' => $this->ben, 'cara' => $this->cara] as $name => $agent) {
            $bar = collect(app(SummaryBarService::class)->forAgent($agent, self::FRI)['steps'])->keyBy('key');
            $mine = $this->entries($agent->id, '2026-10-01', self::FRI);

            $this->assertSame(count($mine), $bar['calls_made']['actual'], "{$name}: calls made = outcomes captured");
            $this->assertSame(count(array_filter($mine, fn ($e) => $e['kind'] === 'conversation')), $bar['conversations']['actual'], "{$name}: conversations");
            $this->assertSame(count(array_filter($mine, fn ($e) => $e['valuation'])), $bar['evaluations_booked']['actual'], "{$name}: evaluations booked");
            $this->assertSame($this->expectedMonthToDate($name, self::FRI), $bar['calls_made']['should_be'], "{$name}: should-be-by-today skips the holiday and leave");
            $this->assertNull($bar['conversations']['should_be'], 'conversations have no monthly target (Q9): actual only');
            $this->assertSame(0, $bar['evaluations_done']['actual']);
            $this->assertSame(0, $bar['mandates_signed']['actual']);
            $this->assertSame(0, $bar['listings_live']['actual']);

            // the same figures on the page the agent actually sees (the agent opens their own, the admin opens the rest)
            $page = $this->visible($this->actingAs($agent)->get(route('road-to-sold.index'))->assertOk()->getContent());
            $this->assertMatchesRegularExpression('/Calls made\s+' . count($mine) . '\s+of\s+' . $bar['calls_made']['should_be'] . '\b/', $page, "{$name}: the page shows the same calls made");
            $this->assertMatchesRegularExpression('/Conversations\s+' . count(array_filter($mine, fn ($e) => $e['kind'] === 'conversation')) . '\b/', $page);
            $this->assertMatchesRegularExpression('/Evaluations booked\s+' . count(array_filter($mine, fn ($e) => $e['valuation'])) . '\b/', $page);
            $this->assertDoesNotMatchRegularExpression(self::OLD_WORD, $page, "{$name}: the list page never says the old word");

            // click a step -> the rows behind it: the drill-down total is the figure
            foreach (['calls_made', 'conversations', 'evaluations_booked'] as $step) {
                $drill = $this->actingAs($agent)->getJson(route('road-to-sold.drilldown', ['metric' => $step, 'agent_id' => $agent->id, 'period' => 'custom', 'start' => '2026-10-01', 'end' => self::FRI]))->assertOk();
                $this->assertSame($bar[$step]['actual'], $drill->json('total'), "{$name}: {$step} drill");
                $this->assertDoesNotMatchRegularExpression(self::OLD_WORD, json_encode($drill->json()), "{$name}: {$step} drill never says the old word");
                $this->assertCount($bar[$step]['actual'], $drill->json('rows'));
            }
        }

        // ── the report: today / last 7 days / this month for the agent, the branch manager and the admin ──
        $periods = [
            'today'       => [self::FRI, self::FRI],
            'last_7_days' => ['2026-10-10', self::FRI],
            'this_month'  => ['2026-10-01', '2026-10-31'],
        ];
        $people = ['anna' => $this->anna, 'ben' => $this->ben, 'cara' => $this->cara];
        foreach (['agent' => $this->anna, 'manager' => $this->mo, 'admin' => $this->adi] as $who => $viewer) {
            foreach ($periods as $preset => [$from, $to]) {
                $res = $this->actingAs($viewer)->get(route('road-to-sold.report', ['period' => $preset]))->assertOk();
                $this->assertDoesNotMatchRegularExpression(self::OLD_WORD, $this->visible($res->getContent()), "{$who}/{$preset}: the report never says the old word");
                $rows = collect($res->viewData('rows'));
                $totals = $res->viewData('totals');

                // who is on the page: an agent only themselves, a manager the branch, the admin the agency
                if ($who === 'agent') {
                    $this->assertSame([$this->anna->id], $rows->pluck('agent_id')->all());
                } else {
                    foreach ($people as $p) {
                        $this->assertContains($p->id, $rows->pluck('agent_id')->all(), "{$who} sees {$p->name}");
                    }
                }

                $columns = array_column($res->viewData('columns'), 'key');
                foreach ($rows as $row) {
                    $mine = $this->entries($row['agent_id'], $from, $to);
                    $isAgent = in_array($row['agent_id'], array_map(fn ($u) => $u->id, $people), true);
                    $c = fn (string $k) => $row['cells'][$k]['value'];

                    if ($isAgent) {
                        $this->assertSame(count(array_filter($mine, fn ($e) => $e['kind'] === 'attempt')), $c('attempts'), "{$who}/{$preset}/{$row['name']}: attempts");
                        $this->assertSame(count(array_filter($mine, fn ($e) => $e['kind'] === 'conversation')), $c('conversations'), "{$who}/{$preset}/{$row['name']}: conversations");
                        $this->assertSame(count(array_filter($mine, fn ($e) => $e['valuation'])), $c('evaluations_booked'), "{$who}/{$preset}/{$row['name']}: evaluations booked");
                        $this->assertSame(count(array_filter($mine, fn ($e) => $e['dropped'])), $c('dropped'), "{$who}/{$preset}/{$row['name']}: dropped after no-answers");
                        foreach (array_keys(self::KIND) as $key) {
                            $this->assertSame(count(array_filter($mine, fn ($e) => $e['key'] === $key)), $c('outcome:' . $key), "{$who}/{$preset}/{$row['name']}: outcome {$key}");
                        }
                        $this->assertSame($this->expectedTarget(array_search($row['agent_id'], array_map(fn ($u) => $u->id, $people), true), $from, $to), $c('target'), "{$who}/{$preset}/{$row['name']}: target skips holiday and leave");
                        $worked = collect($mine)->filter(fn ($e) => $e['item'] && ! in_array($e['origin'], ['added', 'follow_up'], true))->pluck('item')->unique()->count();
                        $extra = collect($mine)->filter(fn ($e) => $e['item'] && $e['origin'] === 'added')->pluck('item')->unique()->count();
                        $this->assertSame($worked, $c('worked'), "{$who}/{$preset}/{$row['name']}: worked toward the target");
                        $this->assertSame($extra, $c('added_worked'), "{$who}/{$preset}/{$row['name']}: extra over target");
                    }
                }

                // the Total row is the sum of the rows
                foreach ($columns as $k) {
                    $this->assertSame($rows->sum(fn ($r) => $r['cells'][$k]['value']), $totals['cells'][$k]['value'], "{$who}/{$preset}: total {$k}");
                }

                // every figure = the rows behind it: the Total row (the whole cohort the viewer sees) and, for the admin, every agent row too
                foreach ($columns as $k) {
                    if ($k === 'target') {
                        continue;   // report vs drill-down targets are pinned on their own below (an assistant row makes them differ)
                    }
                    $q = ['metric' => $k, 'period' => $preset];
                    $drill = $this->actingAs($viewer)->getJson(route('road-to-sold.drilldown', $q))->assertOk();
                    $this->assertSame($totals['cells'][$k]['value'], $drill->json('total'), "{$who}/{$preset}: drill of total {$k} - report rows " . json_encode($rows->mapWithKeys(fn ($r) => [$r['name'] => $r['cells'][$k]['value']])->all()) . ' drill rows ' . json_encode(array_map(fn ($r) => [$r['agent'] ?? null, $r['target'] ?? null, $r['when'] ?? null], $drill->json('rows') ?? [])));
                    if ($who === 'admin' && $preset !== 'today') {
                        foreach ($rows as $row) {
                            $this->assertSame($row['cells'][$k]['value'], $this->actingAs($viewer)->getJson(route('road-to-sold.drilldown', $q + ['agent_id' => $row['agent_id']]))->assertOk()->json('total'), "{$who}/{$preset}: drill of {$row['name']} {$k}");
                        }
                    }
                }
            }
        }

        // an agent cannot drill a colleague by id; the manager and admin can
        $q = ['metric' => 'attempts', 'period' => 'this_month', 'agent_id' => $this->ben->id];
        $this->actingAs($this->anna)->getJson(route('road-to-sold.drilldown', $q))->assertNotFound();
        $this->actingAs($this->mo)->getJson(route('road-to-sold.drilldown', $q))->assertOk();

        // print, PDF and XLSX are the same figures
        $this->actingAs($this->mo)->get(route('road-to-sold.report.print', ['period' => 'this_month']))->assertOk()->assertSee('Ben Agent')->assertSee('Cara Agent');
        $this->actingAs($this->adi)->get(route('road-to-sold.report.pdf', ['period' => 'this_month']))->assertOk();
        $this->actingAs($this->adi)->get(route('road-to-sold.report.xlsx', ['period' => 'this_month']))->assertOk();
    }

    public function test_a_second_agency_sees_nothing_of_the_first_and_the_first_nothing_of_the_second(): void
    {
        // Agency B: its own people, its own stock, its own work (set up before anyone is logged in, so nothing is stamped with agency A).
        $b = Agency::create(['name' => 'Other Realty ' . uniqid(), 'slug' => 'oth-' . uniqid()]);
        $bBranch = Branch::create(['agency_id' => $b->id, 'name' => 'Main']);
        $bAgent = $this->person('Bea Other', 'agent', [], $b, $bBranch);
        $bMgr = $this->person('Bob Other', 'branch_manager', [], $b, $bBranch);
        $this->grants($b->id);
        app(OutcomeDefinitionService::class)->ensureDefaults($b->id);

        $this->mondayWork();
        $this->at(self::MON . ' 16:00:00');

        $bTp = [];
        foreach (range(1, 6) as $i) {
            $tp = $this->tp(['street_number' => '5', 'street_name' => 'Otherville Rd ' . $i, 'suburb' => 'Shelly Beach', 'suburb_normalised' => 'shelly beach'], $b);
            $this->listing(['address' => '5 Otherville Rd ' . $i, 'suburb' => 'Shelly Beach', 'first_seen_at' => '2026-03-0' . $i], $tp, $b);
            $bTp[] = $tp->id;
        }
        $this->app['auth']->forgetGuards();
        $this->artisan('rts:build-lists', ['--date' => self::MON, '--agency' => $b->id])->assertExitCode(0);

        $bList = RtsListItem::withoutGlobalScopes()->where('user_id', $bAgent->id)->where('list_date', self::MON)->get();
        $this->assertCount(3, $bList, 'six properties dealt out between its agent and its manager (road-to-sold.md 22.6)');
        $this->assertSame([], array_values(array_diff($bList->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all(), $bTp)), 'only its own stock');
        $this->assertSame(0, RtsListItem::withoutGlobalScopes()->where('agency_id', $this->agency->id)->whereIn('tracked_property_id', $bTp)->count(), 'agency A never sees agency B\'s properties');

        // B captures one outcome through the page.
        $firstTp = TrackedProperty::withoutGlobalScopes()->find($bList->first()->tracked_property_id);
        $secondTp = TrackedProperty::withoutGlobalScopes()->find($bList->skip(1)->first()->tracked_property_id);
        $this->actingAs($bAgent)->post(route('road-to-sold.outcomes.record'), ['tracked_property_id' => $firstTp->id, 'list_item_id' => $bList->first()->id, 'outcome_definition_id' => $this->def('no_answer', $b->id)->id])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        // B's page, report and drill-downs: only B.
        $page = $this->actingAs($bAgent)->get(route('road-to-sold.index'))->assertOk();
        $page->assertSee($secondTp->street_name)->assertDontSee('Uvongo Ave')->assertDontSee('Manaba Ave');   // the first was called, so it is no longer "open"
        $this->actingAs($bAgent)->get(route('road-to-sold.index', ['status' => 'all']))->assertOk()->assertSee($firstTp->street_name);
        $this->actingAs($bAgent)->get(route('road-to-sold.index', ['agent' => $this->anna->id]))->assertNotFound();
        $this->actingAs($bMgr)->get(route('road-to-sold.index', ['agent' => $this->anna->id]))->assertNotFound();

        $rep = $this->actingAs($bMgr)->get(route('road-to-sold.report', ['period' => 'today']))->assertOk();
        $names = collect($rep->viewData('rows'))->pluck('name')->all();
        $this->assertContains('Bea Other', $names);
        foreach (['Anna Agent', 'Ben Agent', 'Cara Agent', 'Asha Assistant', 'Mo Manager', 'Adi Admin', 'Dan Agent'] as $n) {
            $this->assertNotContains($n, $names, "{$n} belongs to the other agency");
        }
        $this->assertSame(1, $rep->viewData('totals')['cells']['attempts']['value'], 'B\'s total is B\'s one call');
        $this->assertSame(0, $rep->viewData('totals')['cells']['conversations']['value']);
        $this->assertSame(1, $this->actingAs($bMgr)->getJson(route('road-to-sold.drilldown', ['metric' => 'attempts', 'period' => 'today']))->json('total'));
        $this->actingAs($bMgr)->getJson(route('road-to-sold.drilldown', ['metric' => 'attempts', 'period' => 'today', 'agent_id' => $this->ben->id]))->assertNotFound();

        // B's HFC-style words: nothing of agency A's outcome list or wording leaks into B's report.
        $this->assertStringNotContainsString('Scenario Realty', $this->actingAs($bMgr)->get(route('road-to-sold.report', ['period' => 'today']))->getContent());

        // ...and A's figures are exactly what A captured: B's call is not in them.
        $a = $this->actingAs($this->adi)->get(route('road-to-sold.report', ['period' => 'today']))->assertOk();
        $this->assertSame(count($this->entries(null, self::MON, self::MON, 'attempt')), $a->viewData('totals')['cells']['attempts']['value']);
        $this->assertNotContains('Bea Other', collect($a->viewData('rows'))->pluck('name')->all());
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  The story, played step by step
    // ═════════════════════════════════════════════════════════════════════════

    /** Friday's two calls are made in setUp()'s stock(); this is Monday: the night, then everyone's morning at the phone. */
    private function mondayWork(): void
    {
        $this->nightly(self::MON);
        $this->at(self::MON . ' 09:00:00');

        // BEN works first: B01 three times, so the third is the run of no-answers that drops it; B02-B04 are conversations.
        foreach ([1, 2, 3] as $n) {
            $this->capture($this->ben, 'B01', 'no_answer', [], $this->itemFor($this->ben, 'B01', self::MON));
        }
        $this->capture($this->ben, 'B02', 'spoke_evaluation_booked', ['appointment_at' => '2026-10-20 10:00'], $this->itemFor($this->ben, 'B02', self::MON));
        $this->capture($this->ben, 'B03', 'spoke_call_back', ['next_action_at' => self::TUE], $this->itemFor($this->ben, 'B03', self::MON));
        $this->capture($this->ben, 'B04', 'spoke_not_interested', ['next_action_at' => '2026-10-20'], $this->itemFor($this->ben, 'B04', self::MON));

        // ANNA leaves U01-U04 untouched and works the rest of her list (the assistant makes the wrong-number call for her).
        $this->at(self::MON . ' 10:30:00');
        $this->capture($this->anna, 'U05', 'listed_other_agency_open', ['next_action_at' => self::THU], $this->itemFor($this->anna, 'U05', self::MON));
        $this->capture($this->anna, 'U06', 'mandated_other_agency_sole', ['next_action_at' => '2026-10-30'], $this->itemFor($this->anna, 'U06', self::MON));
        $this->capture($this->asha, 'U07', 'wrong_number', [], $this->itemFor($this->anna, 'U07', self::MON), $this->anna);
        $this->capture($this->anna, 'U08', 'do_not_contact', [], $this->itemFor($this->anna, 'U08', self::MON));

        // Anna adds two more to her day ("Add to my day") and calls one of them.
        $this->actingAs($this->anna)->post(route('road-to-sold.add-to-day'), ['selection' => 'long_on_market', 'count' => 2])->assertSessionHas('status', 'Added 2 to your day.');
        $this->capture($this->anna, 'U09', 'no_answer', [], $this->itemFor($this->anna, 'U09', self::MON));

        // CARA calls the first of hers.
        $this->at(self::MON . ' 11:30:00');
        $this->capture($this->cara, 'M01', 'no_answer', [], $this->itemFor($this->cara, 'M01', self::MON));
    }

    /** Monday, then Tuesday to Friday: the follow-ups worked, the holiday and the leave, until Friday afternoon. */
    private function theWeek(): void
    {
        $this->mondayWork();

        // TUESDAY (Ben first, so the properties he takes are his; then Anna works what is left of hers; then Cara).
        $this->nightly(self::TUE);
        $this->at(self::TUE . ' 09:00:00');
        $this->workNext($this->ben, 'spoke_not_interested', ['next_action_at' => '2026-10-30']);          // the follow-up (B03)
        $this->workNext($this->ben, 'no_answer');
        $this->workNext($this->ben, 'spoke_call_back', ['next_action_at' => self::FRI]);
        $this->workNext($this->anna, 'no_answer');
        $this->workNext($this->anna, 'no_answer');
        $this->workNext($this->anna, 'spoke_evaluation_booked', ['appointment_at' => '2026-10-22 14:00']);
        $this->workNext($this->cara, 'wrong_number');
        $this->workNext($this->cara, 'spoke_call_back', ['next_action_at' => self::WED]);                  // due on the holiday: comes back next working day

        // WEDNESDAY: public holiday. No lists. THURSDAY: Ben on leave.
        $this->nightly(self::WED);
        $this->nightly(self::THU);
        $this->at(self::THU . ' 09:00:00');
        $this->workNext($this->cara, 'spoke_not_interested', ['next_action_at' => '2026-10-30']);           // the carried-over follow-up
        $this->workNext($this->anna, 'spoke_call_back', ['next_action_at' => '2026-10-22']);                // U05 came due today
        $this->workNext($this->anna, 'no_answer');

        // FRIDAY: Cara's Friday-9th "no answer" is due again; Ben's call back is due.
        $this->nightly(self::FRI);
        $this->at(self::FRI . ' 09:00:00');
        $this->workNext($this->cara, 'no_answer');
        $this->workNext($this->ben, 'no_answer');
        $this->workNext($this->anna, 'spoke_not_interested', ['next_action_at' => '2026-10-30']);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Capturing through the page, and the ledger
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Post "Contacted client" exactly as the popup does and write what happened into the ledger. $as is who is logged in;
     * $forAgent is whose figure it is (the agent, when an assistant makes the call).
     */
    private function capture(User $as, string $property, string $key, array $extra = [], ?int $itemId = null, ?User $forAgent = null): void
    {
        $forAgent ??= $as;
        $tp = $this->tpid($property);
        $before = RtsContactOutcome::withoutGlobalScopes()->count();
        $item = $itemId ? RtsListItem::withoutGlobalScopes()->find($itemId) : null;

        $res = $this->actingAs($as)->post(route('road-to-sold.outcomes.record'), array_filter([
            'tracked_property_id' => $tp, 'list_item_id' => $itemId, 'outcome_definition_id' => $this->def($key)->id,
        ], fn ($v) => $v !== null) + $extra);
        $res->assertSessionHasNoErrors();
        $this->assertNull(session('rts_error'), "capturing {$key} on {$property}: " . (string) session('rts_error'));
        $this->assertSame($before + 1, RtsContactOutcome::withoutGlobalScopes()->count(), "{$key} on {$property} was saved");

        $row = RtsContactOutcome::withoutGlobalScopes()->orderByDesc('id')->first();
        // the run of no-answers is followed independently of the code under test: the Nth in a row for this agent on this property
        $run = ($key === 'no_answer' && ($this->runs[$forAgent->id . ':' . $tp] ?? 0) >= 0) ? ($this->runs[$forAgent->id . ':' . $tp] ?? 0) + 1 : 0;
        $this->runs[$forAgent->id . ':' . $tp] = $key === 'no_answer' ? $run : 0;
        $this->ledger[] = [
            'user' => $forAgent->id, 'key' => $key, 'kind' => self::KIND[$key], 'valuation' => isset($extra['appointment_at']) || $key === 'spoke_evaluation_booked',
            'dropped' => $run >= 3, 'day' => Carbon::now()->toDateString(), 'item' => $item?->id, 'origin' => $item?->origin,
        ];
        $this->assertSame($this->ledger[array_key_last($this->ledger)]['dropped'], (bool) $row->dropped, "{$key} on {$property}: dropped after three no-answers in a row");
    }

    /** The next open item on this person's list for today that nobody else holds, worked with this outcome. */
    private function workNext(User $agent, string $key, array $extra = []): void
    {
        $day = Carbon::now()->toDateString();
        $item = RtsListItem::withoutGlobalScopes()->where('user_id', $agent->id)->where('list_date', $day)->where('status', 'open')->orderBy('position')->get()
            ->first(fn ($i) => ! app(HoldService::class)->isHeldByOther((int) $i->tracked_property_id, $agent));
        $this->assertNotNull($item, "{$agent->name} has an open, free item on {$day}");
        $tp = TrackedProperty::withoutGlobalScopes()->find($item->tracked_property_id);
        $name = array_search($tp->id, array_map(fn ($t) => $t->id, $this->p), true);
        $this->capture($agent, (string) $name, $key, $extra, $item->id);
    }

    private function itemFor(User $agent, string $property, string $day): int
    {
        $row = RtsListItem::withoutGlobalScopes()->where('user_id', $agent->id)->where('list_date', $day)->where('tracked_property_id', $this->tpid($property))->first();
        $this->assertNotNull($row, "{$property} is on {$agent->name}'s list for {$day}");

        return (int) $row->id;
    }

    private function entries(?int $userId, string $from, string $to, ?string $kind = null): array
    {
        return array_values(array_filter($this->ledger, fn ($e) => ($userId === null || $e['user'] === $userId) && $e['day'] >= $from && $e['day'] <= $to && ($kind === null || $e['kind'] === $kind)));
    }

    private function at(string $when): void
    {
        Carbon::setTestNow($when);
        \Carbon\CarbonImmutable::setTestNow($when);
        Cache::flush();
        SettingsService::forget();
    }

    private function nightly(string $day): void
    {
        $this->at($day . ' 04:30:00');
        $this->artisan('rts:build-lists', ['--date' => $day, '--agency' => $this->agency->id])->assertExitCode(0);
    }

    private function listOf(User $u, string $day)
    {
        return RtsListItem::withoutGlobalScopes()->where('user_id', $u->id)->where('list_date', $day)->orderBy('position')->get();
    }

    private function listIds(User $u, string $day): array
    {
        return $this->listOf($u, $day)->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->all();
    }

    private function def(string $key, ?int $agencyId = null): Def
    {
        return Def::withoutGlobalScopes()->where('agency_id', $agencyId ?? $this->agency->id)->where('key', $key)->firstOrFail();
    }

    private function tpid(string $name): int
    {
        return (int) $this->p[$name]->id;
    }

    private function ids(string ...$names): array
    {
        return array_map(fn ($n) => $this->tpid($n), $names);
    }

    /** Johan's rule: CoreX says "evaluation", never the old word (identifiers such as the valuation_booked column are never on screen). */
    private const OLD_WORD = '/(?<![eE])valuation/i';

    private function visible(string $html): string
    {
        $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html);

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  The calendar oracle: targets worked out from the table at the top, not from the code under test
    // ═════════════════════════════════════════════════════════════════════════

    private function expectedTarget(string $name, string $from, string $to): int
    {
        $sum = 0;
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d = $d->copy()->addDay()) {
            $sum += $this->targetOn($name, $d->toDateString());
        }

        return $sum;
    }

    private function expectedMonthToDate(string $name, string $day): int
    {
        return $this->expectedTarget($name, Carbon::parse($day)->startOfMonth()->toDateString(), $day);
    }

    private function targetOn(string $name, string $day): int
    {
        if ($day === self::WED) {
            return 0;                       // public holiday
        }
        if ($name === 'ben' && $day === self::THU) {
            return 0;                       // approved leave (Cara's Friday leave is only pending)
        }

        return self::WEEKS[$name][Carbon::parse($day)->dayOfWeekIso] ?? 0;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  The world
    // ═════════════════════════════════════════════════════════════════════════

    private function person(string $name, string $role, array $extra = [], ?Agency $agency = null, ?Branch $branch = null): User
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;

        return User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '.' . uniqid() . '@example.test',
        ] + $extra);
    }

    /** A populated grants table (an empty one permits everything in tests): who holds the page and how wide their view is. */
    private function grants(int $agencyId): void
    {
        foreach ([
            ['agent', 'own'], ['assistant', 'own'], ['branch_manager', 'branch'], ['admin', 'all'],
        ] as [$role, $scope]) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $agencyId]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $agencyId, 'scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    private function weekdayTargets(): void
    {
        $t = app(TargetService::class);
        foreach (['anna' => $this->anna, 'ben' => $this->ben, 'cara' => $this->cara] as $name => $u) {
            $t->saveWeek($u, self::WEEKS[$name], $this->adi->id);
        }
        // The manager, the admin and Dan do not work a call list.
        foreach ([$this->mo, $this->adi, $this->dan] as $u) {
            $t->saveWeek($u, [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0], $this->adi->id);
        }
    }

    private function calendar(): void
    {
        DB::table('public_holidays')->insert([
            'country_code' => 'ZA', 'holiday_date' => self::WED, 'name' => 'Scenario holiday', 'is_movable' => 0,
            'applies_to_year' => 2026, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->leaveTypeId = DB::table('leave_types')->insertGetId([
            'agency_id' => $this->agency->id, 'code' => 'annual', 'label' => 'Annual leave', 'category' => 'annual',
            'accrual_method' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->leave($this->ben, self::THU, self::THU, 'approved');
        $this->leave($this->cara, self::FRI, self::FRI, 'submitted');
    }

    private function leave(User $u, string $from, string $to, string $status): void
    {
        static $n = 0;
        $employee = DB::table('payroll_employees')->insertGetId([
            'agency_id' => $this->agency->id, 'user_id' => $u->id, 'employment_date' => '2024-01-01',
            'designation_snapshot' => 'Agent', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('leave_applications')->insert([
            'agency_id' => $this->agency->id, 'payroll_employee_id' => $employee, 'user_id' => $u->id,
            'leave_type_id' => $this->leaveTypeId, 'application_number' => 'LV-S' . (++$n), 'start_date' => $from, 'end_date' => $to,
            'is_half_day' => 0, 'working_days_requested' => 1, 'calendar_days_requested' => 1,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The agency's stock of calls, oldest first so that a rule that is NOT applied would put the wrong property on top:
     *   H, D, S  (Jan)  held by Cara / do-not-contact / another agent's CoreX stock - the oldest of all, so any leak leads the list
     *   R01-R10  (Feb)  Ramsgate, nobody's area
     *   U01-U40  (Mar)  Uvongo - Anna's and Ben's area
     *   M01-M02  (Mar)  Manaba - Cara's area, but only two of them
     */
    private function stock(): void
    {
        $country = DB::table('p24_countries')->insertGetId(['p24_id' => 9201, 'name' => 'South Africa']);
        $province = DB::table('p24_provinces')->insertGetId(['p24_id' => 9201, 'p24_country_id' => $country, 'name' => 'KwaZulu-Natal']);
        $city = DB::table('p24_cities')->insertGetId(['p24_id' => 9211, 'p24_province_id' => $province, 'name' => 'Margate']);
        $sub = [];
        foreach (['Uvongo' => 920101, 'Manaba' => 920102, 'Ramsgate' => 920103, 'Central' => 920104] as $name => $p24) {
            $sub[$name] = DB::table('p24_suburbs')->insertGetId([
                'name' => $name, 'slug' => strtolower($name), 'p24_id' => $p24, 'p24_city_id' => $city, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['Uvongo', 'Manaba', 'Ramsgate', 'Central'] as $name) {
            $this->putInPool($this->agency->id, $sub[$name]);
        }
        // v3 (road-to-sold.md 22.6): a property belongs to one agent, so Ben works his own suburb (Central) instead of sharing Anna's stock.
        foreach ([[$this->anna, 'Uvongo'], [$this->ben, 'Central'], [$this->cara, 'Manaba']] as [$u, $s]) {
            RtsAgentArea::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'track' => 'sales',
                'user_id' => $u->id, 'p24_suburb_id' => $sub[$s], 'assigned_by_user_id' => $this->adi->id,
            ]);
        }

        // owners with a phone (the first twelve Uvongo properties, the Manaba pair, H and D)
        $this->prop('H', 'Uvongo', $sub['Uvongo'], 'Held Ave 01', '2026-01-01', true);
        $this->prop('D', 'Uvongo', $sub['Uvongo'], 'Never Ave 01', '2026-01-02', true);
        $this->prop('S', 'Uvongo', $sub['Uvongo'], 'Stock Ave 01', '2026-01-03', false);
        foreach (range(1, 10) as $i) {
            $this->prop(sprintf('R%02d', $i), 'Ramsgate', $sub['Ramsgate'], sprintf('Ramsgate Ave %02d', $i), Carbon::parse('2026-02-01')->addDays($i - 1)->toDateString(), false);
        }
        foreach (range(1, 40) as $i) {
            $this->prop(sprintf('U%02d', $i), 'Uvongo', $sub['Uvongo'], sprintf('Uvongo Ave %02d', $i), Carbon::parse('2026-03-01')->addDays($i - 1)->toDateString(), $i <= 12);
        }
        foreach (range(1, 12) as $i) {
            $this->prop(sprintf('B%02d', $i), 'Central', $sub['Central'], sprintf('Central Ave %02d', $i), Carbon::parse('2026-03-01')->addDays($i - 1)->toDateString(), $i <= 12);
        }
        foreach (range(1, 2) as $i) {
            $this->prop(sprintf('M%02d', $i), 'Manaba', $sub['Manaba'], sprintf('Manaba Ave %02d', $i), Carbon::parse('2026-03-20')->addDays($i - 1)->toDateString(), true);
        }

        // S is another agent's CoreX property (Dan's, sold): it is his, never to be offered to anyone else.
        $propertyId = $this->property($this->dan->id, ['address' => '10 Stock Ave 01', 'suburb' => 'Uvongo', 'status' => 'sold']);
        DB::table('prospecting_listings')->where('tracked_property_id', $this->tpid('S'))->update(['matched_property_id' => $propertyId]);
        TrackedProperty::withoutGlobalScopes()->where('id', $this->tpid('S'))->update(['promoted_to_property_id' => $propertyId]);

        // FRIDAY 9 OCT: Cara phoned H ("no answer": hers for 30 days) and D ("do not contact": nobody calls it again).
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $svc = app(OutcomeService::class);
        $svc->record($this->cara, $this->tpid('H'), $this->def('no_answer')->id);
        $svc->record($this->cara, $this->tpid('D'), $this->def('do_not_contact')->id);
        $this->ledger[] = ['user' => $this->cara->id, 'key' => 'no_answer', 'kind' => 'attempt', 'valuation' => false, 'dropped' => false, 'day' => self::FRI_BEFORE, 'item' => null, 'origin' => null];
        $this->ledger[] = ['user' => $this->cara->id, 'key' => 'do_not_contact', 'kind' => 'conversation', 'valuation' => false, 'dropped' => false, 'day' => self::FRI_BEFORE, 'item' => null, 'origin' => null];
        $this->runs[$this->cara->id . ':' . $this->tpid('H')] = 1;
        Carbon::setTestNow(self::MON . ' 03:00:00');
    }

    private function prop(string $code, string $suburb, int $suburbId, string $street, string $firstSeen, bool $withOwner): void
    {
        $owner = null;
        if ($withOwner) {
            $owner = $this->owner[$code] = Contact::withoutGlobalScopes()->create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->anna->id, 'agent_id' => $this->anna->id,
                'first_name' => 'Owner', 'last_name' => $code, 'phone' => '082' . str_pad((string) (crc32($code) % 10000000), 7, '0', STR_PAD_LEFT),
            ]);
        }
        $tp = $this->tp([
            'street_number' => '10', 'street_name' => $street, 'suburb' => $suburb, 'suburb_normalised' => mb_strtolower($suburb),
            'p24_suburb_id' => $suburbId, 'owner_contact_id' => $owner?->id, 'last_known_asking_price' => 1_450_000,
        ]);
        $this->listing(['address' => '10 ' . $street, 'suburb' => $suburb, 'first_seen_at' => $firstSeen . ' 08:00:00', 'agency_name' => 'Rival Realty'], $tp);
        $this->p[$code] = $tp;
    }
}
