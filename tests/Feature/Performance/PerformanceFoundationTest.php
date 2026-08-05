<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Models\UserBranchHistory;
use App\Services\Performance\AgencyPerformanceReportService;
use App\Services\Performance\BranchAttributionResolver;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-366-A foundation — branch-move enabler, point-in-time branch resolution,
 * and the user → branch → company rollup (period-scoped, owner-excluded,
 * null-branch → "Unassigned").
 */
class PerformanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_move_is_logged_to_history(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $b1 = Branch::create(['agency_id' => $agency->id, 'name' => 'B1']);
        $b2 = Branch::create(['agency_id' => $agency->id, 'name' => 'B2']);
        $mover = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id]);

        $this->actingAs($mover);
        User::find($agent->id)->update(['branch_id' => $b2->id]);

        $row = UserBranchHistory::where('user_id', $agent->id)->first();
        $this->assertNotNull($row, 'A branch move must be recorded in user_branch_history.');
        $this->assertSame($b1->id, (int) $row->from_branch_id);
        $this->assertSame($b2->id, (int) $row->to_branch_id);
        $this->assertSame($mover->id, (int) $row->moved_by_user_id);
        $this->assertSame($agency->id, (int) $row->agency_id);
    }

    public function test_point_in_time_branch_resolution(): void
    {
        $resolver = new BranchAttributionResolver();

        // No history → current branch.
        $this->assertSame(7, $resolver->branchAt(999, CarbonImmutable::parse('2026-08-01'), 7));

        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $u = User::factory()->create(['agency_id' => $agency->id]);
        UserBranchHistory::create([
            'user_id' => $u->id, 'agency_id' => $agency->id,
            'from_branch_id' => 10, 'to_branch_id' => 20,
            'moved_at' => CarbonImmutable::parse('2026-08-10 12:00:00'),
        ]);

        $r = new BranchAttributionResolver();
        $this->assertSame(10, $r->branchAt($u->id, CarbonImmutable::parse('2026-08-05'), 99), 'Before the move → the from-branch.');
        $this->assertSame(20, $r->branchAt($u->id, CarbonImmutable::parse('2026-08-15'), 99), 'After the move → the to-branch.');
    }

    public function test_rollup_is_period_scoped_owner_excluded_and_buckets_null_branch(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $b1 = Branch::create(['agency_id' => $agency->id, 'name' => 'B1']);

        // is_owner is guarded on Role — set it explicitly, not via mass assignment.
        Role::create(['name' => 'System Owner', 'label' => 'System Owner'])
            ->forceFill(['is_owner' => true])->save();
        Role::clearCache();

        $owner     = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'System Owner']);
        $agentA    = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'agent']);
        $agentNull = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => null, 'role' => 'agent']);

        $this->actingAs($agentA);

        // In-period contacts.
        $this->makeContact($agency, $b1, $agentA, '2026-08-10');
        $this->makeContact($agency, $b1, $agentA, '2026-08-12');
        $this->makeContact($agency, $b1, $agentNull, '2026-08-11');
        // Out-of-period → excluded.
        $this->makeContact($agency, $b1, $agentA, '2026-07-01');
        // By the owner → excluded (owner not in cohort).
        $this->makeContact($agency, $b1, $owner, '2026-08-10');

        // Diagnostics — pin down the cohort + dates so a miscount points at the cause.
        Role::clearCache();
        $this->assertContains('System Owner', User::ownerRoleNames(), 'The owner role must resolve.');
        $cohort = app(\App\Services\Performance\HierarchyResolver::class)
            ->agents(new PerformanceScope($agency->id))->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertEqualsCanonicalizing([$agentA->id, $agentNull->id], $cohort, 'Cohort must exclude the System Owner.');
        $this->assertSame(3, (int) DB::table('contacts')
            ->whereBetween('created_at', ['2026-08-01 00:00:00', '2026-08-31 23:59:59'])
            ->whereIn('created_by_user_id', [$agentA->id, $agentNull->id])
            ->whereNull('deleted_at')->count(), 'Exactly 3 in-period contacts by the cohort.');

        $period = (new PeriodResolver())->resolve('this_month', null, null, CarbonImmutable::parse('2026-08-15'));
        $report = app(AgencyPerformanceReportService::class)->build(new PerformanceScope($agency->id), $period);

        // Company: 2 (agentA) + 1 (agentNull) = 3. Out-of-period + owner excluded.
        $this->assertSame(3, $report['company']['contacts_created']);
        // Branch B1 = agentA's 2; Unassigned = agentNull's 1.
        $this->assertSame(2, $report['branches'][(string) $b1->id]['metrics']['contacts_created']);
        $this->assertSame(1, $report['branches']['unassigned']['metrics']['contacts_created']);
        $this->assertSame('Unassigned', $report['branches']['unassigned']['label']);
        // Owner is not part of the agent cohort.
        $this->assertNotContains($owner->name, array_column($report['agents'], 'name'));
    }

    public function test_agent_journey_pairs_current_with_prior_period_delta(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'B']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->actingAs($agent);

        // 3 contacts this month, 1 in the prior (equal-length) period.
        $this->makeContact($agency, $branch, $agent, '2026-08-10');
        $this->makeContact($agency, $branch, $agent, '2026-08-12');
        $this->makeContact($agency, $branch, $agent, '2026-08-14');
        $this->makeContact($agency, $branch, $agent, '2026-07-05');

        $period = (new PeriodResolver())->resolve('this_month', null, null, CarbonImmutable::parse('2026-08-15'));
        $journey = app(AgencyPerformanceReportService::class)->agentJourney($agency->id, $agent->id, $period);

        $this->assertNotNull($journey['agent']);
        $this->assertSame($agent->id, $journey['agent']['user_id']);

        $cc = collect($journey['metrics'])->firstWhere('key', 'contacts_created');
        $this->assertSame(3, $cc['value'], 'current period contacts_created');
        $this->assertSame(1, $cc['previous'], 'prior period contacts_created');
        $this->assertSame(2, $cc['delta'], 'delta = current - prior');
    }

    private function makeContact(Agency $agency, Branch $branch, User $creator, string $date): void
    {
        $c = new Contact();
        $c->forceFill([
            'agency_id'          => $agency->id,
            'branch_id'          => $branch->id,
            'first_name'         => 'C',
            'last_name'          => 'X',
            'created_by_user_id' => $creator->id,
        ])->save();

        // Pin created_at to the target day (bypass the auto timestamp).
        DB::table('contacts')->where('id', $c->id)->update(['created_at' => $date . ' 09:00:00']);
    }
}
