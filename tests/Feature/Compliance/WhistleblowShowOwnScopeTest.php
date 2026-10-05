<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\WhistleblowComplaint;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Audit fix M3: show() applies the same own-scope as index(). */
final class WhistleblowShowOwnScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_cannot_open_a_colleagues_complaint(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Seed the grants table so the permission check is real (an unseeded table is allow-all in the
        // test suite): agents may open the whistleblow area but do NOT hold view_all_agency.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'compliance.whistleblow.view', 'scope' => null, 'agency_id' => null]);
        PermissionService::clearCache();

        $owner = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $other = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        $complaint = WhistleblowComplaint::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'reported_by_user_id' => $owner->id,
            'tier' => 'tier_1', 'property_address' => '1 Test St', 'status' => 'pending_approval',
        ]);

        $this->actingAs($other)->get(route('compliance.whistleblow.show', $complaint))->assertForbidden();
    }
}
