<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\BackupPasswordReveal;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-163 — System Developer → Backups page.
 * Spec: .ai/specs/wa-media-durability-transcription.md PART 8.
 *
 * Permission convention (mirrors SoftDeletesRegisterTest): with role_permissions
 * unseeded the PermissionService grants every permission, so a factory user
 * passes view_backups / reveal_backup_password. Cache cleared in tearDown.
 */
class BackupPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function makeUser(): User
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
        ]);
    }

    /** Page renders without error regardless of the on-disk backup state (robustness). */
    public function test_index_renders(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee('Backups')
            ->assertSee('Status');   // the Status & Health card heading
    }

    /**
     * Doctrine: a reveal is ALWAYS audit-logged, even if reading the secret then
     * fails (e.g. the sudo helper is unavailable in CI). The row lands first.
     */
    public function test_reveal_always_writes_an_audit_row(): void
    {
        $user = $this->makeUser();
        $this->assertSame(0, BackupPasswordReveal::count());

        $resp = $this->actingAs($user)->post(route('admin.backups.reveal'));
        $resp->assertRedirect();

        $this->assertSame(1, BackupPasswordReveal::count());
        $row = BackupPasswordReveal::first();
        $this->assertSame($user->id, $row->revealed_by);
        $this->assertNotNull($row->revealed_at);
    }

    /** Threshold is configurable and persisted to the global setting. */
    public function test_update_threshold_persists_valid_value(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('admin.backups.threshold'), ['stale_alarm_hours' => 48])
            ->assertRedirect();

        $this->assertSame('48', PerformanceSetting::get('backup_stale_alarm_hours'));
    }

    /** Malformed threshold is rejected, nothing persisted (input-space rule). */
    public function test_update_threshold_rejects_out_of_range(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('admin.backups.threshold'), ['stale_alarm_hours' => 0])
            ->assertSessionHasErrors('stale_alarm_hours');

        $this->assertNull(PerformanceSetting::get('backup_stale_alarm_hours'));
    }
}
