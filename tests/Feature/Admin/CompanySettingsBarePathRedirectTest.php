<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every in-app link to Company Settings uses route('admin.company-settings'),
 * which has always resolved under the app's /corex prefix. An admin who types
 * or bookmarks the intuitive bare /admin/company-settings path got a dead-end
 * 404 instead (2026-10-05, reported by Johan/cc4 on QA1). Fix: a GET-only
 * redirect from the bare path to the real route. No permission, controller,
 * or behaviour change — only the bare path's fate changes.
 */
final class CompanySettingsBarePathRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_path_redirects_to_the_real_company_settings_route(): void
    {
        $agency = Agency::create(['name' => 'Bare Path Co', 'slug' => 'bare-path-' . uniqid()]);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_performance_settings', 'agency_id' => $agency->id],
            []
        );
        Role::clearCache();
        PermissionService::clearCache();

        $admin = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin', 'is_active' => true]);

        $resp = $this->actingAs($admin)->get('/admin/company-settings');

        $resp->assertRedirect(route('admin.company-settings'));
    }

    public function test_bare_path_requires_auth_same_as_the_real_route(): void
    {
        $resp = $this->get('/admin/company-settings');

        $resp->assertRedirect(route('login'));
    }
}
