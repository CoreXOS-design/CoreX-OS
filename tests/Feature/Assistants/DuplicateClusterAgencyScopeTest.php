<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-267 audit — DuplicateCleanupController::dismiss updated a contact_duplicate_clusters row by id
 * via a raw DB query with NO agency scope (no Eloquent global scope to save it), so any user could
 * dismiss another agency's cluster by id. Now bounded to the caller's effective agency.
 */
final class DuplicateClusterAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_cannot_dismiss_another_agencys_duplicate_cluster(): void
    {
        $agencyA = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $agencyB = Agency::create(['name' => 'Rival', 'slug' => 'riv-' . uniqid()]);
        $branchA = Branch::create(['agency_id' => $agencyA->id, 'name' => 'Margate']);

        $userA = User::factory()->create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);

        $rivalCluster = DB::table('contact_duplicate_clusters')->insertGetId([
            'agency_id'   => $agencyB->id,
            'contact_ids' => json_encode([1, 2]),
            'match_field' => 'email',
            'match_value' => 'dup@example.co.za',
            'status'      => 'pending',
            'created_at'  => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($userA)
            ->post(route('command-center.admin.duplicate-cleanup.dismiss', $rivalCluster));

        // The rival agency's cluster is untouched — still pending.
        $this->assertSame('pending', DB::table('contact_duplicate_clusters')->where('id', $rivalCluster)->value('status'));
    }
}
