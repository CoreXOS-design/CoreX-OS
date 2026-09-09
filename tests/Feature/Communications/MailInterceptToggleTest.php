<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\OutboundMailGuardToggleAudit;
use App\Models\Role;
use App\Models\User;
use App\Services\Communications\MailInterceptToggleService;
use App\Support\OutboundMailGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AT-URGENT-2026-09-09 — the kill switch's write side. "Super users only —
 * CoreX users" and "every change records who, when, direction, and a
 * required free-text reason."
 */
final class MailInterceptToggleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->agency = Agency::create(['name' => 'T ' . uniqid(), 'slug' => 'tt-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'D']);

        // The schema-snapshot test DB carries no seeded role rows — isOwnerRole()
        // resolves via Role::allRoles()->firstWhere('name', ...)->is_owner, which
        // is false for a role that doesn't exist at all, same pattern as
        // EllieReferenceSourceOwnerOnlyTest.
        $ownerRole = Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::clearCache();
    }

    private function superAdmin(): User
    {
        // A real super_admin's own agency_id/branch_id are NULL in production
        // (a global, cross-agency System Owner) — now that the role row
        // genuinely exists, the fixture can match that real shape.
        return User::factory()->create([
            'agency_id' => null,
            'branch_id' => null,
            'role' => 'super_admin',
        ]);
    }

    private function agencyAdmin(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
    }

    // ── Server-side enforcement — the actual safety boundary ────────────────

    public function test_a_crafted_request_from_a_non_super_admin_is_refused_with_403(): void
    {
        $admin = $this->agencyAdmin();

        $response = $this->actingAs($admin)->put(route('settings.email-setup.mail-intercept'), [
            'direction' => 'send',
            'reason' => 'trying to bypass this',
        ]);

        $response->assertForbidden();
        $this->assertNull(OutboundMailGuard::forcedDirection(), 'the setting must be completely untouched by a refused request');
        $this->assertSame(0, OutboundMailGuardToggleAudit::count(), 'a refused request must not write an audit row either');
    }

    public function test_a_super_admin_can_force_the_toggle_over_http(): void
    {
        $owner = $this->superAdmin();

        $response = $this->actingAs($owner)->put(route('settings.email-setup.mail-intercept'), [
            'direction' => 'send',
            'reason' => 'testing against my own mailbox on staging',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertFalse(OutboundMailGuard::forcedDirection());
    }

    public function test_a_request_with_no_reason_is_rejected(): void
    {
        $owner = $this->superAdmin();

        $response = $this->actingAs($owner)->put(route('settings.email-setup.mail-intercept'), [
            'direction' => 'send',
            'reason' => '',
        ]);

        $response->assertSessionHasErrors('reason');
        $this->assertNull(OutboundMailGuard::forcedDirection());
    }

    // ── Visibility — absent from the page, not just disabled ───────────────

    public function test_toggle_block_is_absent_from_the_page_for_an_agency_admin(): void
    {
        $admin = $this->agencyAdmin();

        $response = $this->actingAs($admin)->get(route('settings.email-setup.index'));

        $response->assertOk();
        $response->assertDontSee('Outbound mail — CoreX only');
        $response->assertDontSee('Force intercept ON');
    }

    public function test_toggle_block_is_present_for_a_super_admin(): void
    {
        $owner = $this->superAdmin();
        // A real System Owner has no agency of their own (agency_id NULL) —
        // settings.email-setup.index carries agency.required, so reaching it
        // needs the same agency-switcher override a real owner would use.
        session(['active_agency_id' => $this->agency->id]);

        $response = $this->actingAs($owner)->get(route('settings.email-setup.index'));

        $response->assertOk();
        $response->assertSee('Outbound mail — CoreX only');
    }

    // ── The service — audit trail, required reason ─────────────────────────

    public function test_force_intercept_writes_an_audit_row(): void
    {
        $owner = $this->superAdmin();
        $service = new MailInterceptToggleService();

        $service->forceIntercept($owner, 'Afrihost is blocking us again');

        $this->assertTrue(OutboundMailGuard::forcedDirection());
        $row = OutboundMailGuardToggleAudit::first();
        $this->assertSame($owner->id, $row->user_id);
        $this->assertSame(OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_ON, $row->direction);
        $this->assertSame('Afrihost is blocking us again', $row->reason);
    }

    public function test_force_send_writes_an_audit_row(): void
    {
        $owner = $this->superAdmin();
        $service = new MailInterceptToggleService();

        $service->forceSend($owner, 'testing against my own mailbox');

        $this->assertFalse(OutboundMailGuard::forcedDirection());
        $row = OutboundMailGuardToggleAudit::first();
        $this->assertSame(OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_OFF, $row->direction);
    }

    public function test_blank_reason_is_rejected_by_the_service_directly(): void
    {
        $owner = $this->superAdmin();
        $service = new MailInterceptToggleService();

        $this->expectException(\InvalidArgumentException::class);
        $service->forceIntercept($owner, '   ');
    }

    public function test_clear_override_records_which_direction_the_environment_reverted_to(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);
        $owner = $this->superAdmin();
        $service = new MailInterceptToggleService();

        $service->forceIntercept($owner, 'incident');
        $service->clearOverride($owner, 'fixed, reverting');

        $this->assertNull(OutboundMailGuard::forcedDirection());
        $latest = OutboundMailGuardToggleAudit::latest('id')->first();
        // Production's own default is "send" — clearing an intercept-on
        // override there reverts TO sending, so the audit records that as
        // the effective direction the clear produced.
        $this->assertSame(OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_OFF, $latest->direction);
    }
}
