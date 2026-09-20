<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalInspectionSetting;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conductor's ruling, 2026-09-20 — the worst failure shape there is, proved
 * live by cc1 as a real authenticated admin: a saver on a shared wizard step
 * genuinely flashed "That did not save — please try again" into the session
 * via its own has()-guard (RentalApplicationSettingsController::
 * updateFieldDisplayConfig(), field_display_submitted absent), the request
 * redirected FORWARD to the next step rather than back, session('success')
 * was set to "Saved.", and the page the user landed on returned 200 with
 * zero trace of the error. An agency owner is told something saved when it
 * never did, and nothing ever connects the two events.
 *
 * Root cause: AgencySetupWizardController::save() called each saver and
 * discarded the return value entirely — only a THROWN exception was ever
 * noticed. A saver returning redirect()->withErrors([...]) instead of
 * throwing was invisible to the loop, which kept running, marked the step
 * complete, and advanced.
 *
 * Fix, as a class, not a patch for this one saver: every saver's call is
 * followed by an immediate check of session('errors') (cleared right
 * before, so a hit can only be that saver's own action). A hit is converted
 * into the same ValidationException a validate() failure already produces.
 * The whole step's savers run inside one DB transaction, so ANY failure —
 * thrown or converted — rolls back everything the step would otherwise
 * have written; the user stays on the same step. wizard.blade.php gained a
 * top-of-form @if($errors->any()) banner, since no view rendered the
 * 'errors' bag at all before this — a fix that stages an error nobody
 * displays is the same bug with extra steps.
 *
 * This is a mechanism fix in AgencySetupWizardController::save() itself, so
 * it protects EVERY saver on EVERY shared step, not just the one that
 * surfaced it — proved here specifically via the same step and saver cc1
 * reproduced against, but the fix lives one level up from any single step.
 */
final class AgencySetupWizardAtomicSaveTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function fullValidPayload(): array
    {
        return [
            'expiry_notice_window_days' => 99,
            'fault_report_window_days' => 7,
            'out_inspection_signing_window_days' => 7,
            'no_approval_spend_threshold' => 500,
            'shown_field_keys' => collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all(),
            'required_field_keys' => [],
            'field_display_submitted' => '1',
            'required_fields_submitted' => '1',
            'return_gate_method' => 'id_number',
            'return_gate_attempt_max' => 6,
            'return_gate_attempt_window_minutes' => 15,
            'lock_property_after_submission' => '1',
            'tag_contact_as_tenant_on_approval' => '1',
            'require_fica_before_authorisation' => '0',
            'document_uploads_open_after_approval' => '1',
        ];
    }

    /**
     * cc1's exact reproduction: a genuinely valid submission with ONE
     * omitted marker (field_display_submitted) — the has()-guard case that
     * used to be silently absorbed. Proves the whole chain: redirect stays
     * on the SAME step, the error is visible in session AND on the
     * rendered page, and nothing from this request — not even the earlier
     * savers that would have committed before the failing one ran — landed
     * in the database. Atomic per step, not per saver.
     */
    public function test_a_saver_failing_via_the_has_guard_pattern_rolls_back_the_whole_step_and_is_visible_to_the_user(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 45]);

        $payload = $this->fullValidPayload();
        unset($payload['field_display_submitted']);

        // A real user always GETs the step before POSTing its form — this
        // is what gives Laravel's session a "previous URL" for the
        // ValidationException handler's own redirect(url()->previous())
        // fallback to land on. Omitting it made this test fail on a
        // harness gap, not a real one (found and reproduced independently
        // by cc1): a raw ->post() with no preceding ->get() has no previous
        // URL tracked, so the failure redirect fell through to root
        // instead of back to this step — the mechanism was never broken.
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']));

        $response = $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $payload);

        // Stays on the SAME step — never advances while something failed.
        $response->assertRedirect(route('corex.agency-setup.step', ['step' => 'leases']));
        $response->assertSessionHasErrors();
        $this->assertNotEquals('Saved.', session('success'), 'must never flash success on a failed save');

        // Atomic: the earlier saver (LeaseSettingsController::update, which
        // ran BEFORE the failing one and would ordinarily have already
        // committed) is rolled back too — the pre-existing value survives
        // completely unchanged, not partially updated.
        $this->assertSame(
            45,
            LeaseSetting::expiryNoticeWindowDaysFor($agency->id),
            'an earlier saver in the same failed request must not leave its write committed'
        );
        $this->assertFalse(RentalInspectionSetting::where('agency_id', $agency->id)->exists(), 'no saver after the failing one may have written either');
        $this->assertFalse(RentalWorkOrderSetting::where('agency_id', $agency->id)->exists());
        $this->assertFalse(RentalApplicationQualifyingSetting::where('agency_id', $agency->id)->exists());

        // The failure is actually VISIBLE on the page the user lands on —
        // not just present in session state nothing renders. This is the
        // exact gap the conductor named: "a fix that stages an error nobody
        // displays is the same bug with extra steps."
        $rendered = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString("didn't save", $rendered, 'the error banner must actually render on the page');
    }

    /** The positive control — a genuinely complete submission must still succeed exactly as before. */
    public function test_a_fully_valid_submission_still_saves_and_advances_normally(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);

        $response = $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $this->fullValidPayload());

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('corex.agency-setup.step', ['step' => 'properties']));

        $this->assertSame(99, LeaseSetting::expiryNoticeWindowDaysFor($agency->id));
        $this->assertSame('id_number', RentalApplicationQualifyingSetting::returnGateMethodFor($agency->id));
    }

    /**
     * A saver that throws ValidationException directly (the pre-existing
     * mechanism, e.g. updateReturnGate()'s own $request->validate()) must
     * get the SAME atomic-rollback treatment as the has()-guard case above
     * — not a second, different code path.
     */
    public function test_a_saver_throwing_validation_exception_directly_also_rolls_back_the_whole_step(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 45]);

        $payload = $this->fullValidPayload();
        unset($payload['return_gate_method']); // updateReturnGate() requires all 3 return-gate fields together.

        $response = $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $payload);

        $response->assertSessionHasErrors();
        $this->assertSame(45, LeaseSetting::expiryNoticeWindowDaysFor($agency->id), 'the earlier lease saver must also roll back here');
        $this->assertFalse(RentalApplicationQualifyingSetting::where('agency_id', $agency->id)->exists());
    }
}
