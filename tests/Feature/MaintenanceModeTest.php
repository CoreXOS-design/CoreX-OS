<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Maintenance mode — system-owners-only access (AT-93).
 * Spec: .ai/specs/maintenance-mode.md
 *
 * Covers the full matrix: owner-through, non-owner-blocked,
 * guest-blocked-but-login-reachable, toggle off, CLI escape hatch,
 * owner-only toggle gating, and the malformed-flag absorb path.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Never leak the flag file into the real storage dir / other tests.
        app(MaintenanceMode::class)->disable();
        Role::clearCache();
        parent::tearDown();
    }

    private function ownerUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    private function agentUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent', 'sort_order' => 2]);
        $role->is_owner = false;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'agent']);
    }

    private function maintenance(): MaintenanceMode
    {
        return app(MaintenanceMode::class);
    }

    // ── The gate ──────────────────────────────────────────────────────

    public function test_owner_retains_full_access_during_maintenance(): void
    {
        $this->maintenance()->enable(by: 'test');

        // dev-settings is owner-only; a 200 proves the gate let the owner
        // through (and so did owner_only). Not the down-page.
        $res = $this->actingAs($this->ownerUser())->get(route('admin.dev-settings.index'));

        $res->assertOk();
        $res->assertDontSee('data-page="corex-maintenance"', false);
    }

    public function test_non_owner_is_blocked_with_branded_page_during_maintenance(): void
    {
        $this->maintenance()->enable(by: 'test');

        $res = $this->actingAs($this->agentUser())->get(route('admin.dev-settings.index'));

        $res->assertStatus(503);
        $res->assertSee('data-page="corex-maintenance"', false);
    }

    public function test_guest_is_blocked_during_maintenance(): void
    {
        $this->maintenance()->enable(by: 'test');

        $res = $this->get(route('admin.dev-settings.index'));

        $res->assertStatus(503);
        $res->assertSee('data-page="corex-maintenance"', false);
    }

    public function test_login_route_stays_reachable_during_maintenance(): void
    {
        $this->maintenance()->enable(by: 'test');

        // The whole point of the escape path: an owner must be able to log in.
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-page="corex-maintenance"', false);
    }

    public function test_everyone_is_normal_when_maintenance_off(): void
    {
        $this->assertFalse($this->maintenance()->isActive());

        $this->actingAs($this->agentUser())
            ->get(route('login'))
            ->assertDontSee('data-page="corex-maintenance"', false);
    }

    public function test_json_caller_gets_structured_503_during_maintenance(): void
    {
        $this->maintenance()->enable(by: 'test');

        $this->actingAs($this->agentUser())
            ->getJson(route('admin.dev-settings.index'))
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'status' => 'maintenance']);
    }

    // ── The toggle (owner-only) ───────────────────────────────────────

    public function test_owner_can_enable_and_disable_via_toggle(): void
    {
        $owner = $this->ownerUser();

        $this->actingAs($owner)
            ->post(route('admin.maintenance.enable'))
            ->assertRedirect(route('admin.dev-settings.index'))
            ->assertSessionHas('success');
        $this->assertTrue($this->maintenance()->isActive());

        $this->actingAs($owner)
            ->post(route('admin.maintenance.disable'))
            ->assertRedirect(route('admin.dev-settings.index'))
            ->assertSessionHas('success');
        $this->assertFalse($this->maintenance()->isActive());
    }

    public function test_non_owner_cannot_enable_maintenance(): void
    {
        // Gate is OFF here — proves the toggle itself is owner-gated.
        $this->actingAs($this->agentUser())
            ->post(route('admin.maintenance.enable'))
            ->assertForbidden();

        $this->assertFalse($this->maintenance()->isActive());
    }

    // ── Escape hatch + service robustness ─────────────────────────────

    public function test_artisan_escape_hatch_lifts_maintenance_without_ui(): void
    {
        $this->maintenance()->enable(by: 'test');
        $this->assertTrue($this->maintenance()->isActive());

        $this->artisan('corex:maintenance off')->assertExitCode(0);

        $this->assertFalse($this->maintenance()->isActive());
    }

    public function test_artisan_can_enable_and_report_status(): void
    {
        $this->artisan('corex:maintenance on')->assertExitCode(0);
        $this->assertTrue($this->maintenance()->isActive());

        $this->artisan('corex:maintenance status')->assertExitCode(0);
    }

    public function test_malformed_flag_is_absorbed_not_crashed(): void
    {
        // A garbled flag file must still read as ON with empty meta — never throw.
        file_put_contents($this->maintenance()->flagPath(), '{not valid json');

        $this->assertTrue($this->maintenance()->isActive());
        $this->assertSame([], $this->maintenance()->meta());
    }

    public function test_enable_records_metadata(): void
    {
        $this->maintenance()->enable(by: 'Johan Reichel');

        $meta = $this->maintenance()->meta();
        $this->assertSame('Johan Reichel', $meta['enabled_by'] ?? null);
        $this->assertArrayHasKey('enabled_at', $meta);
    }
}
