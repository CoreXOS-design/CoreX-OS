<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CommandTask;
use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsManagerAction;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\RoadToSold\RtsTrainingPointer;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\RolePermission;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Performance\Period;
use App\Services\RoadToSold\DrilldownService;
use App\Services\RoadToSold\FunnelService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\RecipeProgressService;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\SuggestionService;
use App\Services\RoadToSold\TaskCompletionService;
use App\Services\RoadToSold\TaskTargetService;
use App\Services\RoadToSold\TrainingPointerService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 Road to Sold v2 phase 5 - the funnel, training flags and suggested targets (road-to-sold.md sections 9, 10.4, 10.5, 13.1).
 *
 * Proven: every grid figure is the SAME query as DrilldownService::count and its drill-down rows (per agent, per figure), another agency
 * and another window are left out; the done-by split adds up to the total; a ratio with nothing in its denominator has no percentage;
 * the agency median needs three agents with enough events and flags respect the sample gate and the percentage; a branch manager sees
 * their branch but the median is the whole agency's; flags are acted on (training assigned = a task + a notification + a ledger line, a
 * note, acknowledge) and an agent outside the breadth or one's own flags are refused; training pointers validate and archive/restore;
 * a suggested target follows the stated rule, is capped by the next level, never writes by itself, and accept / edit / ignore are audited;
 * the personal-target list sets, clears and restores through one audited writer; the screens search, filter, sort and page.
 */
final class FunnelTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private Branch $north;
    private Branch $south;
    private User $admin;
    private User $manager;
    private User $a1;
    private User $a2;
    private User $a3;
    private User $a4;
    private User $otherAdmin;
    private User $otherAgent;
    private TrackedProperty $tp;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        // Wednesday 21 Oct 2026
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00:00', config('app.timezone')));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->other = Agency::create(['name' => 'Durban Homes', 'slug' => 'dbn-' . Str::random(6)]);
        $this->north = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->south = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Claremont']);
        $otherBranch = Branch::create(['agency_id' => $this->other->id, 'name' => 'Umhlanga']);
        $this->admin = $this->mk('Alice Admin', 'admin', $this->north);
        $this->manager = $this->mk('Bruce Manager', 'branch_manager', $this->north);
        $this->a1 = $this->mk('Retha Kelly', 'agent', $this->north);
        $this->a2 = $this->mk('Thandi Nkosi', 'agent', $this->north);
        $this->a3 = $this->mk('Shawn Du Bois', 'agent', $this->south);
        $this->a4 = $this->mk('Pieter Smit', 'agent', $this->south);
        $this->otherAdmin = $this->mk('Olga Other', 'admin', $otherBranch);
        $this->otherAgent = $this->mk('Olive Other', 'agent', $otherBranch);

        foreach ([$this->agency->id, $this->other->id] as $agencyId) {
            foreach ([
                ['admin', 'access_road_to_sold', null], ['admin', 'road_to_sold.view', 'all'], ['admin', 'road_to_sold.view_funnel', null], ['admin', 'road_to_sold.manage_targets', null],
                ['admin', 'road_to_sold.manage_settings', null], ['admin', 'road_to_sold.manage_recipe', null],
                ['branch_manager', 'access_road_to_sold', null], ['branch_manager', 'road_to_sold.view', 'branch'], ['branch_manager', 'road_to_sold.view_funnel', null],
                ['branch_manager', 'road_to_sold.manage_targets', null],
                ['agent', 'access_road_to_sold', null], ['agent', 'road_to_sold.view', 'own'],
            ] as [$role, $key, $scope]) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => $scope, 'agency_id' => $agencyId]);
            }
        }
        PermissionService::clearCache();

        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        app(OutcomeDefinitionService::class)->ensureDefaults($this->other->id);
        $this->tp = TrackedProperty::create(['agency_id' => $this->agency->id, 'street_number' => '14', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function mk(string $name, string $role, Branch $b, array $extra = []): User
    {
        return User::factory()->create($extra + [
            'agency_id' => $b->agency_id, 'branch_id' => $b->id, 'role' => $role, 'is_active' => true, 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . '@example.test',
        ]);
    }

    private function period(string $from = '2026-09-22', string $to = '2026-10-21'): Period
    {
        return new Period(CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay(), 'test', 'custom');
    }

    private function setting(array $values): void
    {
        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), $values)->assertSessionHasNoErrors();
        SettingsService::forget();
    }

    /** n logged calls by $user; $conversations of them are conversations. $by = who pressed the button (defaults to the agent). */
    private function calls(User $user, int $n, int $conversations = 0, string $at = '2026-10-12 10:00:00', ?User $by = null, int $booked = 0, ?Agency $agency = null): void
    {
        $agency ??= $this->agency;
        $tp = $agency->id === $this->agency->id ? $this->tp : TrackedProperty::create(['agency_id' => $agency->id, 'street_number' => '1', 'street_name' => 'Other Rd', 'suburb' => 'Umhlanga']);
        $none = Def::withoutGlobalScopes()->where('agency_id', $agency->id)->where('key', 'no_answer')->firstOrFail();
        $spoke = Def::withoutGlobalScopes()->where('agency_id', $agency->id)->where('key', 'spoke_call_back')->firstOrFail();
        for ($i = 0; $i < $n; $i++) {
            $def = $i < $conversations ? $spoke : $none;
            DB::table('rts_contact_outcomes')->insert([
                'agency_id' => $agency->id, 'branch_id' => $user->branch_id, 'track' => 'sales', 'user_id' => $user->id, 'recorded_by_user_id' => ($by ?? $user)->id,
                'tracked_property_id' => $tp->id, 'outcome_definition_id' => $def->id, 'outcome_key' => $def->key, 'outcome_label' => $def->label, 'kind' => $def->kind,
                'next_action' => 'none', 'is_current' => false, 'valuation_booked' => $i < $booked, 'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function event(User $user, string $category, string $at, ?string $viewing = null, bool $feedback = true, ?Agency $agency = null): int
    {
        $agency ??= $this->agency;
        $id = DB::table('calendar_events')->insertGetId([
            'agency_id' => $agency->id, 'branch_id' => $user->branch_id, 'user_id' => $user->id, 'created_by_id' => $user->id, 'event_type' => 'manual', 'category' => $category,
            'title' => 'Appointment ' . (++$this->n), 'event_date' => $at, 'status' => 'completed', 'priority' => 'normal', 'source_type' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($feedback) {
            DB::table('calendar_event_feedback')->insert([
                'calendar_event_id' => $id, 'agency_id' => $agency->id, 'viewing_status' => $viewing ?? '', 'captured_by_user_id' => $user->id, 'captured_at' => $at, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function document(User $owner, string $type, string $status, string $at, ?Agency $agency = null): void
    {
        $agency ??= $this->agency;
        $docId = DB::table('docuperfect_documents')->insertGetId([
            'name' => 'Doc ' . (++$this->n), 'owner_id' => $owner->id, 'agency_id' => $agency->id, 'document_type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('signature_templates')->insert(['document_id' => $docId, 'agency_id' => $agency->id, 'status' => $status, 'completed_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function listing(User $agent, string $listed, ?Agency $agency = null): void
    {
        $agency ??= $this->agency;
        DB::table('properties')->insert([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $agent->branch_id, 'external_id' => (string) Str::uuid(), 'title' => 'L ' . (++$this->n),
            'listing_type' => 'sale', 'status' => 'active', 'property_type' => 'house', 'price' => 900000, 'listed_date' => $listed, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function presentation(User $by, ?string $sentAt, ?Agency $agency = null): int
    {
        $agency ??= $this->agency;
        $id = DB::table('presentations')->insertGetId([
            'agency_id' => $agency->id, 'branch_id' => $by->branch_id, 'created_by_user_id' => $by->id, 'title' => 'Presentation ' . (++$this->n), 'property_address' => '14 Marine Drive',
            'status' => 'finalized', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($sentAt !== null) {
            \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
            DB::table('presentation_deliveries')->insert([
                'snapshot_link_id' => 9000 + random_int(1, 8999), 'presentation_id' => $id, 'agency_id' => $agency->id, 'sent_by_user_id' => $by->id,
                'channel' => 'email', 'recipient_name' => 'Pieter van der Merwe', 'recipient_email' => 'pieter@example.co.za', 'mode' => 'full',
                'status' => 'sent', 'sent_at' => $sentAt, 'created_at' => now(), 'updated_at' => now(),
            ]);
            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
        }

        return $id;
    }

    private function deal(User $agent, string $registered, ?Agency $agency = null): void
    {
        $agency ??= $this->agency;
        DB::table('deals')->insert([
            'agency_id' => $agency->id, 'branch_id' => $agent->branch_id, 'managed_by_user_id' => $agent->id, 'accepted_status' => 'R', 'registration_date' => $registered,
            'property_address' => 'Deal ' . (++$this->n), 'period' => substr($registered, 0, 7), 'deal_date' => $registered, 'property_value' => 900000, 'total_commission' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── the figures: one query, many readers ─────────────────────────────

    public function test_every_grid_figure_is_the_same_query_as_the_count_and_the_drill_down_rows(): void
    {
        $this->calls($this->a1, 6, 3, booked: 2);
        $this->calls($this->a2, 4, 1);
        $this->calls($this->a1, 5, 0, '2026-08-01 10:00:00');          // outside the window
        $this->calls($this->otherAgent, 7, 7, agency: $this->other);    // another agency
        $this->event($this->a1, 'property_evaluation', '2026-10-10 10:00:00');
        $this->event($this->a1, 'property_evaluation', '2026-10-11 10:00:00', feedback: false);   // booked, not done
        $this->event($this->a2, 'viewing', '2026-10-09 10:00:00', 'viewed');
        $this->event($this->a2, 'viewing', '2026-10-09 11:00:00', 'did_not_happen');
        $this->document($this->a1, 'mandate', 'completed', '2026-10-08 12:00:00');
        $this->document($this->a1, 'otp', 'completed', '2026-10-08 12:00:00');
        $this->document($this->a2, 'otp', 'signing', '2026-10-08 12:00:00');                       // not complete
        $this->listing($this->a1, '2026-10-05');
        $this->listing($this->a2, '2026-09-01');                                                    // outside the window
        $this->presentation($this->a1, '2026-10-07 09:00:00');
        $this->presentation($this->a1, null);                                                       // never delivered
        $this->deal($this->a1, '2026-10-15');

        $drill = app(DrilldownService::class);
        $ids = [$this->a1->id, $this->a2->id];
        $expected = ['calls_made' => [6, 4], 'conversations' => [3, 1], 'evaluations_booked' => [2, 0], 'evaluations_done' => [1, 0], 'presentations_given' => [1, 0],
            'mandates_signed' => [1, 0], 'listings_live' => [1, 0], 'viewings_held' => [0, 1], 'otp_signed' => [1, 0], 'deals_registered' => [1, 0]];

        foreach ($expected as $metric => [$one, $two]) {
            $by = $drill->countsByAgent($metric, $ids, $this->period(), $this->agency->id);
            $this->assertSame([$this->a1->id => $one, $this->a2->id => $two], $by, $metric . ' per agent');
            foreach ($ids as $id) {
                $this->assertSame($by[$id], $drill->count($metric, [$id], $this->period(), $this->agency->id), $metric . ' count for ' . $id);
                $this->assertSame($by[$id], $drill->rows($metric, [$id], $this->period(), $this->agency->id)['total'], $metric . ' rows total for ' . $id);
            }
        }
        $this->assertSame(0, $drill->count('calls_made', [$this->otherAgent->id], $this->period(), $this->agency->id), 'another agency\'s agent never counts here');
    }

    public function test_the_drill_down_endpoint_serves_the_new_figures(): void
    {
        $this->presentation($this->a1, '2026-10-07 09:00:00');
        $this->document($this->a1, 'otp', 'completed', '2026-10-08 12:00:00');
        $this->event($this->a1, 'viewing', '2026-10-09 10:00:00', 'viewed');
        $this->deal($this->a1, '2026-10-15');

        foreach (['presentations_given', 'otp_signed', 'viewings_held', 'deals_registered'] as $metric) {
            $this->actingAs($this->admin)->getJson(route('road-to-sold.drilldown', ['metric' => $metric, 'agent_id' => $this->a1->id, 'period' => 'custom', 'start' => '2026-10-01', 'end' => '2026-10-21']))
                ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'rows');
        }
    }

    public function test_the_done_by_split_adds_up_to_the_total(): void
    {
        $assistant = $this->mk('Asha Assistant', 'agent', $this->north, ['is_assistant' => true]);
        $this->calls($this->a1, 6, 2);                         // the agent's own
        $this->calls($this->a1, 3, 1, by: $assistant);         // an assistant's, counted under the agent
        $this->document($this->a1, 'mandate', 'completed', '2026-10-08 12:00:00');

        $drill = app(DrilldownService::class);
        $all = $drill->countsByAgent('calls_made', [$this->a1->id], $this->period(), $this->agency->id);
        $agent = $drill->countsByAgent('calls_made', [$this->a1->id], $this->period(), $this->agency->id, doneBy: 'agent');
        $asst = $drill->countsByAgent('calls_made', [$this->a1->id], $this->period(), $this->agency->id, doneBy: 'assistant');
        $this->assertSame([9, 6, 3], [$all[$this->a1->id], $agent[$this->a1->id], $asst[$this->a1->id]]);

        $conv = $drill->countsByAgent('conversations', [$this->a1->id], $this->period(), $this->agency->id, doneBy: 'assistant');
        $this->assertSame(1, $conv[$this->a1->id]);

        // a source that does not record who pressed the button: the agent is the whole figure, the assistant none - and they still add up
        $m = fn (?string $by) => $drill->countsByAgent('mandates_signed', [$this->a1->id], $this->period(), $this->agency->id, doneBy: $by)[$this->a1->id];
        $this->assertSame([1, 1, 0], [$m(null), $m('agent'), $m('assistant')]);
    }

    // ── ratios, medians, flags ───────────────────────────────────────────

    public function test_a_ratio_with_nothing_underneath_has_no_percentage(): void
    {
        $f = app(FunnelService::class);
        $r = $f->ratiosOf(['calls_made' => 0, 'conversations' => 0, 'evaluations_booked' => 3, 'evaluations_done' => 0]);

        $this->assertNull($r['calls_to_conversations']['pct'], 'never shown as 0%');
        $this->assertNull($r['conversations_to_evaluations']['pct']);
        $this->assertSame(0.0, $r['evaluations_booked_to_done']['pct'], 'a real zero out of three is 0%');
        $this->assertSame(0, $r['calls_to_conversations']['den']);
    }

    public function test_the_median_needs_three_agents_with_enough_events_and_is_a_true_median(): void
    {
        $f = app(FunnelService::class);
        $mk = fn (int $num, int $den) => ['calls_to_conversations' => ['num' => $num, 'den' => $den, 'pct' => $den > 0 ? round($num / $den * 100, 1) : null]];

        $this->assertNull($f->medians([1 => $mk(8, 10), 2 => $mk(2, 10)], 5)['calls_to_conversations'], 'two agents is not enough');
        $this->assertNull($f->medians([1 => $mk(8, 10), 2 => $mk(2, 10), 3 => $mk(8, 2)], 5)['calls_to_conversations'], 'the third has too little behind it');
        $this->assertSame(50.0, $f->medians([1 => $mk(8, 10), 2 => $mk(2, 10), 3 => $mk(5, 10)], 5)['calls_to_conversations']);
        $this->assertSame(65.0, $f->medians([1 => $mk(8, 10), 2 => $mk(2, 10), 3 => $mk(5, 10), 4 => $mk(8, 10)], 5)['calls_to_conversations'], 'even count: the middle two averaged');
    }

    public function test_a_flag_needs_the_sample_the_median_and_a_ratio_below_the_percentage(): void
    {
        $f = app(FunnelService::class);
        $mk = fn (int $num, int $den) => ['calls_to_conversations' => ['num' => $num, 'den' => $den, 'pct' => $den > 0 ? round($num / $den * 100, 1) : null]];
        $ratios = [1 => $mk(8, 10), 2 => $mk(2, 10), 3 => $mk(8, 10), 4 => $mk(8, 10), 5 => $mk(1, 4), 6 => $mk(0, 0), 7 => $mk(48, 100)];
        $medians = $f->medians($ratios, 5);
        $this->assertSame(80.0, $medians['calls_to_conversations']);

        $flags = $f->flags($ratios, $medians, 5, 60);

        $this->assertArrayHasKey('calls_to_conversations', $flags[2], '20% is below 60% of 80% (48%)');
        $this->assertArrayNotHasKey(1, $flags, 'at the median');
        $this->assertArrayNotHasKey(5, $flags, '25% but only 4 calls: below the minimum sample');
        $this->assertArrayNotHasKey(6, $flags, 'no data, no flag');
        $this->assertArrayNotHasKey(7, $flags, 'exactly 60% of the median is not below it');
        $this->assertEqualsWithDelta(0.75, $flags[2]['calls_to_conversations']['severity'], 0.001);
        $this->assertSame([], $f->flags($ratios, ['calls_to_conversations' => null] + $medians, 5, 60), 'no median, no flags');
    }

    public function test_the_window_and_the_flag_text(): void
    {
        $p = app(FunnelService::class)->windowPeriod(30, Carbon::parse('2026-10-21 10:00:00'));
        $this->assertSame('2026-09-22', $p->start->toDateString());
        $this->assertSame('2026-10-21', $p->end->toDateString());
        $this->assertSame('Needs training: the evaluation pitch', FunnelService::flagText('conversations_to_evaluations'));
    }

    // ── the screens ──────────────────────────────────────────────────────

    /** a1 and a3/a4 convert 8 of 10 calls; a2 converts 2 of 10 - the agency median is 80%, so only a2 is flagged. */
    private function flaggedScenario(): void
    {
        $this->setting(['flag_min_sample' => 5]);
        $this->calls($this->a1, 10, 8);
        $this->calls($this->a2, 10, 2);
        $this->calls($this->a3, 10, 8);
        $this->calls($this->a4, 10, 8);
    }

    public function test_a_branch_manager_sees_their_branch_but_is_measured_against_the_whole_agency(): void
    {
        $this->flaggedScenario();

        $page = $this->actingAs($this->manager)->get(route('road-to-sold.funnel.index'))->assertOk();
        $page->assertSee('Retha Kelly')->assertSee('Thandi Nkosi')->assertDontSee('Shawn Du Bois')->assertDontSee('Pieter Smit');
        $page->assertSee('Needs training: getting owners talking')->assertSee('80%');

        $admin = $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index'))->assertOk();
        $admin->assertSee('Shawn Du Bois')->assertSee('Pieter Smit')->assertSee('Thandi Nkosi');

        $this->actingAs($this->otherAdmin)->get(route('road-to-sold.funnel.index'))->assertOk()->assertDontSee('Retha Kelly')->assertDontSee('Thandi Nkosi');
        $this->actingAs($this->a1)->get(route('road-to-sold.funnel.index'))->assertForbidden();
        $this->actingAs($this->a1)->get(route('road-to-sold.funnel.flags'))->assertForbidden();
        $this->actingAs($this->a1)->get(route('road-to-sold.funnel.suggestions'))->assertForbidden();
    }

    public function test_the_grid_searches_filters_sorts_pages_and_says_when_there_is_not_enough_data(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index'))->assertOk()->assertSee('Not enough data yet');

        $this->flaggedScenario();
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['q' => 'smit']))->assertSee('Pieter Smit')->assertDontSee('Thandi Nkosi');
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['q' => 'no-such-agent']))->assertSee('Nothing matches this search');
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['flagged' => 1]))->assertSee('Thandi Nkosi')->assertDontSee('Retha Kelly');
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['branch' => $this->south->id, 'sort' => 'agent']))->assertSee('Shawn Du Bois')->assertDontSee('Retha Kelly');
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index'))->assertSeeInOrder(['Thandi Nkosi', 'Retha Kelly']);   // worst flag first
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['view' => 'figures']))->assertOk()->assertSee('Calls made')->assertSee('Deals registered');
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['by' => 'assistant']))->assertOk();   // nothing was done by an assistant: ratios fall away, no error
    }

    public function test_the_grid_pages_at_25_agents(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->mk('Pager Agent ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'agent', $this->north);
        }
        $page = $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['sort' => 'agent', 'q' => 'pager']))->assertOk();
        $page->assertSee('Pager Agent 00')->assertSee('Pager Agent 24')->assertDontSee('Pager Agent 25');
        $this->actingAs($this->admin)->get(route('road-to-sold.funnel.index', ['sort' => 'agent', 'q' => 'pager', 'page' => 2]))->assertSee('Pager Agent 25');
    }

    // ── flags: filters, training, notes ──────────────────────────────────

    public function test_the_flags_list_filters_by_status_step_and_search(): void
    {
        $this->flaggedScenario();

        $open = $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags'))->assertOk();
        $open->assertSee('Thandi Nkosi')->assertSee('Needs training: getting owners talking')->assertSee('agency median 80%');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['q' => 'evaluation']))->assertSee('Nothing matches this search');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['step' => 'contact_outcome']))->assertSee('Thandi Nkosi');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['step' => 'mandate']))->assertSee('Nothing matches this search');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['status' => 'acted']))->assertSee('Nothing matches this search');

        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.acknowledge'), ['agent_id' => $this->a2->id, 'ratio_key' => 'calls_to_conversations'])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags'))->assertDontSee('Thandi Nkosi');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['status' => 'acted']))->assertSee('Thandi Nkosi')->assertSee('Marked as seen')->assertSee('Bruce Manager');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['status' => 'all']))->assertSee('Thandi Nkosi');
    }

    public function test_an_empty_flags_list_says_why(): void
    {
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags'))->assertOk()->assertSee('Not enough data yet');
        $this->flaggedScenario();
        $this->calls($this->a2, 30, 28);   // everyone now converts
        $this->calls($this->a1, 30, 28);
        $this->calls($this->a3, 30, 28);
        $this->calls($this->a4, 30, 28);
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags'))->assertSee('No one is flagged');
    }

    private function course(Agency $agency = null, string $title = 'Cold calling that gets a yes'): TrainingCourse
    {
        $agency ??= $this->agency;

        return TrainingCourse::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'title' => $title, 'description' => 'x', 'category' => 'sales', 'is_published' => true, 'created_by' => $this->admin->id]);
    }

    public function test_training_can_only_be_assigned_when_the_agency_has_chosen_it_and_it_is_a_task_a_notice_and_a_ledger_line(): void
    {
        $this->flaggedScenario();
        $post = fn (User $by, User $agent) => $this->actingAs($by)->post(route('road-to-sold.funnel.assign'), ['agent_id' => $agent->id, 'ratio_key' => 'calls_to_conversations']);

        $post($this->manager, $this->a2)->assertSessionHasErrors('training');
        $this->assertSame(0, RtsManagerAction::withoutGlobalScopes()->count());

        $course = $this->course();
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.flags.pointer.save'), ['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => (string) $course->id])->assertSessionHasNoErrors();

        $post($this->manager, $this->a2)->assertSessionHasNoErrors();

        $action = RtsManagerAction::withoutGlobalScopes()->sole();
        $this->assertSame('training_assigned', $action->kind);
        $this->assertSame('calls_to_conversations', $action->subject_key);
        $this->assertSame((int) $this->manager->id, (int) $action->by_user_id);
        $task = CommandTask::withoutGlobalScopes()->findOrFail($action->command_task_id);
        $this->assertSame((int) $this->a2->id, (int) $task->assigned_to);
        $this->assertStringContainsString('Cold calling that gets a yes', $task->title);
        $this->assertSame(1, NotificationDispatchLog::where('user_id', $this->a2->id)->count(), 'the agent is told');

        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['status' => 'acted']))->assertSee('Training assigned');
    }

    public function test_actions_stay_inside_the_breadth_and_never_on_ones_own_flags(): void
    {
        $this->flaggedScenario();
        $act = fn (User $by, User $agent, string $route, array $extra = []) => $this->actingAs($by)->post(route($route), ['agent_id' => $agent->id, 'ratio_key' => 'calls_to_conversations'] + $extra);

        $act($this->manager, $this->a3, 'road-to-sold.funnel.acknowledge')->assertNotFound();     // south
        $act($this->manager, $this->otherAgent, 'road-to-sold.funnel.acknowledge')->assertNotFound();   // another agency
        $act($this->otherAdmin, $this->a2, 'road-to-sold.funnel.acknowledge')->assertNotFound();
        $act($this->a1, $this->a1, 'road-to-sold.funnel.acknowledge')->assertForbidden();         // no door
        $act($this->admin, $this->a3, 'road-to-sold.funnel.acknowledge')->assertSessionHasNoErrors();

        // a manager who happens to be flagged is looked at by someone else
        $this->calls($this->manager, 10, 1);
        $act($this->manager, $this->manager, 'road-to-sold.funnel.acknowledge')->assertForbidden();
    }

    public function test_a_coaching_note_needs_words_and_is_kept(): void
    {
        $this->flaggedScenario();
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.note'), ['agent_id' => $this->a2->id, 'ratio_key' => 'calls_to_conversations', 'note' => '   '])->assertSessionHasErrors('note');
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.note'), ['agent_id' => $this->a2->id, 'ratio_key' => 'calls_to_conversations', 'note' => str_repeat('x', 2001)])->assertSessionHasErrors('note');
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.note'), ['agent_id' => $this->a2->id, 'ratio_key' => 'nonsense', 'note' => 'ok'])->assertSessionHasErrors('ratio_key');
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.note'), ['agent_id' => $this->a2->id, 'ratio_key' => 'calls_to_conversations', 'note' => 'Role-played the opening line'])->assertSessionHasNoErrors();

        $this->assertSame('Role-played the opening line', RtsManagerAction::withoutGlobalScopes()->sole()->note);
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.flags', ['status' => 'acted']))->assertSee('Role-played the opening line');
    }

    public function test_the_manager_action_history_is_append_only(): void
    {
        $this->flaggedScenario();
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.acknowledge'), ['agent_id' => $this->a2->id, 'ratio_key' => 'calls_to_conversations']);
        $row = RtsManagerAction::withoutGlobalScopes()->sole();

        try {
            $row->update(['note' => 'rewritten']);
            $this->fail('an update must be refused');
        } catch (\LogicException) {
            $this->assertNull($row->fresh()->note);
        }
        $this->expectException(\LogicException::class);
        $row->delete();
    }

    // ── training pointers (settings) ─────────────────────────────────────

    public function test_training_pointers_validate_archive_and_restore_and_never_cross_agencies(): void
    {
        $mine = $this->course();
        $theirs = $this->course($this->other, 'Their course');
        $save = fn (array $d) => $this->actingAs($this->admin)->post(route('road-to-sold.settings.flags.pointer.save'), $d);

        $save(['ratio_key' => 'nonsense', 'kind' => 'course', 'target' => (string) $mine->id])->assertSessionHasErrors('ratio_key');
        $save(['ratio_key' => 'calls_to_conversations', 'kind' => 'video', 'target' => '1'])->assertSessionHasErrors('kind');
        $save(['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => (string) $theirs->id])->assertSessionHasErrors('target');
        $save(['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => '999999'])->assertSessionHasErrors('target');
        $this->assertSame(0, RtsTrainingPointer::withoutGlobalScopes()->count());

        $save(['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => (string) $mine->id])->assertSessionHasNoErrors();
        $save(['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => (string) $mine->id])->assertSessionHasNoErrors();
        $this->assertSame(1, RtsTrainingPointer::withoutGlobalScopes()->count(), 'a second save changes the one row');

        $this->actingAs($this->admin)->get(route('road-to-sold.settings.flags.index'))->assertOk()->assertSee('Cold calling that gets a yes')->assertSee('Training &amp; flags', false);

        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.flags.pointer.archive', 'calls_to_conversations'))->assertRedirect();
        $this->assertSoftDeleted('rts_training_pointers', ['ratio_key' => 'calls_to_conversations']);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.flags.index', ['show' => 'archived']))->assertSee('Archived: Cold calling that gets a yes');
        $this->actingAs($this->admin)->post(route('road-to-sold.settings.flags.pointer.restore', 'calls_to_conversations'))->assertRedirect();
        $this->assertSame(1, RtsTrainingPointer::withoutGlobalScopes()->whereNull('deleted_at')->count());

        $save(['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => (string) $mine->id]);
        $this->actingAs($this->admin)->delete(route('road-to-sold.settings.flags.pointer.archive', 'calls_to_conversations'));
        $save(['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => (string) $mine->id]);   // re-save after archive restores the same row
        $this->assertSame(1, RtsTrainingPointer::withoutGlobalScopes()->withTrashed()->count());

        $this->actingAs($this->manager)->get(route('road-to-sold.settings.flags.index'))->assertForbidden();
        $this->actingAs($this->manager)->post(route('road-to-sold.settings.flags.pointer.save'), ['ratio_key' => 'calls_to_conversations', 'kind' => 'course', 'target' => (string) $mine->id])->assertForbidden();
    }

    public function test_the_flags_settings_page_saves_the_thresholds_and_comes_back_to_itself(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.flags.index'))->assertOk()
            ->assertSee('Fewest events before a ratio can be flagged')->assertSee('A suggested target allows growth of');

        $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), ['back_to' => 'flags', 'flag_min_sample' => 12, 'flag_below_pct' => 50, 'flag_window_days' => 45, 'suggest_window_weeks' => 6, 'suggest_growth_pct' => 15])
            ->assertRedirect(route('road-to-sold.settings.flags.index'));
        SettingsService::forget();
        $s = SettingsService::forAgency($this->agency->id, 'sales');
        $this->assertSame([12, 50, 45, 6, 15], [$s->flagMinSample(), $s->flagBelowPct(), $s->flagWindowDays(), $s->suggestWindowWeeks(), $s->suggestGrowthPct()]);

        foreach (['flag_min_sample' => [0, 1001], 'flag_below_pct' => [0, 101], 'flag_window_days' => [6, 367], 'suggest_window_weeks' => [1, 53], 'suggest_growth_pct' => [-1, 101]] as $field => $bad) {
            foreach ($bad as $value) {
                $this->actingAs($this->admin)->put(route('road-to-sold.settings.general.update'), [$field => $value])->assertSessionHasErrors($field);
            }
        }
    }

    public function test_the_defaults_are_agency_neutral(): void
    {
        $s = SettingsService::forAgency($this->agency->id, 'sales');
        $this->assertSame([20, 60, 30, 8, 10], [$s->flagMinSample(), $s->flagBelowPct(), $s->flagWindowDays(), $s->suggestWindowWeeks(), $s->suggestGrowthPct()]);
    }

    // ── suggested targets ────────────────────────────────────────────────

    /** A weekly door-knock task on Level 1 with a target of $target, and (optionally) Level 2 asking for $next. */
    private function weeklyTask(int $target = 5, ?int $next = null, string $unit = 'week'): RtsTask
    {
        app(RecipeService::class)->start($this->agency->id, 'sales');
        $step = RtsStep::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'find')->first();
        $task = app(RecipeService::class)->saveTask($this->agency->id, 'sales', null, [
            'label' => 'Door knock ' . (++$this->n), 'step_id' => $step->id, 'type' => 'custom', 'recurrence_unit' => $unit, 'capture_kind' => 'none', 'evidence_required' => 'none',
        ]);
        $l1 = RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 1)->first();
        RtsLevelTask::create(['agency_id' => $this->agency->id, 'level_id' => $l1->id, 'task_id' => $task->id, 'target' => $target, 'points' => 1.0, 'required_for_signoff' => false]);
        if ($next !== null) {
            $l2 = RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 2)->first();
            RtsLevelTask::create(['agency_id' => $this->agency->id, 'level_id' => $l2->id, 'task_id' => $task->id, 'target' => $next, 'points' => 1.0, 'required_for_signoff' => false]);
        }

        return $task;
    }

    private function did(User $agent, RtsTask $task, string $day, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            app(TaskCompletionService::class)->complete($task, $agent, null, [
                'occurred_at' => Carbon::parse($day . ' 12:00:00', config('app.timezone')), 'token' => (string) Str::uuid(), 'source' => RtsTaskCompletion::SOURCE_CAPTURED,
            ]);
        }
    }

    public function test_a_suggestion_follows_the_stated_rule_and_writes_nothing(): void
    {
        $task = $this->weeklyTask(5);
        foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13'] as $day) {
            $this->did($this->a1, $task, $day, 6);   // 42 in the window: well above a target of 5 a week
        }
        $before = RtsUserTaskOverride::withoutGlobalScopes()->count();

        $row = collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id);

        $this->assertSame(5, $row['current']);
        $this->assertSame(42, $row['total']);
        $this->assertGreaterThanOrEqual(SuggestionService::MIN_WORKING_DAYS, $row['working_days']);
        $this->assertSame(round(42 / $row['working_days'], 2), $row['rate']);
        $this->assertSame(max(5, (int) round((42 / $row['working_days']) * 1.10 * $row['next_days'])), $row['suggested']);
        $this->assertTrue($row['change']);
        $this->assertSame($before, RtsUserTaskOverride::withoutGlobalScopes()->count(), 'a suggestion never writes a target');
        $this->assertSame(0, RtsManagerAction::withoutGlobalScopes()->count());
    }

    public function test_the_growth_and_window_come_from_the_agency_settings(): void
    {
        $task = $this->weeklyTask(5);
        foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13'] as $day) {
            $this->did($this->a1, $task, $day, 12);
        }
        $this->setting(['suggest_growth_pct' => 0]);
        $flat = collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id)['suggested'];
        $this->setting(['suggest_growth_pct' => 50]);
        $grown = collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id)['suggested'];
        $this->assertGreaterThan($flat, $grown);

        $this->setting(['suggest_window_weeks' => 2]);
        $short = collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id);
        $this->assertSame(2, $short['weeks']);
        $this->assertSame(12, $short['total'], 'only the last two weeks are read');
    }

    public function test_the_next_level_caps_a_suggestion_but_never_below_the_current_target(): void
    {
        $task = $this->weeklyTask(5, next: 7);
        foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13'] as $day) {
            $this->did($this->a1, $task, $day, 12);
        }
        $row = collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id);
        $this->assertSame(7, $row['suggested']);
        $this->assertSame('Level 2', $row['capped_by']);
    }

    public function test_no_activity_hourly_tasks_and_thin_data_give_no_suggestion(): void
    {
        $quiet = $this->weeklyTask(5);
        $hourly = $this->weeklyTask(5, unit: 'hour');
        $this->did($this->a1, $hourly, '2026-10-13', 9);

        $rows = collect(app(SuggestionService::class)->forAgent($this->a1));
        $this->assertSame('Not enough data yet', $rows->firstWhere('task_id', $quiet->id)['reason']);
        $this->assertNull($rows->firstWhere('task_id', $quiet->id)['suggested']);
        $this->assertNull($rows->firstWhere('task_id', $hourly->id), 'an hourly challenge is never suggested');
    }

    public function test_accept_edit_and_ignore_are_audited_and_only_a_person_writes(): void
    {
        $task = $this->weeklyTask(5);
        foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13'] as $day) {
            $this->did($this->a1, $task, $day, 6);
            $this->did($this->a2, $task, $day, 6);
        }
        $suggested = collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id)['suggested'];

        $page = $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions'))->assertOk();
        $page->assertSee('Retha Kelly')->assertSee('Thandi Nkosi')->assertSee('Accept')->assertDontSee('Shawn Du Bois');
        $this->assertSame(0, RtsUserTaskOverride::withoutGlobalScopes()->count(), 'opening the screen writes nothing');

        // accept as suggested
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.suggestions.accept'), ['agent_id' => $this->a1->id, 'task_id' => $task->id, 'target' => $suggested, 'suggested' => $suggested])->assertSessionHasNoErrors();
        $own = RtsUserTaskOverride::withoutGlobalScopes()->where('user_id', $this->a1->id)->sole();
        $this->assertSame($suggested, (int) $own->target);
        $this->assertSame((int) $this->manager->id, (int) $own->set_by_user_id);
        $audit = RtsManagerAction::withoutGlobalScopes()->where('kind', 'target_set')->sole();
        $this->assertSame([5, $suggested, 'Accepted the suggested target'], [(int) $audit->value_from, (int) $audit->value_to, $audit->note]);

        // edit: a different number
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.suggestions.accept'), ['agent_id' => $this->a2->id, 'task_id' => $task->id, 'target' => 9, 'suggested' => $suggested])->assertSessionHasNoErrors();
        $this->assertSame(9, (int) RtsUserTaskOverride::withoutGlobalScopes()->where('user_id', $this->a2->id)->value('target'));
        $this->assertStringContainsString('Edited from the suggested', RtsManagerAction::withoutGlobalScopes()->where('user_id', $this->a2->id)->value('note'));

        $this->assertSame(2, RtsManagerAction::withoutGlobalScopes()->where('kind', 'target_set')->count());
    }

    public function test_an_ignored_suggestion_stays_hidden_until_the_figure_changes(): void
    {
        $task = $this->weeklyTask(5);
        foreach (['2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29', '2026-10-06', '2026-10-13'] as $day) {
            $this->did($this->a1, $task, $day, 6);
        }
        $suggested = collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id)['suggested'];

        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.suggestions.ignore'), ['agent_id' => $this->a1->id, 'task_id' => $task->id, 'suggested' => $suggested])->assertSessionHasNoErrors();
        $this->assertTrue(collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id)['ignored']);
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions'))->assertSee('Nothing to suggest for this agent');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions', ['show' => 'all']))->assertSee('Ignored');

        $this->did($this->a1, $task, '2026-10-14', 20);   // the figure moves
        $this->assertFalse(collect(app(SuggestionService::class)->forAgent($this->a1))->firstWhere('task_id', $task->id)['ignored']);
    }

    public function test_suggestions_are_refused_outside_the_breadth_and_without_the_key(): void
    {
        $task = $this->weeklyTask(5);
        $body = fn (User $agent) => ['agent_id' => $agent->id, 'task_id' => $task->id, 'target' => 8, 'suggested' => 8];

        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.suggestions.accept'), $body($this->a3))->assertNotFound();          // south
        $this->actingAs($this->otherAdmin)->post(route('road-to-sold.funnel.suggestions.accept'), $body($this->a1))->assertNotFound();       // another agency
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.suggestions.ignore'), $body($this->a3) + [])->assertNotFound();
        $this->actingAs($this->a1)->post(route('road-to-sold.funnel.suggestions.accept'), $body($this->a1))->assertForbidden();
        $this->assertSame(0, RtsUserTaskOverride::withoutGlobalScopes()->count());

        // a viewer who may see the funnel but not set targets
        RolePermission::where('role', 'branch_manager')->where('permission_key', 'road_to_sold.manage_targets')->delete();
        PermissionService::clearCache();
        $this->actingAs($this->manager)->post(route('road-to-sold.funnel.suggestions.accept'), $body($this->a1))->assertForbidden();
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions'))->assertOk()->assertDontSee('>Accept<', false);
    }

    public function test_the_suggestions_screen_searches_filters_and_has_an_empty_state(): void
    {
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions'))->assertOk()->assertSee('Nothing to suggest for this agent');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions', ['q' => 'no-such-agent']))->assertSee('Nothing matches this search');

        $task = $this->weeklyTask(5);
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions', ['show' => 'all']))->assertSee('Door knock')->assertSee('Not enough data yet');
        $this->actingAs($this->manager)->get(route('road-to-sold.funnel.suggestions', ['show' => 'all', 'task' => 'zzz']))->assertDontSee('Door knock');
        $this->assertNotNull($task);
    }

    // ── the personal-target list and its audited writer ──────────────────

    public function test_a_personal_target_is_set_cleared_and_restored_through_one_audited_writer(): void
    {
        $task = $this->weeklyTask(5);
        $url = fn (string $name, User $u) => route('road-to-sold.settings.task-targets.' . $name, [$u->id, $task->id]);

        $this->actingAs($this->manager)->put($url('save', $this->a1), ['target' => 8, 'note' => 'Strong quarter'])->assertSessionHasNoErrors();
        $this->assertSame(8, (int) RtsUserTaskOverride::withoutGlobalScopes()->where('user_id', $this->a1->id)->value('target'));

        $progress = collect(app(RecipeProgressService::class)->forAgent($this->a1)['tasks'])->firstWhere('task_id', $task->id);
        $this->assertSame(8, $progress['target'], 'the engine reads the personal target');
        $this->assertSame(5, collect(app(RecipeProgressService::class)->forAgent($this->a2)['tasks'])->firstWhere('task_id', $task->id)['target'], 'others keep the level\'s');

        $this->actingAs($this->manager)->put($url('save', $this->a1), ['target' => 9])->assertSessionHasNoErrors();
        $this->assertSame(1, RtsUserTaskOverride::withoutGlobalScopes()->count(), 'one row per agent and task');

        $this->actingAs($this->manager)->delete($url('clear', $this->a1))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('rts_user_task_overrides', ['user_id' => $this->a1->id]);
        $this->assertSame(5, collect(app(RecipeProgressService::class)->forAgent($this->a1)['tasks'])->firstWhere('task_id', $task->id)['target']);
        $this->actingAs($this->manager)->delete($url('clear', $this->a1))->assertNotFound();   // nothing left to clear

        $this->actingAs($this->manager)->post($url('restore', $this->a1))->assertSessionHasNoErrors();
        $this->assertSame(9, collect(app(RecipeProgressService::class)->forAgent($this->a1)['tasks'])->firstWhere('task_id', $task->id)['target']);

        $kinds = RtsManagerAction::withoutGlobalScopes()->orderBy('id')->pluck('kind')->all();
        $this->assertSame(['target_set', 'target_set', 'target_cleared', 'target_set'], $kinds);
        $first = RtsManagerAction::withoutGlobalScopes()->orderBy('id')->first();
        $this->assertSame([5, 8, 'Strong quarter'], [(int) $first->value_from, (int) $first->value_to, $first->note]);
    }

    public function test_a_personal_target_is_validated_and_scoped(): void
    {
        $task = $this->weeklyTask(5);
        $save = fn (User $by, User $agent, $target) => $this->actingAs($by)->put(route('road-to-sold.settings.task-targets.save', [$agent->id, $task->id]), ['target' => $target]);

        foreach (['-1', '2.5', 'abc', '100001', ''] as $bad) {
            $save($this->manager, $this->a1, $bad)->assertSessionHasErrors('target');
        }
        $save($this->manager, $this->a1, '0')->assertSessionHasNoErrors();   // 0 = nothing required this period
        $this->assertSame(0, (int) RtsUserTaskOverride::withoutGlobalScopes()->value('target'));

        $save($this->manager, $this->a3, 6)->assertNotFound();               // south
        $save($this->otherAdmin, $this->a1, 6)->assertNotFound();            // another agency
        $save($this->a1, $this->a1, 6)->assertForbidden();
        $this->assertSame(1, RtsUserTaskOverride::withoutGlobalScopes()->count());

        $svc = app(TaskTargetService::class);
        $this->assertSame(0, $svc->effective($this->a1, $task, RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 1)->first()));
    }

    public function test_the_task_target_list_searches_filters_sorts_pages_and_says_when_empty(): void
    {
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index'))->assertOk()->assertSee('There are no targets to set yet');

        $task = $this->weeklyTask(5);
        $page = $this->actingAs($this->manager)->get(route('road-to-sold.settings.task-targets.index'))->assertOk();
        $page->assertSee('Retha Kelly')->assertSee('Thandi Nkosi')->assertDontSee('Shawn Du Bois')->assertSee('Door knock');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index'))->assertSee('Shawn Du Bois');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index', ['q' => 'smit']))->assertSee('Pieter Smit')->assertDontSee('Retha Kelly');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index', ['q' => 'zzz']))->assertSee('Nothing matches this search');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index', ['branch' => $this->south->id]))->assertSee('Shawn Du Bois')->assertDontSee('Retha Kelly');

        app(TaskTargetService::class)->set($this->admin, $this->a1, $task, RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 1)->first(), 7);
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index', ['show' => 'own']))->assertSee('Retha Kelly')->assertDontSee('Thandi Nkosi');
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index', ['show' => 'archived']))->assertSee('Nothing matches this search');
        app(TaskTargetService::class)->clear($this->admin, $this->a1, $task, RtsLevel::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('number', 1)->first());
        $this->actingAs($this->admin)->get(route('road-to-sold.settings.task-targets.index', ['show' => 'archived']))->assertSee('Restore 7');

        $this->actingAs($this->a1)->get(route('road-to-sold.settings.task-targets.index'))->assertForbidden();
    }

    // ── the sidebar ──────────────────────────────────────────────────────

    public function test_the_sidebar_link_sits_inside_the_permission_and_feature_guards(): void
    {
        $src = file_get_contents(resource_path('views/layouts/corex-sidebar.blade.php'));
        $at = strpos($src, "route('road-to-sold.funnel.index')");
        $this->assertNotFalse($at, 'the sidebar links to the team funnel');
        $before = substr($src, max(0, $at - 500), 500);
        $this->assertStringContainsString("@feature('road-to-sold')", $before);
        $this->assertStringContainsString("@permission('road_to_sold.view_funnel')", $before);
        $this->assertTrue(collect(\Illuminate\Support\Facades\Route::getRoutes()->getByName('road-to-sold.funnel.index')->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_contains($m, 'view_funnel')), 'the page needs the same key');
    }
}
