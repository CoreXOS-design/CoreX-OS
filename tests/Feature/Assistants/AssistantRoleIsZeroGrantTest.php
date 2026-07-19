<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Database\Seeders\AssistantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * AT-267 — Assistants, Prompt B: the `assistant` role grants NOTHING, and must keep
 * granting nothing forever.
 *
 * THE TRAP THIS GUARDS. `users.role` is NOT NULL DEFAULT 'agent'. There is no "no role"
 * state in CoreX. So a user created for the assistant flow without an explicit role is
 * saved as a FULL AGENT — every agent permission, silently. The `assistant` role exists
 * purely so that an assistant has somewhere safe to stand.
 *
 * That makes "the assistant role has zero grants" a SECURITY invariant, not a tidiness
 * one. It is the last line of defence: PermissionService fails closed on a role with zero
 * grants (AT-265), so if the assistant resolver hook (Prompt C) were ever bypassed by some
 * future code path, an assistant would fall through to their role and get NOTHING — rather
 * than falling through to agent defaults and getting everything.
 *
 * A future `corex:sync-permissions` run, a new permission added to config, or someone
 * "helpfully" filling in the empty `include` array would all quietly break that. Hence
 * this file.
 *
 * Paths proven: the role exists as a global template · it is cloned into every new agency ·
 * it has zero grants on a fresh sync · repeated syncs keep it at zero (idempotent) · a user
 * holding it has NO permissions and NO data scope · admin gets the assistants.* keys
 * automatically via all-minus-exclude · branch_manager does NOT get them by default but the
 * keys ARE registered, so Role Manager can grant them · the keys are real (registered in
 * nexus_permissions), not phantom strings.
 */
final class AssistantRoleIsZeroGrantTest extends TestCase
{
    use RefreshDatabase;

    private const ASSISTANT_KEYS = [
        'assistants.view',
        'assistants.create',
        'assistants.reassign',
        'assistants.revoke',
        'assistants.view_all',
    ];

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        // Provision the role exactly as a deploy does. The role is GLOBAL reference data and
        // travels via AssistantRoleSeeder, registered in `deploy:sync-reference-data` — the
        // migration alone is not enough, because a snapshot-bootstrapped DB (the test suite,
        // migrate:fresh) sees the migration as already-run and never gets the row.
        //
        // Seeding through the real seeder here, rather than hand-inserting a fixture, means
        // these tests exercise the provisioning path that live actually uses.
        Artisan::call('db:seed', ['--class' => AssistantRoleSeeder::class, '--force' => true]);

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);

        PermissionService::clearCache();
        Role::clearCache();
    }

    /**
     * The test DB carries NO roles except `assistant`.
     *
     * `2026_03_06_000002_seed_existing_roles` is a DATA migration, and `schema:dump`
     * captures structure + the migrations ledger, not data rows — so on a snapshot-
     * bootstrapped test DB that migration reads as already-run and never replays. The
     * `assistant` role is present only because ITS migration is newer than the committed
     * snapshot. (This is why PermissionFailClosedTest also builds its own roles.)
     *
     * So any test that asserts on a real role's grants has to stand that role up itself,
     * exactly as live has it — otherwise it is asserting against a role that does not exist
     * and would pass or fail for the wrong reason.
     */
    private function realWorldRole(string $name): Role
    {
        return Role::create([
            'name'      => $name,
            'label'     => ucfirst($name),
            'agency_id' => $this->agency->id,
        ]);
    }

    public function test_the_assistant_role_exists_as_a_global_template(): void
    {
        $template = Role::withoutGlobalScopes()
            ->whereNull('agency_id')
            ->where('name', 'assistant')
            ->first();

        $this->assertNotNull($template, 'The assistant role template must ship with the migration — a seeder would never reach live on a git-pull deploy.');
        $this->assertFalse((bool) $template->is_owner, 'An assistant role that is an owner role would bypass every permission check.');
        $this->assertFalse((bool) $template->can_be_deleted, 'Deleting the assistant role would leave existing assistants standing on nothing.');
    }

    public function test_a_new_agency_gets_its_own_copy_of_the_assistant_role(): void
    {
        // RoleProvisioningService clones the templates on Agency::created.
        $fresh = Agency::create(['name' => 'Coastal Realty', 'slug' => 'cr-' . uniqid()]);

        $this->assertTrue(
            Role::withoutGlobalScopes()->where('agency_id', $fresh->id)->where('name', 'assistant')->exists(),
            'Every agency needs its own assistant role row — roles are agency-scoped.'
        );
    }

    public function test_the_assistant_role_has_zero_grants(): void
    {
        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);

        $this->assertSame(
            0,
            RolePermission::withoutGlobalScopes()->where('role', 'assistant')->count(),
            'The assistant role must never hold a single grant. Its permissions come from the assignment matrix.'
        );
    }

    public function test_repeated_syncs_keep_the_assistant_role_at_zero(): void
    {
        // The deploy runs `corex:sync-permissions --merge-defaults` on every promotion.
        // It must never drift a key into this role.
        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);

        $this->assertSame(0, RolePermission::withoutGlobalScopes()->where('role', 'assistant')->count());
    }

    public function test_a_user_holding_the_assistant_role_has_no_permissions_and_no_data_scope(): void
    {
        // Stand up a POPULATED grants table first. Without this the assertion below would
        // pass for the wrong reason: an empty role_permissions table denies everything by
        // itself (AT-265 fail-closed), so an assistant role that secretly held grants would
        // still look clean. We need a system where other roles demonstrably DO have grants,
        // and the assistant still has none.
        $this->realWorldRole('admin');

        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        PermissionService::clearCache();
        Role::clearCache();
        PermissionService::forceProductionPosture();

        $this->assertTrue(
            RolePermission::withoutGlobalScopes()->where('role', 'admin')->exists(),
            'Guard: the grants table must be populated, or this test proves nothing.'
        );

        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'assistant',
        ]);

        // The role alone grants nothing — not a menu, not a record, not a scope. An
        // assistant with no assignment is a user who can do nothing, which is the
        // safe direction to fail.
        foreach (['view_dashboard', 'access_properties', 'properties.create', 'contacts.view', 'assistants.create'] as $key) {
            $this->assertFalse($user->hasPermission($key), "The bare assistant role must not grant [{$key}].");
        }

        $this->assertNull(PermissionService::getDataScope($user, 'contacts'));
        $this->assertNull(PermissionService::getDataScope($user, 'properties'));
    }

    public function test_admin_gets_the_assistants_keys_automatically(): void
    {
        $this->realWorldRole('admin');

        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        PermissionService::clearCache();
        Role::clearCache();
        PermissionService::forceProductionPosture();

        $admin = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);

        // D5: admin is all-minus-exclude, so it inherits every new key with no config edit.
        foreach (self::ASSISTANT_KEYS as $key) {
            $this->assertTrue($admin->hasPermission($key), "Admin must hold [{$key}].");
        }
    }

    public function test_branch_manager_does_not_get_them_by_default_but_they_are_grantable(): void
    {
        $this->realWorldRole('branch_manager');

        Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);
        PermissionService::clearCache();
        Role::clearCache();
        PermissionService::forceProductionPosture();

        $bm = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'branch_manager']);

        // D5, half one: nothing out of the box.
        $this->assertFalse($bm->hasPermission('assistants.create'));

        // D5, half two: the key is REGISTERED, so Role Manager renders it and an admin can
        // switch it on for branch_manager without a code change. This is the whole point of
        // declaring the keys in config rather than hardcoding the admin check.
        RolePermission::create([
            'role'           => 'branch_manager',
            'permission_key' => 'assistants.create',
            'agency_id'      => $this->agency->id,
        ]);
        PermissionService::clearCache();

        $this->assertTrue($bm->fresh()->hasPermission('assistants.create'));
    }

    public function test_the_assistants_keys_are_real_registered_permissions(): void
    {
        // Role Manager validates a submitted key against nexus_permissions before writing it.
        // A key that is not registered there cannot be granted from the UI at all — it would
        // be a phantom permission that silently never applies.
        Artisan::call('corex:sync-permissions');

        foreach (self::ASSISTANT_KEYS as $key) {
            $this->assertDatabaseHas('nexus_permissions', ['key' => $key, 'section' => 'assistants']);
        }
    }
}
