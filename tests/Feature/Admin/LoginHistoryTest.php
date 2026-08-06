<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\LoginHistory;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/login-audit-trail.md — a login/logout writes a permanent,
 * never-pruned login_histories row (unlike the `sessions` table, which
 * Laravel GCs). Admin edit page surfaces it only behind
 * users.login_history.view.
 */
final class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function agent(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ], $overrides));
    }

    public function test_successful_login_writes_a_login_history_row(): void
    {
        $user = $this->agent([
            'email' => 'elise.' . Str::random(6) . '@hfcoastal.co.za',
            'password' => Hash::make('CorrectHorseBattery9!'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'CorrectHorseBattery9!',
        ], ['REMOTE_ADDR' => '41.13.9.201'])->assertRedirect();

        $row = LoginHistory::forUser($user->id)->where('event', 'login')->firstOrFail();
        $this->assertSame('41.13.9.201', $row->ip_address);
        $this->assertNotNull($row->created_at);
    }

    public function test_wrong_password_writes_no_login_history_row(): void
    {
        $user = $this->agent([
            'email' => 'nofica.' . Str::random(6) . '@hfcoastal.co.za',
            'password' => Hash::make('CorrectHorseBattery9!'),
        ]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertSame(0, LoginHistory::forUser($user->id)->count());
    }

    public function test_logout_writes_a_login_history_row(): void
    {
        $user = $this->agent();

        $this->actingAs($user)
            ->post(route('logout'), [], ['REMOTE_ADDR' => '105.4.2.19'])
            ->assertRedirect();

        $row = LoginHistory::forUser($user->id)->where('event', 'logout')->firstOrFail();
        $this->assertSame('105.4.2.19', $row->ip_address);
    }

    public function test_admin_with_permission_sees_login_history_on_edit_page(): void
    {
        $admin  = $this->agent(['role' => 'admin']);
        $target = $this->agent();

        RolePermission::insert([
            ['role' => 'admin', 'permission_key' => 'manage_users', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'admin', 'permission_key' => 'users.login_history.view', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();

        LoginHistory::create([
            'user_id' => $target->id, 'event' => 'login',
            'ip_address' => '196.30.11.5', 'user_agent' => 'Mozilla/5.0 Test',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertSee('Login History')
            ->assertSee('196.30.11.5');
    }

    public function test_admin_without_login_history_permission_does_not_see_ip_data(): void
    {
        $admin  = $this->agent(['role' => 'admin']);
        $target = $this->agent();

        RolePermission::insert([
            ['role' => 'admin', 'permission_key' => 'manage_users', 'scope' => null, 'agency_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();

        LoginHistory::create([
            'user_id' => $target->id, 'event' => 'login',
            'ip_address' => '196.30.11.5', 'user_agent' => 'Mozilla/5.0 Test',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $target))
            ->assertOk()
            ->assertDontSee('196.30.11.5');
    }
}
