<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-454 (Johan, 23 Oct 2026): the Performance tiles link to the Deal Register with ?branch_id= and the register ignored it, so a
 * branch tile landed on every branch. It is now honoured WITHIN the viewer's breadth and never beyond it.
 *
 * Input paths proven: a whole-agency viewer asking for each branch gets that branch's deals only (the rule the tiles count by:
 * the deal's own branch, else its agent's); no branch_id, a branch that does not exist, another agency's branch, and a value that
 * is not a number all leave the list whole; a branch-scope viewer asking for ANOTHER branch still gets their own branch's deals
 * and the branch figure beside each row stays theirs; an own-scope viewer keeps their own deals whatever is asked.
 */
final class DealRegisterBranchFilterTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branchA;
    private Branch $branchB;
    private User $admin;
    private User $bmA;
    private User $agentA;
    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branchA = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->branchB = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Camps Bay']);
        foreach (['admin', 'branch_manager', 'agent'] as $role) {
            Role::create(['name' => $role, 'label' => ucfirst($role), 'agency_id' => $this->agency->id]);
        }

        $this->admin = $this->user('admin', $this->branchA);
        $this->bmA = $this->user('branch_manager', $this->branchA);
        $this->agentA = $this->user('agent', $this->branchA);
        $this->agentB = $this->user('agent', $this->branchB);

        foreach (['admin' => 'all', 'branch_manager' => 'branch', 'agent' => 'own'] as $role => $scope) {
            foreach (['view_deals', 'create_deals', 'deals.create', 'deals.edit'] as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id]);
            }
            RolePermission::create(['role' => $role, 'permission_key' => 'deals.view', 'scope' => $scope, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true]);
    }

    private function deal(User $owner, Branch $branch, string $address): int
    {
        $id = (int) DB::table('deals')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'period' => '2026-10', 'deal_date' => '2026-10-05',
            'property_address' => $address, 'accepted_status' => 'G', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_user')->insert(['deal_id' => $id, 'user_id' => $owner->id, 'side' => 'listing', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array{0: list<string>, 1: int} the addresses the register lists, and the branch figure it shows beside them */
    private function register(User $viewer, array $query): array
    {
        $response = $this->actingAs($viewer)->get(route('deals-dr2.index', $query))->assertOk();

        return [$response->viewData('deals')->pluck('property_address')->sort()->values()->all(), (int) $response->viewData('branchIdContext')];
    }

    public function test_a_whole_agency_viewer_gets_the_asked_branch_and_only_that_branch(): void
    {
        $this->deal($this->agentA, $this->branchA, '1 Sea Point Rd');
        $this->deal($this->bmA, $this->branchA, '2 Sea Point Rd');
        $this->deal($this->agentB, $this->branchB, '3 Camps Bay Rd');

        $this->assertSame([['1 Sea Point Rd', '2 Sea Point Rd'], $this->branchA->id], $this->register($this->admin, ['branch_id' => $this->branchA->id]));
        $this->assertSame([['3 Camps Bay Rd'], $this->branchB->id], $this->register($this->admin, ['branch_id' => $this->branchB->id]));
        $this->assertSame(['1 Sea Point Rd', '2 Sea Point Rd', '3 Camps Bay Rd'], $this->register($this->admin, [])[0], 'no branch asked: the whole agency');
    }

    public function test_a_branch_that_is_not_theirs_to_ask_for_is_ignored_for_a_whole_agency_viewer(): void
    {
        $this->deal($this->agentA, $this->branchA, '1 Sea Point Rd');
        $this->deal($this->agentB, $this->branchB, '3 Camps Bay Rd');
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . Str::random(6)]);
        $foreign = Branch::create(['agency_id' => $other->id, 'name' => 'Elsewhere']);
        $all = ['1 Sea Point Rd', '3 Camps Bay Rd'];

        foreach ([999999, $foreign->id, 'abc', 0, -4] as $bad) {
            [$rows, $context] = $this->register($this->admin, ['branch_id' => $bad]);
            $this->assertSame($all, $rows, "branch_id={$bad} leaves the list whole");
            $this->assertSame(0, $context, "and shows no single branch's figure");
        }
    }

    public function test_a_branch_scope_viewer_asking_for_another_branch_gets_their_own_scope_never_more(): void
    {
        $this->deal($this->agentA, $this->branchA, '1 Sea Point Rd');
        $this->deal($this->agentB, $this->branchB, '3 Camps Bay Rd');

        foreach ([$this->branchB->id, $this->branchA->id, 999999] as $asked) {
            $this->assertSame([['1 Sea Point Rd'], $this->branchA->id], $this->register($this->bmA, ['branch_id' => $asked]), "asked for branch {$asked}");
        }
    }

    public function test_an_own_scope_viewer_keeps_their_own_deals_whatever_branch_is_asked(): void
    {
        $this->deal($this->agentA, $this->branchA, '1 Sea Point Rd');
        $this->deal($this->bmA, $this->branchA, '2 Sea Point Rd');
        $this->deal($this->agentB, $this->branchB, '3 Camps Bay Rd');

        foreach ([$this->branchA->id, $this->branchB->id] as $asked) {
            $this->assertSame([['1 Sea Point Rd'], 0], $this->register($this->agentA, ['branch_id' => $asked]), "asked for branch {$asked}");
        }
    }

    public function test_the_branch_filter_composes_with_the_tiles_other_parameters(): void
    {
        $this->deal($this->agentA, $this->branchA, '1 Sea Point Rd');
        DB::table('deals')->where('property_address', '1 Sea Point Rd')->update(['accepted_status' => 'R', 'deal_date' => '2026-09-10', 'period' => '2026-09']);
        $this->deal($this->agentA, $this->branchA, '2 Sea Point Rd');
        $this->deal($this->agentB, $this->branchB, '3 Camps Bay Rd');

        // what a Branch Performance tile sends: status, month, commission state and branch together
        $this->assertSame([['2 Sea Point Rd'], $this->branchA->id], $this->register($this->admin, ['status' => 'Granted', 'period' => '2026-10', 'commission_status' => 'Not Paid', 'branch_id' => $this->branchA->id]));
    }
}
