<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\TvAccessCode;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-agency isolation audit 2026-08-20, finding C1: Admin\TvCodeController's
 * generate()/revoke() validated branch_id/code_id with Laravel's exists: rule
 * only -- an unscoped existence check -- so any admin with manage_tv_messages
 * (not owner-only) could mint a live public TV code for another agency's
 * branch, or revoke another agency's active codes. Fixed by routing branch_id
 * through Branch::findOrFail (Branch uses BelongsToAgency) and adding
 * BelongsToAgency to TvAccessCode itself.
 */
final class TvCodeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgencyAdmin(string $label): array
    {
        $agency = Agency::create(['name' => $label, 'slug' => strtolower($label) . '-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => $label . ' HQ']);
        Role::create(['name' => 'admin', 'label' => 'Administrator', 'agency_id' => $agency->id]);
        RolePermission::updateOrCreate(
            ['role' => 'admin', 'permission_key' => 'manage_tv_messages', 'agency_id' => $agency->id],
            []
        );
        PermissionService::clearCache();

        $admin = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        return compact('agency', 'branch', 'admin');
    }

    public function test_admin_cannot_mint_a_tv_code_for_another_agencys_branch(): void
    {
        PermissionService::forceProductionPosture();

        $own = $this->makeAgencyAdmin('Own');
        $foreign = $this->makeAgencyAdmin('Foreign');

        $response = $this->actingAs($own['admin'])->postJson(route('admin.tv-code.generate'), [
            'branch_id' => $foreign['branch']->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseMissing('tv_access_codes', [
            'branch_id' => $foreign['branch']->id,
        ]);
    }

    public function test_admin_cannot_revoke_another_agencys_tv_code(): void
    {
        PermissionService::forceProductionPosture();

        $own = $this->makeAgencyAdmin('Own');
        $foreign = $this->makeAgencyAdmin('Foreign');

        $foreignCode = TvAccessCode::create([
            'branch_id' => $foreign['branch']->id,
            'agency_id' => $foreign['agency']->id,
            'code' => '123456',
            'created_by' => $foreign['admin']->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($own['admin'])->postJson(route('admin.tv-code.revoke'), [
            'code_id' => $foreignCode->id,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('tv_access_codes', [
            'id' => $foreignCode->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_still_mint_and_revoke_a_code_for_their_own_branch(): void
    {
        PermissionService::forceProductionPosture();

        $own = $this->makeAgencyAdmin('Own');

        $generate = $this->actingAs($own['admin'])->postJson(route('admin.tv-code.generate'), [
            'branch_id' => $own['branch']->id,
        ]);
        $generate->assertStatus(302);

        $code = TvAccessCode::where('branch_id', $own['branch']->id)->where('is_active', true)->firstOrFail();
        $this->assertSame($own['agency']->id, $code->agency_id);

        $revoke = $this->actingAs($own['admin'])->postJson(route('admin.tv-code.revoke'), [
            'code_id' => $code->id,
        ]);
        $revoke->assertStatus(302);

        $this->assertDatabaseHas('tv_access_codes', [
            'id' => $code->id,
            'is_active' => false,
        ]);
    }

    public function test_generated_codes_are_unique_across_agencies_not_just_within_one(): void
    {
        PermissionService::forceProductionPosture();

        $own = $this->makeAgencyAdmin('Own');
        $foreign = $this->makeAgencyAdmin('Foreign');

        TvAccessCode::create([
            'branch_id' => $foreign['branch']->id,
            'agency_id' => $foreign['agency']->id,
            'code' => '555555',
            'created_by' => $foreign['admin']->id,
            'is_active' => true,
        ]);

        // generateUniqueCode() must check uniqueness globally (queryWithoutAgencyScope),
        // not just within the caller's own agency -- otherwise two agencies could hold
        // the same active code, and the public /tv/verify lookup (which has no agency
        // context at all) would resolve to whichever row MySQL returns first.
        $this->actingAs($own['admin']);
        for ($i = 0; $i < 20; $i++) {
            $code = TvAccessCode::generateUniqueCode();
            $this->assertNotSame('555555', $code);
        }
    }
}
