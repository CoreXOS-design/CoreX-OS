<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\Contact;
use App\Models\RoadToSold\RtsContactOutcome;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectListService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages as S;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\StageCountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Road to Sold v3 - the reads behind My prospects / Parked / the manager's stage counts (search, sort, filters, paging, scoping),
 * and the commands that adopt existing rows, upgrade outcome defaults, release a departed agent's stock and report the task mapping.
 */
final class ProspectListsAndCommandsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const WED = '2026-10-14';

    private ProspectService $prospects;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::WED . ' 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        ParkReasonService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        $this->prospects = app(ProspectService::class);
        $this->manager = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true, 'name' => 'Mia Manager']);
        foreach (['agent' => 'own', 'branch_manager' => 'branch'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owned(User $agent, string $street, array $tp = []): RtsProspect
    {
        $owner = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
            'first_name' => 'Owner', 'last_name' => $street, 'phone' => '082' . random_int(1000000, 9999999),
        ]);
        $t = $this->tp($tp + ['street_number' => '5', 'street_name' => $street, 'suburb' => 'Uvongo', 'owner_contact_id' => $owner->id]);

        return $this->prospects->place($agent, $t->id);
    }

    // ── My prospects ─────────────────────────────────────────────────────

    public function test_my_prospects_are_searched_filtered_and_sorted_by_when_they_are_due(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road');
        $b = $this->owned($this->agent, 'Beta Road');
        $c = $this->owned($this->agent, 'Gamma Road');
        $this->prospects->setNextAction($a, 'Call again', Carbon::parse('2026-10-20'), $this->agent);
        $this->prospects->setNextAction($b, 'Do the evaluation', Carbon::parse('2026-10-10'), $this->agent);
        $this->prospects->advance($b->refresh(), S::APPOINTMENT_SET, $this->agent, ['subject_type' => 'x', 'subject_id' => 1, 'next_at' => Carbon::parse('2026-10-10'), 'next_label' => 'Do the evaluation']);

        $svc = app(ProspectListService::class);
        $all = $svc->prospects($this->agent, $this->agent->id)['rows']->pluck('address')->all();
        $this->assertSame(['5 Beta Road', '5 Alpha Road', '5 Gamma Road'], $all, 'soonest first, nothing scheduled last');

        $this->assertSame(['5 Beta Road'], $svc->prospects($this->agent, $this->agent->id, ['due' => 'overdue'])['rows']->pluck('address')->all());
        $this->assertSame(['5 Beta Road'], $svc->prospects($this->agent, $this->agent->id, ['stage' => S::APPOINTMENT_SET])['rows']->pluck('address')->all());
        $this->assertSame(['5 Gamma Road'], $svc->prospects($this->agent, $this->agent->id, ['due' => 'none'])['rows']->pluck('address')->all());
        $this->assertSame(['5 Alpha Road'], $svc->prospects($this->agent, $this->agent->id, ['q' => 'alpha'])['rows']->pluck('address')->all(), 'search by address');
        $this->assertSame(['5 Alpha Road'], $svc->prospects($this->agent, $this->agent->id, ['q' => 'Call again'])['rows']->pluck('address')->all(), 'search by next action');
        $this->assertSame(['5 Gamma Road', '5 Beta Road', '5 Alpha Road'], $svc->prospects($this->agent, $this->agent->id, ['sort' => 'address', 'dir' => 'desc'])['rows']->pluck('address')->all());

        $res = $svc->prospects($this->agent, $this->agent->id);
        $this->assertSame(2, $res['counts'][S::FOUND]);
        $this->assertSame(1, $res['counts'][S::APPOINTMENT_SET], 'the stage strip counts the whole pile');
        $first = $res['rows']->first();
        $this->assertSame('overdue', $first['due_state']);
        $this->assertSame('Appointment set', $first['stage_label']);
    }

    public function test_parked_stock_is_listed_with_reason_and_revisit_and_a_manager_sees_the_pile_by_reason(): void
    {
        $p1 = $this->owned($this->agent, 'One Road');
        $p2 = $this->owned($this->agent, 'Two Road');
        $p3 = $this->owned($this->mate, 'Three Road');
        $this->prospects->park($p1, 'chose_other_agency', Carbon::parse('2026-11-20'), 'went with Sam', $this->agent);
        $this->prospects->park($p2, 'chose_other_agency', Carbon::parse('2026-10-15'), null, $this->agent);
        $this->prospects->park($p3, 'price_unrealistic', Carbon::parse('2027-02-01'), null, $this->mate);

        $svc = app(ProspectListService::class);
        $mine = $svc->parked($this->agent, $this->agent->id);
        $this->assertSame(['5 Two Road', '5 One Road'], $mine['rows']->pluck('address')->all(), 'by revisit date, soonest first');
        $this->assertSame(['5 Two Road'], $svc->parked($this->agent, $this->agent->id, ['revisit' => 'month'])['rows']->pluck('address')->all(), 'coming back within 30 days');
        $this->assertSame(['5 One Road'], $svc->parked($this->agent, $this->agent->id, ['revisit' => 'later'])['rows']->pluck('address')->all());
        $this->assertCount(0, $svc->parked($this->agent, $this->agent->id, ['revisit' => 'due'])['rows'], 'nothing is due yet');
        $this->assertSame(['5 One Road'], $svc->parked($this->agent, $this->agent->id, ['q' => 'went with'])['rows']->pluck('address')->all(), 'search in the note');

        $pile = $svc->parked($this->manager, null);
        $this->assertSame(['Seller chose another agency' => 2, 'Price unrealistic' => 1], collect($pile['by_reason'])->pluck('count', 'reason')->all(), 'the manager sees the pile grouped by reason');
        $this->assertSame(3, $pile['rows']->total());
        $this->assertSame(['Seller chose another agency'], $svc->parked($this->manager, null, ['reason' => 'Seller chose another agency'])['rows']->getCollection()->pluck('park_reason')->unique()->values()->all());
    }

    public function test_an_agent_sees_only_their_own_and_a_manager_only_their_branch(): void
    {
        $this->owned($this->agent, 'Mine Road');
        $this->owned($this->mate, 'Theirs Road');
        $svc = app(ProspectListService::class);

        $this->assertSame(['5 Mine Road'], $svc->prospects($this->agent, $this->agent->id)['rows']->pluck('address')->all());
        $this->assertCount(0, $svc->prospects($this->agent, $this->mate->id)['rows'], 'a colleague\'s list is not readable by id');
        $this->assertCount(1, $svc->prospects($this->manager, $this->mate->id)['rows'], 'the branch manager reads the branch');

        $otherBranch = \App\Models\Branch::create(['agency_id' => $this->agency->id, 'name' => 'Elsewhere']);
        $far = $this->makeAgent(null, $otherBranch);
        $this->owned($far, 'Far Road');
        $this->assertCount(0, $svc->prospects($this->manager, $far->id)['rows'], 'but not another branch');
    }

    public function test_stage_counts_show_now_entered_and_stalled_per_stage(): void
    {
        $a = $this->owned($this->agent, 'Alpha Road');
        $b = $this->owned($this->agent, 'Beta Road');
        $this->prospects->advance($a, S::EVALUATION_DONE, $this->agent, ['subject_type' => 'x', 'subject_id' => 1]);
        $this->prospects->advance($b->refresh(), S::EVALUATION_DONE, $this->agent, ['subject_type' => 'x', 'subject_id' => 2]);
        $this->prospects->advance($b->refresh(), S::PRESENTATION_GIVEN, $this->agent, ['subject_type' => 'x', 'subject_id' => 3]);
        // 20 days later: nothing has moved
        Carbon::setTestNow('2026-11-03 09:00:00');

        $rows = collect(app(StageCountService::class)->forStages($this->manager, $this->agent->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30')))->keyBy('stage');
        $this->assertSame(1, $rows[S::EVALUATION_DONE]['now']);
        $this->assertSame(2, $rows[S::EVALUATION_DONE]['entered'], 'both passed through it, even though one has moved on');
        $this->assertSame(1, $rows[S::PRESENTATION_GIVEN]['entered']);
        $this->assertSame(1, $rows[S::EVALUATION_DONE]['stalled'], 'idle longer than the agency\'s stall days (14)');
        $this->assertSame(20.0, $rows[S::EVALUATION_DONE]['avg_days']);
        $this->assertSame('Evaluation done', $rows[S::EVALUATION_DONE]['label']);

        $none = collect(app(StageCountService::class)->forStages($this->agent, $this->mate->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30')))->keyBy('stage');
        $this->assertSame(0, $none[S::EVALUATION_DONE]['entered'], 'an agent reads no colleague\'s counts');
    }

    // ── Commands ─────────────────────────────────────────────────────────

    public function test_adopting_existing_rows_gives_each_property_one_agent_and_closes_the_others(): void
    {
        $tp = $this->tp();
        $other = $this->tp();
        foreach ([$this->agent, $this->mate] as $u) {
            RtsListItem::withoutAgencyStamping(fn () => RtsListItem::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $u->id, 'list_date' => self::WED, 'track' => 'sales',
                'tracked_property_id' => $tp->id, 'selection' => 'long_on_market', 'origin' => 'auto', 'position' => 1, 'status' => 'open', 'reason' => 'old',
            ]));
        }
        $this->actingAs($this->mate);
        app(\App\Services\RoadToSold\OutcomeService::class)->record($this->mate, $other->id, Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'no_answer')->value('id'));
        RtsProspect::withoutGlobalScopes()->where('tracked_property_id', $other->id)->forceDelete();   // an outcome from before prospects existed

        $this->artisan('rts:adopt-prospects', ['--agency' => $this->agency->id])->assertExitCode(0);
        $this->assertSame(0, RtsProspect::withoutGlobalScopes()->count(), 'a dry run writes nothing');

        $this->artisan('rts:adopt-prospects', ['--agency' => $this->agency->id, '--apply' => true])->assertExitCode(0);
        $this->assertSame($this->agent->id, (int) $this->prospects->find($this->agency->id, $tp->id)->user_id, 'the earlier open row wins');
        $this->assertSame('missed', RtsListItem::withoutGlobalScopes()->where('user_id', $this->mate->id)->where('tracked_property_id', $tp->id)->value('status'), 'the other agent\'s row is closed');
        $adopted = $this->prospects->find($this->agency->id, $other->id);
        $this->assertSame($this->mate->id, (int) $adopted->user_id, 'the agent who called it keeps it');
        $this->assertSame('Call again', $adopted->next_action_label);

        $this->artisan('rts:adopt-prospects', ['--agency' => $this->agency->id, '--apply' => true])->assertExitCode(0);
        $this->assertSame(2, RtsProspect::withoutGlobalScopes()->count(), 'a second run adds nothing');
    }

    public function test_the_outcome_defaults_command_is_a_dry_run_until_told_otherwise(): void
    {
        Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'eval_chose_other')->forceDelete();

        $this->artisan('rts:apply-outcome-defaults', ['--agency' => $this->agency->id])->assertExitCode(0);
        $this->assertSame(0, Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'eval_chose_other')->count());

        $this->artisan('rts:apply-outcome-defaults', ['--agency' => $this->agency->id, '--apply' => true])->assertExitCode(0);
        $this->assertSame(1, Def::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('key', 'eval_chose_other')->count());
    }

    public function test_an_agent_who_has_gone_releases_their_stock_to_the_pool(): void
    {
        $tp = $this->tp();
        $this->prospects->place($this->mate, $tp->id);
        $this->assertNull($this->prospects->place($this->agent, $tp->id));

        $this->mate->forceFill(['is_active' => false])->save();
        $this->artisan('rts:release-inactive', ['--agency' => $this->agency->id, '--dry-run' => true])->assertExitCode(0);
        $this->assertNotNull($this->prospects->ownerOf($this->agency->id, $tp->id), 'a dry run releases nothing');

        $this->artisan('rts:release-inactive', ['--agency' => $this->agency->id])->assertExitCode(0);
        $this->assertNull($this->prospects->ownerOf($this->agency->id, $tp->id));
        $this->assertNotNull($this->prospects->place($this->agent, $tp->id), 'and another agent can now take it');
    }

    public function test_the_task_mapping_report_writes_nothing_and_says_so(): void
    {
        $before = \App\Models\RoadToSold\RtsTask::withoutGlobalScopes()->count();

        $this->artisan('rts:bind-tasks', ['--agency' => $this->agency->id])
            ->expectsOutputToContain('Report only')
            ->assertExitCode(0);

        $this->assertSame($before, \App\Models\RoadToSold\RtsTask::withoutGlobalScopes()->count());
        $this->artisan('rts:bind-tasks')->assertExitCode(1);
    }
}
