<?php

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalSettingAuditEntry;
use App\Models\User;
use App\Services\Rentals\ApplicationTerms;
use App\Support\Audit\AuditContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * rental-applications.md "Application terms": the agency's own fees, deposit banking details, tenant information
 * summary and documents list - saved by has()-guarded savers (settings pages AND the setup wizard), audited, never
 * leaking across agencies, and built into ONE structure (ApplicationTerms) every application surface prints from.
 */
final class ApplicationTermsSettingsTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AuditContext::reset();
    }

    private function agency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid()]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    /** @return array<string, mixed> everything the lease settings page must post to be accepted (the required expiry window). */
    private function leasePost(array $extra): array
    {
        return array_merge(['expiry_notice_window_days' => 60], $extra);
    }

    public function test_lease_settings_page_saves_renewal_fee_wording_and_deposit_bank_details_and_audits_them(): void
    {
        $agency = $this->agency('Mega Test');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $this->leasePost([
            'renewal_fee' => '1000',
            'rent_before_occupation_text' => "  The first month's rent is payable before moving in.  ",
            'deposit_bank_account_name' => 'VIPS CC t/a Mega Rent Real Estate',
            'deposit_bank_name' => 'ABSA',
            'deposit_bank_branch_code' => '632005',
            'deposit_bank_account_type' => 'Savings',
            'deposit_bank_account_number' => '409 738 2003',
            'deposit_reference_rule' => 'property_address',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1000.0, LeaseSetting::renewalFeeFor($agency->id));
        $this->assertSame("The first month's rent is payable before moving in.", LeaseSetting::rentOccupationTextFor($agency->id));
        $details = collect(LeaseSetting::depositBankDetailsFor($agency->id))->pluck('value', 'key');
        $this->assertSame('ABSA', $details['deposit_bank_name']);
        $this->assertSame('409 738 2003', $details['deposit_bank_account_number']);
        $this->assertSame('property_address', LeaseSetting::depositReferenceRuleFor($agency->id));

        $audit = RentalSettingAuditEntry::where('agency_id', $agency->id)->where('setting_key', 'deposit_bank_account_number')->first();
        $this->assertNotNull($audit);
        $this->assertSame('409 738 2003', $audit->new_value);
        $this->assertSame('settings', $audit->source);
        $this->assertNotNull(RentalSettingAuditEntry::where('agency_id', $agency->id)->where('setting_key', 'renewal_fee')->first());
    }

    public function test_a_post_without_the_new_keys_never_wipes_them_and_a_blank_clears_one(): void
    {
        $agency = $this->agency('Keeper Realty');
        $admin = $this->admin($agency);
        LeaseSetting::create(['agency_id' => $agency->id, 'renewal_fee' => 750, 'deposit_bank_account_number' => '123', 'deposit_bank_name' => 'FNB',
            'rent_before_occupation_text' => 'Pay first.']);

        // a wizard step / older form that renders none of them
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $this->leasePost([]))->assertSessionHasNoErrors();
        $this->assertSame(750.0, LeaseSetting::renewalFeeFor($agency->id));
        $this->assertSame('Pay first.', LeaseSetting::rentOccupationTextFor($agency->id));
        $this->assertSame('FNB', collect(LeaseSetting::depositBankDetailsFor($agency->id))->pluck('value', 'key')['deposit_bank_name']);

        // an explicit blank means "not set"
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $this->leasePost(['renewal_fee' => '', 'rent_before_occupation_text' => '']))->assertSessionHasNoErrors();
        $this->assertNull(LeaseSetting::renewalFeeFor($agency->id));
        $this->assertSame('', LeaseSetting::rentOccupationTextFor($agency->id));
        $this->assertSame('123', collect(LeaseSetting::depositBankDetailsFor($agency->id))->pluck('value', 'key')['deposit_bank_account_number']);
    }

    public function test_application_page_saver_saves_fee_summary_and_documents_and_is_has_guarded(): void
    {
        $agency = $this->agency('Mega Test Two');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-applications.front-half'), [
            'application_fee' => '250',
            'tenant_info_summary' => "- One\r\n\r\nTwo  \r\n",
            'documents_to_accompany' => "ID\nProof of address",
        ])->assertSessionHasNoErrors();

        $this->assertSame(250.0, RentalApplicationQualifyingSetting::applicationFeeFor($agency->id));
        $this->assertSame("- One\n\nTwo", RentalApplicationQualifyingSetting::tenantInfoSummaryFor($agency->id));
        $this->assertSame("ID\nProof of address", RentalApplicationQualifyingSetting::documentsToAccompanyFor($agency->id));
        $this->assertNotNull(RentalSettingAuditEntry::where('agency_id', $agency->id)->where('setting_key', 'application_fee')->first());

        // another saver on the same route that posts none of them must not wipe them
        $this->actingAs($admin)->post(route('corex.settings.rental-applications.front-half'), ['allow_manual_link_share' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(250.0, RentalApplicationQualifyingSetting::applicationFeeFor($agency->id));
        $this->assertSame("ID\nProof of address", RentalApplicationQualifyingSetting::documentsToAccompanyFor($agency->id));

        $this->actingAs($admin)->post(route('corex.settings.rental-applications.front-half'), ['application_fee' => ''])->assertSessionHasNoErrors();
        $this->assertNull(RentalApplicationQualifyingSetting::applicationFeeFor($agency->id));
    }

    public function test_settings_never_leak_to_another_agency(): void
    {
        $mine = $this->agency('Mine Realty');
        $other = $this->agency('Other Realty');
        $admin = $this->admin($mine);

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), $this->leasePost(['renewal_fee' => '999', 'deposit_bank_account_number' => '555']))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('corex.settings.rental-applications.front-half'), ['application_fee' => '111', 'documents_to_accompany' => 'Secret list'])->assertSessionHasNoErrors();

        $this->assertNull(LeaseSetting::renewalFeeFor($other->id));
        $this->assertNull(LeaseSetting::depositBankDetailsFor($other->id));
        $this->assertNull(RentalApplicationQualifyingSetting::applicationFeeFor($other->id));
        $this->assertSame('', RentalApplicationQualifyingSetting::documentsToAccompanyFor($other->id));
        $this->assertSame(['amounts' => [], 'bank' => null, 'summary' => [], 'documents' => []], ApplicationTerms::for($other->id));
    }

    public function test_the_settings_pages_render_the_new_controls(): void
    {
        $agency = $this->agency('Render Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->get(route('corex.settings.leases.edit'))->assertOk()
            ->assertSee('data-qa="renewal-fee-input"', false)
            ->assertSee('data-qa="deposit-bank-card"', false)
            ->assertSee('data-qa="rent-before-occupation-input"', false);

        $this->actingAs($admin)->get(route('corex.settings.rental-applications.edit'))->assertOk()
            ->assertSee('data-qa="application-fee-input"', false)
            ->assertSee('data-qa="tenant-info-summary-input"', false)
            ->assertSee('data-qa="documents-to-accompany-input"', false);
    }

    public function test_an_agency_with_nothing_set_gets_empty_terms_and_deposit_only_with_a_rent(): void
    {
        $agency = $this->agency('Blank Realty');

        $this->assertSame(['amounts' => [], 'bank' => null, 'summary' => [], 'documents' => []], ApplicationTerms::for($agency->id));
        $this->assertSame(['amounts' => [], 'bank' => null, 'summary' => [], 'documents' => []], ApplicationTerms::for(null, null, 2, 'X'));

        // with a rent the deposit row shows from the neutral default multiple (1 month), nothing else
        $terms = ApplicationTerms::for($agency->id, 5500.0);
        $this->assertCount(1, $terms['amounts']);
        $this->assertSame('Deposit', $terms['amounts'][0]['label']);
        $this->assertSame('R 5,500.00 (1 × monthly rent)', $terms['amounts'][0]['value']);
    }

    public function test_mega_like_values_build_every_row_with_the_deposit_at_one_and_a_half_times_rent(): void
    {
        $agency = $this->agency('Mega Like');
        LeaseSetting::create([
            'agency_id' => $agency->id, 'default_deposit_months' => 1.5, 'default_lease_fee' => 2500, 'lease_fee_label' => 'First lease fee',
            'renewal_fee' => 1000, 'rent_before_occupation_text' => 'Pay before moving in.',
            'deposit_bank_account_name' => 'VIPS CC t/a Mega Rent Real Estate', 'deposit_bank_name' => 'ABSA', 'deposit_bank_branch_code' => '632005',
            'deposit_bank_account_type' => 'Savings', 'deposit_bank_account_number' => '409 738 2003', 'deposit_reference_rule' => 'property_address',
        ]);
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $agency->id, 'application_fee' => 250,
            'tenant_info_summary' => "• Fee is non-refundable\n- A decision takes 48 hours\n\n  Deposit is held in trust  ",
            'documents_to_accompany' => "ID\nProof of address",
        ]);

        $terms = ApplicationTerms::for($agency->id, 5500.0, 2, '1 Test Street, Table View', 'Jo Tenant', 'L-2');

        $this->assertSame([
            ['label' => 'Application fee (per applicant)', 'value' => 'R 250.00 (2 applicants: R 500.00)'],
            ['label' => 'Deposit', 'value' => 'R 8,250.00 (1.5 × monthly rent)'],
            ['label' => 'First lease fee', 'value' => 'R 2,500.00'],
            ['label' => 'Renewal fee', 'value' => 'R 1,000.00'],
            ['label' => 'Rent payable before occupation', 'value' => 'Pay before moving in.'],
        ], $terms['amounts']);
        $this->assertSame('1 Test Street, Table View', $terms['bank']['reference']);
        $this->assertSame(['Account name', 'Bank', 'Branch code', 'Account type', 'Account number'], array_column($terms['bank']['rows'], 'label'));
        $this->assertSame('409 738 2003', $terms['bank']['rows'][4]['value']);
        $this->assertSame(['Fee is non-refundable', 'A decision takes 48 hours', 'Deposit is held in trust'], $terms['summary']);
        $this->assertSame(['ID', 'Proof of address'], $terms['documents']);

        $one = ApplicationTerms::for($agency->id, null, 1);
        $this->assertSame('R 250.00', $one['amounts'][0]['value']);
        $this->assertNotContains('Deposit', array_column($one['amounts'], 'label'), 'no rent, no deposit row');
    }

    public function test_the_reference_rule_picks_the_tenant_name_or_the_lease_reference(): void
    {
        $agency = $this->agency('Rule Realty');
        LeaseSetting::create(['agency_id' => $agency->id, 'deposit_bank_account_number' => '1', 'deposit_reference_rule' => 'tenant_name']);
        $this->assertSame('Jo Tenant', ApplicationTerms::for($agency->id, null, 1, 'addr', 'Jo Tenant', 'L-9')['bank']['reference']);

        LeaseSetting::where('agency_id', $agency->id)->update(['deposit_reference_rule' => 'lease_reference']);
        $this->assertSame('L-9', ApplicationTerms::for($agency->id, null, 1, 'addr', 'Jo Tenant', 'L-9')['bank']['reference']);
    }

    public function test_a_half_filled_bank_block_without_an_account_is_not_printed(): void
    {
        $agency = $this->agency('Half Realty');
        LeaseSetting::create(['agency_id' => $agency->id, 'deposit_bank_name' => 'ABSA']);

        $this->assertNull(LeaseSetting::depositBankDetailsFor($agency->id));
        $this->assertNull(ApplicationTerms::for($agency->id)['bank']);
    }

    public function test_the_setup_wizard_rentals_step_shows_and_saves_the_new_controls(): void
    {
        $agency = $this->agency('Wizard Realty');
        $admin = $this->admin($agency);

        $page = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk();
        foreach (['renewal_fee', 'rent_before_occupation_text', 'deposit_bank_account_name', 'deposit_bank_account_number', 'deposit_reference_rule',
            'application_fee', 'tenant_info_summary', 'documents_to_accompany'] as $name) {
            $page->assertSee('name="' . $name . '"', false);
        }

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency), [
                'renewal_fee' => '1000',
                'deposit_bank_account_number' => '777',
                'deposit_bank_name' => 'ABSA',
                'application_fee' => '250',
                'documents_to_accompany' => "ID\nBank statements",
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1000.0, LeaseSetting::renewalFeeFor($agency->id));
        $this->assertSame('777', collect(LeaseSetting::depositBankDetailsFor($agency->id))->pluck('value', 'key')['deposit_bank_account_number']);
        $this->assertSame(250.0, RentalApplicationQualifyingSetting::applicationFeeFor($agency->id));
        $this->assertSame("ID\nBank statements", RentalApplicationQualifyingSetting::documentsToAccompanyFor($agency->id));
        $this->assertSame('wizard', RentalSettingAuditEntry::where('agency_id', $agency->id)->where('setting_key', 'application_fee')->value('source'));
    }
}
