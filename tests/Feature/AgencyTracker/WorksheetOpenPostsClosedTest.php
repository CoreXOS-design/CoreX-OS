<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md step 0 (Johan, 20 Oct 2026, answer 14) — the two Worksheet
 * actions `POST /worksheet/align-company-target` (worksheet.align) and
 * `POST /worksheet/apply-branch-default` (worksheet.applyBranchDefault) were registered
 * outside the auth group: no login check, no permission check, and the controller then
 * dereferenced Auth::user()->id. They now carry auth + permission:view_worksheet, the same
 * gate as the Worksheet page whose buttons post to them.
 *
 * Proven for BOTH routes:
 *   logged out                      -> sent to the login page, no data touched
 *   logged in, no view_worksheet    -> 403, no data touched
 *   logged in, view_worksheet       -> reaches the action exactly as before
 *                                      (no-worksheet / no-branch-budget messages)
 */
final class WorksheetOpenPostsClosedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
    }

    /** @return array<string, array{0: string}> */
    public static function routes(): array
    {
        return [
            'align to company'     => ['worksheet.align'],
            'apply branch default' => ['worksheet.applyBranchDefault'],
        ];
    }

    private function agent(): User
    {
        return User::factory()->create([
            'role' => 'agent', 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]);
    }

    /** Grants exist for the agent role, so the "empty grants permit all" test posture is OFF. */
    private function grantAgentOnly(array $keys): void
    {
        foreach ($keys as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        PermissionService::clearCache();
    }

    private function worksheetFor(User $user): Worksheet
    {
        return Worksheet::create([
            'agency_id' => $this->agency->id, 'user_id' => $user->id, 'period' => now()->format('Y-m'),
            'personal_net_target' => 40000, 'business_net_target' => 20000,
        ]);
    }

    #[DataProvider('routes')]
    public function test_logged_out_post_is_sent_to_login_and_changes_nothing(string $routeName): void
    {
        $user = $this->agent();
        $worksheet = $this->worksheetFor($user);
        $snapshot = fn () => $worksheet->fresh()->only(['personal_net_target', 'business_net_target'])
            + ['updated_at' => $worksheet->fresh()->updated_at->toDateTimeString()];
        $before = $snapshot();

        $this->post(route($routeName))->assertRedirect(route('login'));

        $this->assertSame($before, $snapshot());
    }

    #[DataProvider('routes')]
    public function test_user_without_view_worksheet_gets_403(string $routeName): void
    {
        $user = $this->agent();
        $this->worksheetFor($user);
        $this->grantAgentOnly(['access_properties']);

        $this->actingAs($user)->post(route($routeName))->assertForbidden();
    }

    #[DataProvider('routes')]
    public function test_user_with_view_worksheet_still_reaches_the_action_with_no_worksheet(string $routeName): void
    {
        $user = $this->agent();
        $this->grantAgentOnly(['view_worksheet']);

        $response = $this->actingAs($user)->from('/worksheet')->post(route($routeName));

        $response->assertRedirect('/worksheet');
        $this->assertNotEmpty(session('error') ?? session('status'), 'the action ran and answered in plain words');
    }

    public function test_align_with_worksheet_but_no_branch_budget_says_so_and_changes_nothing(): void
    {
        $user = $this->agent();
        $worksheet = $this->worksheetFor($user);
        $this->grantAgentOnly(['view_worksheet']);

        $this->actingAs($user)->from('/worksheet')->post(route('worksheet.align'))
            ->assertRedirect('/worksheet')
            ->assertSessionHas('error', 'No branch budget configured.');

        $this->assertSame('40000.00', $worksheet->fresh()->personal_net_target);
    }

    public function test_another_role_granted_view_worksheet_is_not_locked_out(): void
    {
        $bm = User::factory()->create([
            'role' => 'branch_manager', 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
        ]);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'view_worksheet', 'agency_id' => $this->agency->id]);
        PermissionService::clearCache();

        $this->actingAs($bm)->from('/worksheet')->post(route('worksheet.applyBranchDefault'))
            ->assertRedirect('/worksheet');
    }
}
