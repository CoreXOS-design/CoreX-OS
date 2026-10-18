<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §5.2 / conductor ruling 2026-09-15 — the expiry-notice
 * window is agency-configurable with a sensible default (60 days), never
 * hardcoded, and ships with its own control from day one. Verified for real
 * via a live HTTP walk (settings screen + wizard step, both save paths) in
 * addition to this automated coverage — see the commit message.
 */
final class LeaseSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_60_days_when_nothing_saved(): void
    {
        self::assertSame(60, LeaseSetting::expiryNoticeWindowDaysFor(null));
        self::assertSame(60, LeaseSetting::expiryNoticeWindowDaysFor(999999));
    }

    public function test_saved_value_overrides_the_default(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);

        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 45]);

        self::assertSame(45, LeaseSetting::expiryNoticeWindowDaysFor($agency->id));
    }

    public function test_settings_screen_saves_a_new_value(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $response = $this->actingAs($user)->get(route('corex.settings.leases.edit'));
        $response->assertOk();
        $response->assertSee('value="60"', false);

        $this->actingAs($user)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 90,
        ])->assertRedirect(route('corex.settings.leases.edit'));

        self::assertSame(90, LeaseSetting::expiryNoticeWindowDaysFor($agency->id));

        $this->actingAs($user)->get(route('corex.settings.leases.edit'))
            ->assertSee('value="90"', false);
    }

    // ── deposit default multiple, 2026-09-22 (property 4283) ────────────

    public function test_default_deposit_months_defaults_to_one_when_nothing_saved(): void
    {
        self::assertSame(1.0, LeaseSetting::defaultDepositMonthsFor(null));
        self::assertSame(1.0, LeaseSetting::defaultDepositMonthsFor(999999));
    }

    public function test_the_settings_page_saves_a_new_deposit_multiple(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $this->actingAs($user)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60,
            'default_deposit_months' => 1.5,
        ])->assertSessionDoesntHaveErrors();

        self::assertSame(1.5, LeaseSetting::defaultDepositMonthsFor($agency->id));
    }

    /**
     * §6.1 guard, proven directly: a request that omits
     * default_deposit_months entirely (not just an old test fixture — a
     * genuine partial post) must still save the fields it DOES carry, not
     * 422 or silently blank an existing agency-configured multiple.
     */
    public function test_omitting_the_deposit_multiple_never_blocks_the_rest_of_the_save(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        LeaseSetting::create(['agency_id' => $agency->id, 'default_deposit_months' => 2]);

        $this->actingAs($user)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 75,
        ])->assertSessionDoesntHaveErrors();

        self::assertSame(75, LeaseSetting::expiryNoticeWindowDaysFor($agency->id), 'the field this request DID carry must still save');
        self::assertSame(2.0, LeaseSetting::defaultDepositMonthsFor($agency->id), 'omitted from the request -- must be left exactly as it was, not blanked');
    }

    // ── Property status follows the lease: the four switches have a control (cross-cutting audit 2026-10-08) ──

    private function adminOf(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    public function test_the_status_switches_default_to_on_and_active_and_show_on_the_settings_page(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $admin = $this->adminOf($agency);

        self::assertTrue(LeaseSetting::autoReadvertiseOnNoticeFor($agency->id));
        self::assertTrue(LeaseSetting::autoRestoreStatusOnLeaseEndedFor($agency->id));
        self::assertTrue(LeaseSetting::autoRestoreStatusOnLeaseCancelledFor($agency->id));
        self::assertSame('active', LeaseSetting::defaultPreLetStatusFor($agency->id));

        $this->actingAs($admin)->get(route('corex.settings.leases.edit'))->assertOk()
            ->assertSee('Property status when a lease changes')
            ->assertSee('name="auto_readvertise_on_notice"', false)
            ->assertSee('name="auto_restore_status_on_lease_ended"', false)
            ->assertSee('name="auto_restore_status_on_lease_cancelled"', false)
            ->assertSee('name="default_pre_let_status"', false);
    }

    public function test_the_settings_page_saves_each_status_switch_and_the_on_market_status(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $admin = $this->adminOf($agency);

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60,
            'auto_readvertise_on_notice' => '0',
            'auto_restore_status_on_lease_ended' => '0',
            'auto_restore_status_on_lease_cancelled' => '1',
            'default_pre_let_status' => 'draft',
        ])->assertRedirect(route('corex.settings.leases.edit'))->assertSessionHasNoErrors();

        self::assertFalse(LeaseSetting::autoReadvertiseOnNoticeFor($agency->id));
        self::assertFalse(LeaseSetting::autoRestoreStatusOnLeaseEndedFor($agency->id));
        self::assertTrue(LeaseSetting::autoRestoreStatusOnLeaseCancelledFor($agency->id));
        self::assertSame('draft', LeaseSetting::defaultPreLetStatusFor($agency->id));
    }

    public function test_a_save_that_omits_the_status_switches_never_wipes_them(): void
    {
        // The wizard step posts a SUBSET of fields (agency-onboarding-setup.md section 6.1): absent = "not shown", never "off".
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $admin = $this->adminOf($agency);
        LeaseSetting::create([
            'agency_id' => $agency->id, 'expiry_notice_window_days' => 60,
            'auto_readvertise_on_notice' => false, 'auto_restore_status_on_lease_ended' => false,
            'auto_restore_status_on_lease_cancelled' => false, 'default_pre_let_status' => 'draft',
        ]);

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 75])->assertSessionHasNoErrors();

        self::assertSame(75, LeaseSetting::expiryNoticeWindowDaysFor($agency->id));
        self::assertFalse(LeaseSetting::autoReadvertiseOnNoticeFor($agency->id));
        self::assertFalse(LeaseSetting::autoRestoreStatusOnLeaseEndedFor($agency->id));
        self::assertFalse(LeaseSetting::autoRestoreStatusOnLeaseCancelledFor($agency->id));
        self::assertSame('draft', LeaseSetting::defaultPreLetStatusFor($agency->id));
    }

    public function test_a_status_the_agency_does_not_have_is_rejected(): void
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $admin = $this->adminOf($agency);

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60, 'default_pre_let_status' => 'definitely_not_a_status',
        ])->assertSessionHasErrors('default_pre_let_status');

        self::assertSame('active', LeaseSetting::defaultPreLetStatusFor($agency->id));
    }

    public function test_the_three_status_switches_are_in_the_setup_wizard_leases_step(): void
    {
        $keys = collect(config('agency-onboarding-copy.leases.controls', []))->pluck('key')->all();

        foreach (['auto_readvertise_on_notice', 'auto_restore_status_on_lease_ended', 'auto_restore_status_on_lease_cancelled'] as $key) {
            self::assertContains($key, $keys, "{$key} must be a control on the leases wizard step");
        }
    }
}
