<?php

namespace Tests\Feature\Admin;

use App\Models\DevSetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\SystemOwnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Demo sidebar curation + permanent System Owner login.
 * Spec: .ai/specs/demo-sidebar-curation.md
 */
class DemoSidebarCurationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function ownerUser(): User
    {
        // owner_only middleware checks the role's is_owner flag. is_owner is
        // not fillable, so set it explicitly after create.
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    public function test_system_owner_seeder_creates_permanent_owner_login(): void
    {
        (new SystemOwnerSeeder())->run();

        $user = User::where('email', SystemOwnerSeeder::EMAIL)->first();

        $this->assertNotNull($user, 'Permanent System Owner must be seeded');
        $this->assertSame('super_admin', $user->role);
        $this->assertTrue((bool) $user->is_active);
        $this->assertNull($user->agency_id, 'System Owner is a platform identity, not a tenant member');
        $this->assertTrue(Hash::check(SystemOwnerSeeder::PASSWORD, $user->password));
    }

    public function test_system_owner_seeder_is_idempotent(): void
    {
        (new SystemOwnerSeeder())->run();
        (new SystemOwnerSeeder())->run();

        $this->assertSame(1, User::where('email', SystemOwnerSeeder::EMAIL)->count());
    }

    /**
     * users.email is utf8mb4_unicode_ci — case-INSENSITIVE — under a UNIQUE
     * index, so a tenant login differing only in capitalisation is the SAME ROW
     * as the System Owner. Without the guard, updateOrCreate matches that tenant
     * user and rewrites it into the platform owner, nulling its agency_id and
     * detaching that agency's data. Seeding must fail loudly, never corrupt.
     */
    public function test_system_owner_seeder_refuses_to_hijack_a_tenant_user_with_the_same_email(): void
    {
        $tenantAdmin = User::factory()->create([
            'email'     => strtolower(SystemOwnerSeeder::EMAIL), // differs only by case
            'role'      => 'admin',
            'agency_id' => 1,
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            (new SystemOwnerSeeder())->run();
        } finally {
            $tenantAdmin->refresh();
            $this->assertSame(1, $tenantAdmin->agency_id, 'tenant admin must not be detached from its agency');
            $this->assertSame('admin', $tenantAdmin->role, 'tenant admin must not be promoted to owner');
        }
    }

    /** The demo dataset's admin must never squat the owner's reserved address. */
    public function test_demo_admin_email_does_not_collide_with_the_system_owner(): void
    {
        $demoLoginEmail = (new \ReflectionClass(DemoDataSeeder::class))
            ->getConstant('DEMO_LOGIN_EMAIL');

        $this->assertNotSame(
            strtolower(SystemOwnerSeeder::EMAIL),
            strtolower($demoLoginEmail),
            'DemoDataSeeder::DEMO_LOGIN_EMAIL case-collides with SystemOwnerSeeder::EMAIL. '
            . 'users.email is case-insensitive under a UNIQUE index — these are the same row, '
            . 'and seeding both silently detaches the demo agency from its admin.'
        );
    }

    public function test_owner_can_save_demo_sidebar_visibility(): void
    {
        $owner = $this->ownerUser();
        $keys = ['g:real-estate', 'p:/corex/properties'];

        $this->actingAs($owner)
            ->put(route('admin.dev-settings.demo-sidebar.update'), ['keys' => $keys])
            ->assertRedirect(route('admin.dev-settings.demo-sidebar'))
            ->assertSessionHas('success');

        $this->assertEqualsCanonicalizing($keys, DevSetting::demoHiddenSidebar());
    }

    public function test_saving_with_no_keys_clears_the_list(): void
    {
        DevSetting::set('demo_hidden_sidebar', json_encode(['g:compliance']));
        $owner = $this->ownerUser();

        $this->actingAs($owner)
            ->put(route('admin.dev-settings.demo-sidebar.update'), [])
            ->assertRedirect(route('admin.dev-settings.demo-sidebar'));

        $this->assertSame([], DevSetting::demoHiddenSidebar());
    }

    public function test_non_owner_cannot_save_demo_sidebar(): void
    {
        $user = User::factory()->create(['role' => 'agent']);

        $this->actingAs($user)
            ->put(route('admin.dev-settings.demo-sidebar.update'), ['keys' => ['g:compliance']])
            ->assertForbidden();
    }

    public function test_non_owner_cannot_view_demo_sidebar_page(): void
    {
        $user = User::factory()->create(['role' => 'agent']);

        $this->actingAs($user)
            ->get(route('admin.dev-settings.demo-sidebar'))
            ->assertForbidden();
    }

    public function test_dev_settings_index_links_to_demo_sidebar_page(): void
    {
        $owner = $this->ownerUser();

        $this->actingAs($owner)
            ->get(route('admin.dev-settings.index'))
            ->assertOk()
            ->assertSee('Demo sidebar settings')
            ->assertSee(route('admin.dev-settings.demo-sidebar'), false);
    }

    public function test_demo_sidebar_page_renders_curator(): void
    {
        $owner = $this->ownerUser();

        $this->actingAs($owner)
            ->get(route('admin.dev-settings.demo-sidebar'))
            ->assertOk()
            ->assertSee('Demo sidebar visibility')
            ->assertSee('Save Demo Sidebar');
    }

    public function test_enabling_demo_mode_requires_correct_password(): void
    {
        $owner = $this->ownerUser();

        // Missing/blank password — demo mode must NOT turn on.
        $this->actingAs($owner)
            ->put(route('admin.dev-settings.update'), ['demo_mode_enabled' => '1'])
            ->assertSessionHasErrors('demo_toggle_password');
        $this->assertFalse(DevSetting::bool('demo_mode_enabled'));

        // Wrong password — still off.
        $this->actingAs($owner)
            ->put(route('admin.dev-settings.update'), ['demo_mode_enabled' => '1', 'demo_toggle_password' => 'nope'])
            ->assertSessionHasErrors('demo_toggle_password');
        $this->assertFalse(DevSetting::bool('demo_mode_enabled'));

        // Correct password — demo mode turns on.
        $this->actingAs($owner)
            ->put(route('admin.dev-settings.update'), ['demo_mode_enabled' => '1', 'demo_toggle_password' => 'Demo@on&off@$'])
            ->assertSessionHas('success');
        $this->assertTrue(DevSetting::bool('demo_mode_enabled'));
    }

    public function test_disabling_demo_mode_also_requires_password(): void
    {
        DevSetting::set('demo_mode_enabled', '1');
        $owner = $this->ownerUser();

        // Wrong password — must stay ON.
        $this->actingAs($owner)
            ->put(route('admin.dev-settings.update'), ['demo_mode_enabled' => '0', 'demo_toggle_password' => 'wrong'])
            ->assertSessionHasErrors('demo_toggle_password');
        $this->assertTrue(DevSetting::bool('demo_mode_enabled'));

        // Correct password — turns off.
        $this->actingAs($owner)
            ->put(route('admin.dev-settings.update'), ['demo_mode_enabled' => '0', 'demo_toggle_password' => 'Demo@on&off@$'])
            ->assertSessionHas('success');
        $this->assertFalse(DevSetting::bool('demo_mode_enabled'));
    }

    public function test_changing_other_settings_needs_no_demo_password(): void
    {
        $owner = $this->ownerUser();

        // demo_mode unchanged (stays off) — compliance toggle saves freely.
        $this->actingAs($owner)
            ->put(route('admin.dev-settings.update'), ['compliance_checks_disabled' => '1', 'demo_mode_enabled' => '0'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertTrue(DevSetting::bool('compliance_checks_disabled'));
    }
}
