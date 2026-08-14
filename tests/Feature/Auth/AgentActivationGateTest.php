<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agent Activation Gate — .ai/specs/agent-activation-gate.md
 *
 * A newly invited agent used to be created is_active=true — already counted as
 * active in every list and seat-billing figure before they had ever set a
 * password. Worse, assigning them a branch + designation (the quick-assign
 * "Role" action) force-stamped email_verified_at, which strands the agent:
 * their invite link then claims "already set up, please sign in" even though
 * they never set a password. Found live 2026-08-14 while testing a P24 import
 * against a fresh agency.
 *
 * Fix reuses the existing, already-atomic first_login_at claim
 * (AgencyAdminFirstLoginService) as the single activation moment, rather than
 * a new column.
 */
final class AgentActivationGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(): Agency
    {
        return Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-'.uniqid()]);
    }

    private function ensureRole(string $name): void
    {
        if (! Role::withoutGlobalScopes()->whereNull('agency_id')->where('name', $name)->exists()) {
            (new Role())->forceFill([
                'name' => $name, 'label' => ucfirst($name), 'is_owner' => false, 'agency_id' => null,
            ])->save();
        }
        Role::clearCache();
    }

    private function adminFor(Agency $agency): User
    {
        $this->ensureRole('admin');
        RolePermission::insert([[
            'role' => 'admin', 'permission_key' => 'manage_users', 'scope' => null,
            'agency_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        PermissionService::clearCache();

        return User::factory()->create([
            'name' => 'Nomsa Zulu', 'agency_id' => $agency->id, 'role' => 'admin', 'is_admin' => 1,
        ]);
    }

    public function test_creating_an_agent_leaves_them_inactive(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $this->ensureRole('agent');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Sipho', 'surname' => 'Ngcobo',
            'email' => 'sipho.ngcobo@hfcoastal.co.za', 'cell' => '083 555 0123',
            'role' => 'agent',
        ])->assertSessionDoesntHaveErrors();

        $agent = User::where('email', 'sipho.ngcobo@hfcoastal.co.za')->firstOrFail();
        $this->assertFalse((bool) $agent->is_active, 'a freshly invited agent must stay inactive');
        $this->assertNull($agent->first_login_at);
        $this->assertNull($agent->email_verified_at);
        $this->assertTrue($agent->isPendingInvite());
    }

    public function test_a_test_agent_is_created_active_immediately(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $this->ensureRole('agent');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Test', 'surname' => 'Agent',
            'email' => 'test.agent@hfcoastal.co.za', 'cell' => '083 555 0199',
            'role' => 'agent', 'test_agent' => '1',
        ])->assertSessionDoesntHaveErrors();

        $agent = User::where('email', 'test.agent@hfcoastal.co.za')->firstOrFail();
        $this->assertTrue((bool) $agent->is_active, 'the test-agent bypass must still activate immediately');
        $this->assertNotNull($agent->email_verified_at);
    }

    /** THE bug: assigning branch + designation must not activate a pending invite. */
    public function test_assigning_branch_and_designation_does_not_activate_a_pending_agent(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $this->ensureRole('agent');
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        $pending = User::factory()->pendingInvite()->create([
            'agency_id' => $agency->id, 'role' => 'agent', 'name' => 'Precious Mthembu',
        ]);

        $this->actingAs($admin)->post(route('admin.users.role.update', $pending), [
            'role' => 'agent', 'branch_id' => $branch->id, 'designation' => 'Sales Agent',
            'cell' => '083 555 0111',
        ])->assertSessionDoesntHaveErrors();

        $pending->refresh();
        $this->assertSame($branch->id, $pending->branch_id, 'branch assignment itself must still apply');
        $this->assertSame('Sales Agent', $pending->designation, 'designation assignment itself must still apply');
        $this->assertFalse((bool) $pending->is_active, 'THE bug: must stay inactive');
        $this->assertNull($pending->email_verified_at, 'must not strand the invite link by faking acceptance');
        $this->assertTrue($pending->isPendingInvite());
    }

    /** The pending agent's invite link must still work after the branch/designation assignment above. */
    public function test_the_invite_link_still_works_after_a_branch_assignment(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $this->ensureRole('agent');
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        $pending = User::factory()->pendingInvite()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $this->actingAs($admin)->post(route('admin.users.role.update', $pending), [
            'role' => 'agent', 'branch_id' => $branch->id, 'cell' => '083 555 0111',
        ]);

        $signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'account.setup.store', now()->addDays(7), ['user' => $pending->id]
        );

        $this->post($signedUrl, [
            'password' => 'a-real-password-123', 'password_confirmation' => 'a-real-password-123',
        ])->assertRedirect(route('login'));

        $pending->refresh();
        $this->assertNotNull($pending->email_verified_at, 'setup must succeed, not be blocked as "already set up"');
    }

    /** The full flow: invite accepted, then first sign-in is what actually activates. */
    public function test_first_login_activates_the_agent(): void
    {
        $agency = $this->makeAgency();
        $this->ensureRole('agent');

        $agent = User::factory()->pendingInvite()->create([
            'agency_id' => $agency->id, 'role' => 'agent', 'email' => 'first.login@hfcoastal.co.za',
        ]);
        $this->assertFalse((bool) $agent->is_active);

        // Accept the invite (sets a real password + email_verified_at).
        $agent->forceFill(['password' => 'her-real-password-456', 'email_verified_at' => now()])->save();
        $this->assertFalse((bool) $agent->fresh()->is_active, 'accepting the invite alone must not activate');

        $this->post('/login', ['email' => $agent->email, 'password' => 'her-real-password-456'])
            ->assertSessionDoesntHaveErrors();

        $this->assertAuthenticatedAs($agent->fresh());
        $agent->refresh();
        $this->assertTrue((bool) $agent->is_active, 'the first successful sign-in must activate');
        $this->assertNotNull($agent->first_login_at);
    }

    /** A never-activated pending invite genuinely cannot sign in (unchanged, pre-existing behavior). */
    public function test_a_pending_invite_cannot_sign_in_at_all(): void
    {
        $agency = $this->makeAgency();
        $this->ensureRole('agent');
        $agent = User::factory()->pendingInvite()->create(['agency_id' => $agency->id, 'role' => 'agent']);

        $this->post('/login', ['email' => $agent->email, 'password' => 'a-guess'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** Reinstating a previously-active, later-deactivated agent is unaffected by this gate. */
    public function test_reinstating_a_previously_active_agent_still_works(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $this->ensureRole('agent');
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        // Was active before (first_login_at set by the factory default), then offboarded.
        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'role' => 'agent', 'is_active' => false,
        ]);

        $this->actingAs($admin)->post(route('admin.users.role.update', $agent), [
            'role' => 'agent', 'branch_id' => $branch->id, 'cell' => '083 555 0111',
        ])->assertSessionDoesntHaveErrors();

        $this->assertTrue((bool) $agent->fresh()->is_active, 'a genuinely-previously-active agent must still reinstate');
    }
}
