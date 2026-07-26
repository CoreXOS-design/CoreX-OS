<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityPoints;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SPINE-SETTINGS — who may reach /corex/admin/activity-mappings, and what a
 * System Owner sees when they have not picked an agency yet.
 *
 * THE BUG THIS LOCKS DOWN
 *   The route group carried NO middleware. A System Owner (super_admin,
 *   agency_id NULL) sailed through the controller's permission check via the
 *   owner bypass and then hit `agencyId()`, which abort(403)'d on a null
 *   agency. errors/403.blade.php renders "You don't have permission to access
 *   this page" for EVERY 403 regardless of message — so the platform owner was
 *   told they lacked permission on a screen they own outright.
 *
 *   The right answer for "owner with no agency" is the agency switcher, which
 *   is what `agency.required` (RequireAgencyContext) does. The permission key
 *   also now sits on the route, per non-negotiable #5 (route middleware AND
 *   controller check).
 *
 * NOTE ON THE GRANTS TABLE
 *   The suite convention is an unseeded `role_permissions` = allow-all
 *   (AT-265). The denial tests here therefore seed a grant row first, so
 *   grantsExist() is true and the permission gate actually evaluates.
 */
final class ActivityMappingAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Role::allRoles() memoises into a static that outlives RefreshDatabase's
     * truncation — a stale entry turns an owner into a non-owner (or worse).
     */
    protected function setUp(): void
    {
        parent::setUp();
        Role::clearCache();
        PermissionService::clearCache();
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        PermissionService::clearCache();
        parent::tearDown();
    }

    // ── The reported bug ──────────────────────────────────────────────────

    public function test_system_owner_without_an_agency_is_sent_to_the_agency_switcher_not_403(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->get(route('admin.activity-mappings.index'))
            ->assertRedirect(route('agency.select'));

        $this->assertSame(
            route('admin.activity-mappings.index'),
            session('intended_after_agency_select'),
            'The switcher must send the owner back to Activity Scoring after they pick an agency.'
        );
    }

    public function test_system_owner_with_an_agency_selected_gets_the_page(): void
    {
        $owner  = $this->makeOwner();
        $agency = $this->makeAgency();

        $this->actingAs($owner)
            ->withSession(['active_agency_id' => $agency])
            ->get(route('admin.activity-mappings.index'))
            ->assertOk()
            ->assertViewIs('admin.activity-mappings.index');
    }

    /** The AJAX save path must say what is wrong, not 403 into a blank fetch. */
    public function test_owner_without_an_agency_gets_a_422_on_the_json_save(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->putJson(route('admin.activity-mappings.update', ['id' => 1]), ['value_per_event' => 5])
            ->assertStatus(422);
    }

    // ── Role mapping ──────────────────────────────────────────────────────

    public function test_an_admin_holding_the_key_reaches_the_page(): void
    {
        $agency = $this->makeAgency();
        $this->grant('admin', 'manage_activity_mappings');

        $this->actingAs($this->makeUser('admin', $agency))
            ->get(route('admin.activity-mappings.index'))
            ->assertOk();
    }

    public function test_a_role_without_the_key_is_forbidden(): void
    {
        $agency = $this->makeAgency();
        // Seed SOMETHING so the grants table is not empty (unseeded = allow-all),
        // but nothing that grants the agent this key.
        $this->grant('admin', 'manage_activity_mappings');
        $this->grant('agent', 'view_dashboard');

        $this->actingAs($this->makeUser('agent', $agency))
            ->get(route('admin.activity-mappings.index'))
            ->assertForbidden();
    }

    public function test_a_role_without_the_key_cannot_write_a_scoring_row(): void
    {
        $agency = $this->makeAgency();
        $this->grant('admin', 'manage_activity_mappings');
        $this->grant('agent', 'view_dashboard');

        $this->actingAs($this->makeUser('agent', $agency))
            ->post(route('admin.activity-mappings.toggle-active', ['id' => 1]))
            ->assertForbidden();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeAgency(): int
    {
        $agency = Agency::create([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
        ]);

        DB::table('branches')->insert([
            'id' => $agency->id, 'agency_id' => $agency->id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $agency->id;
    }

    /** A System Owner: global is_owner role row, NULL agency_id, no branch. */
    private function makeOwner(): User
    {
        // forceFill, not create()'s $values: `is_owner` is not in Role::$fillable,
        // so mass assignment drops it and the role lands is_owner = NULL — which
        // makes isOwnerRole() quietly false and voids the whole premise here.
        $role = Role::query()->firstOrNew(['name' => 'super_admin', 'agency_id' => null]);
        $role->forceFill([
            'label'          => 'System Owner',
            'is_owner'       => true,
            'can_be_deleted' => false,
        ])->save();
        Role::clearCache();

        return User::factory()->create([
            'role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => 1,
        ]);
    }

    private function makeUser(string $role, int $agencyId): User
    {
        Role::query()->firstOrCreate(
            ['name' => $role, 'agency_id' => null],
            ['label' => ucfirst($role), 'can_be_deleted' => true, 'sort_order' => 1]
        );
        Role::clearCache();

        return User::factory()->create([
            'role' => $role, 'agency_id' => $agencyId, 'branch_id' => $agencyId, 'is_active' => 1,
        ]);
    }

    /** A global-template grant (agency_id NULL) — what grantsAgencyId() falls back to. */
    private function grant(string $role, string $key): void
    {
        RolePermission::firstOrCreate([
            'role' => $role, 'permission_key' => $key, 'agency_id' => null,
        ]);
        PermissionService::clearCache();
    }
}
