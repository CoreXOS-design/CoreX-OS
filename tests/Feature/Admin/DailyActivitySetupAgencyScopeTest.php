<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20 follow-up: DailyActivitySetupController::
 * storeDefinition() hardcoded every new row scope='system', agency_id=null --
 * globally visible/usable by every agency regardless of who created it.
 * updateDefinition() updated ANY row by bare id with zero ownership check --
 * any admin with manage_targets (an ordinary, per-agency-grantable permission)
 * could corrupt another agency's activity-points configuration by guessing its
 * id. Fixed: only a System Owner may write/edit scope='system'; everyone else
 * is confined to their own branch's rows.
 */
final class DailyActivitySetupAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyAdmin(string $label): array
    {
        $agency = Agency::create(['name' => $label, 'slug' => strtolower($label) . '-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => $label . ' HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_targets', 'agency_id' => $agency->id],
            []
        );
        PermissionService::clearCache();

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);

        return compact('agency', 'branch', 'admin');
    }

    public function test_a_regular_admin_cannot_create_a_globally_visible_system_activity(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyAdmin('Own');

        $this->actingAs($own['admin'])->post(route('admin.daily-activities.setup.store-definition'), [
            'name' => 'Site visit', 'weight' => 1, 'sort_order' => 1,
        ])->assertRedirect();

        $row = DB::table('activity_definitions')->where('name', 'Site visit')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('system', $row->scope, 'a non-owner admin must never create a globally-visible activity');
        $this->assertSame((string) $own['branch']->id, $row->scope);
        $this->assertSame($own['agency']->id, $row->agency_id);
    }

    public function test_a_regular_admin_cannot_edit_another_agencys_activity(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyAdmin('Own');
        $foreign = $this->makeAgencyAdmin('Foreign');

        $foreignId = DB::table('activity_definitions')->insertGetId([
            'name' => 'Foreign activity', 'weight' => 1, 'sort_order' => 1,
            'scoring_mode' => 'count', 'is_enabled' => 1,
            'scope' => (string) $foreign['branch']->id, 'agency_id' => $foreign['agency']->id,
            'branch_id' => $foreign['branch']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // putJson (not put) so a 404 renders as a JSON error response instead
        // of the HTML error view — the test env has no built Vite manifest.
        $this->actingAs($own['admin'])
            ->putJson(route('admin.daily-activities.setup.update-definition', ['id' => $foreignId]), [
                'name' => 'Hijacked', 'weight' => 0, 'sort_order' => 1,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('activity_definitions', ['id' => $foreignId, 'name' => 'Foreign activity']);
    }

    public function test_a_regular_admin_cannot_edit_a_system_activity(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyAdmin('Own');

        $systemId = DB::table('activity_definitions')->insertGetId([
            'name' => 'System activity', 'weight' => 1, 'sort_order' => 1,
            'scoring_mode' => 'count', 'is_enabled' => 1,
            'scope' => 'system', 'agency_id' => null, 'branch_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($own['admin'])
            ->putJson(route('admin.daily-activities.setup.update-definition', ['id' => $systemId]), [
                'name' => 'Hijacked', 'weight' => 0, 'sort_order' => 1,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('activity_definitions', ['id' => $systemId, 'name' => 'System activity']);
    }

    public function test_a_regular_admin_can_still_edit_their_own_branchs_activity(): void
    {
        PermissionService::forceProductionPosture();
        $own = $this->makeAgencyAdmin('Own');

        $ownId = DB::table('activity_definitions')->insertGetId([
            'name' => 'Own activity', 'weight' => 1, 'sort_order' => 1,
            'scoring_mode' => 'count', 'is_enabled' => 1,
            'scope' => (string) $own['branch']->id, 'agency_id' => $own['agency']->id,
            'branch_id' => $own['branch']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($own['admin'])
            ->put(route('admin.daily-activities.setup.update-definition', ['id' => $ownId]), [
                'name' => 'Renamed', 'weight' => 2, 'sort_order' => 5,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_definitions', ['id' => $ownId, 'name' => 'Renamed']);
    }

    public function test_an_owner_can_still_create_and_edit_a_system_activity(): void
    {
        $ownerRole = Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();
        $owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);

        $this->actingAs($owner)->post(route('admin.daily-activities.setup.store-definition'), [
            'name' => 'Global activity', 'weight' => 1, 'sort_order' => 1,
        ])->assertRedirect();

        $row = DB::table('activity_definitions')->where('name', 'Global activity')->first();
        $this->assertSame('system', $row->scope);
        $this->assertNull($row->agency_id);

        $this->actingAs($owner)
            ->put(route('admin.daily-activities.setup.update-definition', ['id' => $row->id]), [
                'name' => 'Global activity renamed', 'weight' => 3, 'sort_order' => 2,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('activity_definitions', ['id' => $row->id, 'name' => 'Global activity renamed']);
    }
}
