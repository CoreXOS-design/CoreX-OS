<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Independent review (conductor, 2026-09-19) of the already-landed Rentals
 * wizard step (commit 02e2eb569) against agency-onboarding-rentals-step.md's
 * own acceptance criteria — proving the saver protection the hard way,
 * against the actual production endpoint, not re-asserting the existing
 * suite's own claims.
 *
 * Broke on QA1 2026-09-20 when rental-work-orders.md Stage 3 added a 4th
 * saver (RentalWorkOrderSettingsController::update(), required
 * no_approval_spend_threshold with no fallback) to this same shared step —
 * these tests' hand-built POST payloads were never updated to include it,
 * so they 422'd. Verified this was test staleness, NOT a real product break,
 * before touching anything: drove the actual live QA1 site with a real
 * Puppeteer browser session as a genuinely fresh agency that had never
 * heard of work orders, confirmed the wizard PRE-FILLS
 * no_approval_spend_threshold with a real default (500) as a normal visible
 * input, confirmed via the page's own FormData that a real browser
 * submission includes it automatically, and confirmed clicking the actual
 * Save & continue button with nothing else touched advances the wizard
 * cleanly. A real agency going through onboarding is not blocked by this;
 * only these fixtures, built before Stage 3 existed, were lying about it.
 *
 * Broke AGAIN on QA1 2026-09-20 when rental-application-field-config.md
 * added 6 more savers to this same shared step. Same root cause, same
 * verdict (test staleness, not a product break) — but confirmed this time
 * that fixing only the newest saver's required fields is NOT sufficient:
 * updateFieldDisplayConfig()/updateRequiredFields()/4 boolean savers each
 * has()-guard their own field and flash "That did not save" when absent
 * rather than throwing, so AgencySetupWizardController::save()'s foreach
 * keeps running past them — the request can still redirect FORWARD (looking
 * successful) while a stale flashed error from an earlier saver survives
 * into the session. Fixed by completing every payload in this file to match
 * a real full-page form submission, not just the newest saver's fields.
 */
final class RentalsStepIndependentReviewTest extends TestCase
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

    /**
     * Proves cross-agency isolation the hard way: Agency B saves the
     * combined wizard step while Agency A already has non-default,
     * genuinely-configured values on BOTH domains. Agency A's data must be
     * byte-for-byte untouched by a request it was never part of.
     */
    public function test_a_second_agencys_settings_survive_another_agencys_wizard_save(): void
    {
        $agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        LeaseSetting::create(['agency_id' => $agencyA->id, 'expiry_notice_window_days' => 33]);
        RentalInspectionSetting::create([
            'agency_id' => $agencyA->id,
            'fault_report_window_days' => 17,
            'out_inspection_signing_window_days' => 22,
        ]);

        $agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);
        $adminB = $this->admin($agencyB);

        $this->actingAs($adminB)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'expiry_notice_window_days' => 99,
                'fault_report_window_days' => 88,
                'out_inspection_signing_window_days' => 45,
                // Every field below is a real control this step now renders,
                // required together with everything above, same as a real
                // browser submits it (each carries a real pre-filled
                // default, so a genuine user's form POST always includes it).
                'no_approval_spend_threshold' => 500,
                'shown_field_keys' => collect(\App\Models\RentalApplication::submissionFieldRegistry())->pluck('key')->all(),
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
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Agency B's own save took.
        $this->assertSame(99, LeaseSetting::expiryNoticeWindowDaysFor($agencyB->id));
        $this->assertSame(88, RentalInspectionSetting::faultReportWindowDaysFor($agencyB->id));
        $this->assertSame(45, RentalInspectionSetting::signingWindowDaysFor($agencyB->id));

        // Agency A, never part of this request, is completely untouched.
        $this->assertSame(33, LeaseSetting::expiryNoticeWindowDaysFor($agencyA->id), 'Agency A lease setting must survive Agency B\'s save');
        $this->assertSame(17, RentalInspectionSetting::faultReportWindowDaysFor($agencyA->id), 'Agency A fault-report window must survive Agency B\'s save');
        $this->assertSame(22, RentalInspectionSetting::signingWindowDaysFor($agencyA->id), 'Agency A signing window must survive Agency B\'s save');
    }

    /**
     * Same shape, other direction — proves it isn't order-dependent luck.
     */
    public function test_saving_agency_as_own_settings_does_not_touch_agency_b(): void
    {
        $agencyB = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]);
        LeaseSetting::create(['agency_id' => $agencyB->id, 'expiry_notice_window_days' => 41]);
        RentalInspectionSetting::create([
            'agency_id' => $agencyB->id,
            'fault_report_window_days' => 9,
            'out_inspection_signing_window_days' => 11,
        ]);

        $agencyA = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]);
        $adminA = $this->admin($agencyA);

        $this->actingAs($adminA)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'expiry_notice_window_days' => 50,
                'fault_report_window_days' => 60,
                'out_inspection_signing_window_days' => 15,
                // Every field below is a real control this step now renders,
                // required together with everything above, same as a real
                // browser submits it.
                'no_approval_spend_threshold' => 500,
                'shown_field_keys' => collect(\App\Models\RentalApplication::submissionFieldRegistry())->pluck('key')->all(),
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
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Agency A's own save actually took (not just "B wasn't touched").
        $this->assertSame(50, LeaseSetting::expiryNoticeWindowDaysFor($agencyA->id));
        $this->assertSame(60, RentalInspectionSetting::faultReportWindowDaysFor($agencyA->id));
        $this->assertSame(15, RentalInspectionSetting::signingWindowDaysFor($agencyA->id));

        // Agency B, never part of this request, is untouched.
        $this->assertSame(41, LeaseSetting::expiryNoticeWindowDaysFor($agencyB->id));
        $this->assertSame(9, RentalInspectionSetting::faultReportWindowDaysFor($agencyB->id));
        $this->assertSame(11, RentalInspectionSetting::signingWindowDaysFor($agencyB->id));
    }

    /**
     * NOT the original incident's shape (no boolean force-default exists
     * here to trigger), but a real, different finding worth proving either
     * way: AgencySetupWizardController::save() calls each saver in a plain
     * foreach with no surrounding DB transaction. If an earlier saver's
     * write succeeds and a LATER saver's own `required` validation then
     * throws, the earlier write is already committed — the request reports
     * failure but is not actually atomic. This does not corrupt a field the
     * step never rendered (both fields here are always rendered together
     * and both are `required`), so it does not reproduce the named
     * incident, but it is a real partial-write-on-failure inconsistency,
     * reported separately rather than silently folded into "protected."
     */
    public function test_a_partial_post_missing_a_required_field_leaves_the_earlier_savers_write_committed(): void
    {
        $agency = Agency::create(['name' => 'Agency C', 'slug' => 'agency-c-' . uniqid()]);
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 60]);
        $admin = $this->admin($agency);

        // Posts the lease field (saver #1, runs first) but omits BOTH
        // rental-inspection fields (saver #2, runs second) entirely.
        $response = $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'expiry_notice_window_days' => 45,
            ]);

        $response->assertSessionHasErrors();

        // The request as a whole reports failure (validation errors) — but
        // saver #1 already ran and its write already persisted before
        // saver #2's validation threw.
        $this->assertSame(
            45,
            LeaseSetting::expiryNoticeWindowDaysFor($agency->id),
            'demonstrates the partial-write: the failed request still persisted the first saver\'s new value'
        );
    }

    /**
     * Stronger version of RentalsStepExistingAgencyTest's own
     * assertSee('7') check. That assertion is real but weak — the digit 7
     * appears elsewhere on this page regardless (SVG icon path coordinates,
     * confirmed by fetching the actual rendered HTML), so assertSee('7')
     * alone would pass even if the field defaulted to something else or
     * failed to render at all. This asserts the specific input's name AND
     * value together, which cannot produce a false positive the same way.
     */
    public function test_existing_agency_wizard_reopen_renders_the_real_default_value_on_the_actual_input_not_just_somewhere_on_the_page(): void
    {
        $agency = Agency::create(['name' => 'Established Realty', 'slug' => 'established-realty-' . uniqid()]);
        $admin = $this->admin($agency);
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 45]);

        $setup = new AgencyOnboardingSetup();
        $setup->agency_id = $agency->id;
        $setup->token = AgencyOnboardingSetup::generateToken();
        $setup->slug = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $setup->created_by = $admin->id;
        $setup->admin_user_id = $admin->id;
        $setup->current_step = AgencyOnboardingSetup::totalSteps();
        $setup->completed_steps = AgencyOnboardingSetup::STEPS;
        $setup->expires_at = now()->addDays(30);
        $setup->completed_at = now()->subMonths(3);
        $setup->save();

        $response = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']));
        $response->assertOk();

        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/name=["\']fault_report_window_days["\'][^>]*value=["\']7["\']/',
            $html,
            'the fault-report-window input must carry the real default (7) as its OWN value attribute, not just have the digit 7 appear somewhere on the page'
        );
        $this->assertMatchesRegularExpression(
            '/name=["\']out_inspection_signing_window_days["\'][^>]*value=["\']7["\']/',
            $html,
            'the signing-window input must carry the real default (7) as its OWN value attribute'
        );
        $this->assertMatchesRegularExpression(
            '/name=["\']expiry_notice_window_days["\'][^>]*value=["\']45["\']/',
            $html,
            'the pre-existing lease value (45) must survive on its own input, not just appear on the page'
        );
    }
}
