<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-agency maintenance mode (AT-93, re-scoped).
 * Spec: .ai/specs/maintenance-mode.md
 *
 * Proves the tenant-level behaviour: the CoreX login is NEVER taken down;
 * only the specific agency under maintenance shows the splash, and only AFTER
 * login resolves the user's agency; System Owners bypass; every other agency
 * operates normally; the toggle is reversible and owner-only.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = 'data-page="corex-maintenance"';

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function makeRoles(): void
    {
        $owner = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $owner->is_owner = true;
        $owner->save();

        $agent = Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent', 'sort_order' => 2]);
        $agent->is_owner = false;
        $agent->save();

        Role::clearCache();
    }

    /** @return array{0: Agency, 1: Agency} two live agencies */
    private function makeAgencies(): array
    {
        return [
            Agency::create(['name' => 'Coastal Maintenance Co', 'slug' => 'coastal-maint']),
            Agency::create(['name' => 'Inland Normal Co', 'slug' => 'inland-normal']),
        ];
    }

    private function agentFor(Agency $agency): User
    {
        return User::factory()->create(['role' => 'agent', 'agency_id' => $agency->id]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    // ── The gate ──────────────────────────────────────────────────────

    public function test_user_of_non_maintenance_agency_is_not_blocked(): void
    {
        $this->makeRoles();
        [$maint, $normal] = $this->makeAgencies();
        $maint->enterMaintenance('Back soon.');

        // A user of the OTHER agency must operate normally.
        $this->actingAs($this->agentFor($normal))
            ->get(route('dashboard'))
            ->assertDontSee(self::SENTINEL, false);
    }

    public function test_user_of_maintenance_agency_sees_splash_after_login(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();
        $maint->enterMaintenance('Scheduled upgrade in progress.');

        $res = $this->actingAs($this->agentFor($maint))->get(route('dashboard'));

        $res->assertStatus(503);
        $res->assertSee(self::SENTINEL, false);
        $res->assertSee('Scheduled upgrade in progress.', false);
    }

    public function test_system_owner_bypasses_maintenance_agency(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();
        $maint->enterMaintenance();

        // Owner switched into the maintenance agency still gets through.
        $this->actingAs($this->owner())
            ->withSession(['active_agency_id' => $maint->id])
            ->get(route('dashboard'))
            ->assertDontSee(self::SENTINEL, false);
    }

    public function test_login_url_is_reachable_even_when_an_agency_is_in_maintenance(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();
        $maint->enterMaintenance();

        // Guest: login loads, no splash.
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee(self::SENTINEL, false);

        // A maintenance-agency user can still reach login/logout to sign out.
        $this->actingAs($this->agentFor($maint))
            ->get(route('login'))
            ->assertDontSee(self::SENTINEL, false);
    }

    public function test_guest_hitting_protected_route_is_not_shown_splash(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();
        $maint->enterMaintenance();

        // No user → gate passes → normal auth redirect to login, never the splash.
        $this->get(route('dashboard'))->assertDontSee(self::SENTINEL, false);
    }

    public function test_json_caller_in_maintenance_agency_gets_structured_503(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();
        $maint->enterMaintenance('Down for maintenance.');

        $this->actingAs($this->agentFor($maint))
            ->getJson(route('dashboard'))
            ->assertStatus(503)
            ->assertJson(['ok' => false, 'status' => 'maintenance']);
    }

    // ── Toggle (owner-only) + reversibility ───────────────────────────

    public function test_owner_toggle_on_off_on_restores_access_each_way(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();
        $owner = $this->owner();
        $agent = $this->agentFor($maint);

        // ON
        $this->actingAs($owner)
            ->post(route('agencies.toggle-maintenance', $maint), ['maintenance_message' => 'BRB'])
            ->assertRedirect(route('agencies.index'));
        $this->assertTrue($maint->fresh()->isInMaintenance());
        $this->actingAs($agent)->get(route('dashboard'))->assertStatus(503);

        // OFF → access restored
        $this->actingAs($owner)
            ->post(route('agencies.toggle-maintenance', $maint))
            ->assertRedirect(route('agencies.index'));
        $this->assertFalse($maint->fresh()->isInMaintenance());
        $this->actingAs($agent)->get(route('dashboard'))->assertDontSee(self::SENTINEL, false);

        // ON again
        $this->actingAs($owner)->post(route('agencies.toggle-maintenance', $maint));
        $this->assertTrue($maint->fresh()->isInMaintenance());
        $this->actingAs($agent)->get(route('dashboard'))->assertStatus(503);
    }

    public function test_non_owner_cannot_toggle_agency_maintenance(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();

        $this->actingAs($this->agentFor($maint))
            ->post(route('agencies.toggle-maintenance', $maint))
            ->assertForbidden();

        $this->assertFalse($maint->fresh()->isInMaintenance());
    }

    // ── Artisan escape hatch (per-agency) ─────────────────────────────

    public function test_artisan_escape_hatch_toggles_specific_agency(): void
    {
        $this->makeRoles();
        [$maint, $normal] = $this->makeAgencies();

        $this->artisan('corex:maintenance', ['agency' => $maint->slug, 'action' => 'on'])->assertExitCode(0);
        $this->assertTrue($maint->fresh()->isInMaintenance());
        // The other agency is unaffected.
        $this->assertFalse($normal->fresh()->isInMaintenance());

        $this->artisan('corex:maintenance', ['agency' => $maint->slug, 'action' => 'off'])->assertExitCode(0);
        $this->assertFalse($maint->fresh()->isInMaintenance());
    }

    public function test_enter_maintenance_stamps_start_time_and_message(): void
    {
        $this->makeRoles();
        [$maint] = $this->makeAgencies();

        $maint->enterMaintenance('Custom note');
        $maint->refresh();

        $this->assertTrue($maint->isInMaintenance());
        $this->assertSame('Custom note', $maint->maintenance_message);
        $this->assertNotNull($maint->maintenance_started_at);

        $maint->exitMaintenance();
        $maint->refresh();
        $this->assertFalse($maint->isInMaintenance());
        $this->assertNull($maint->maintenance_started_at);
    }
}
