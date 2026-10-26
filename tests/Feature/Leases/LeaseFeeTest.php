<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Rentals\LeaseAgreementDocumentValues;
use App\Services\Rentals\LeaseAgreementValuesReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/leases.md "Lease fee" (26 Oct 2026, Johan) - the lease (letting/placement) fee is a real lease term: an agency
 * setting for the starting amount (0 = not charged), a per-lease value on the capture screen that the agent can change,
 * carried on a renewal, shown on the lease screen beside rent and deposit, bindable to any agency's own lease agreement
 * (registry key `lease_fee`) and printed in the document money format. Zero is a real value ("R 0.00", like a zero deposit);
 * a lease with no fee on record leaves the agreement's box empty.
 */
final class LeaseFeeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $rival;
    private Branch $branch;
    private User $admin;
    private Property $property;
    private Contact $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->admin->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->tenant = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi' . uniqid() . '@example.test',
        ]);
    }

    // ── the agency setting ───────────────────────────────────────────────────────────

    public function test_an_agency_with_no_setting_charges_nothing(): void
    {
        $this->assertSame(0.0, LeaseSetting::defaultLeaseFeeFor(null));
        $this->assertSame(0.0, LeaseSetting::defaultLeaseFeeFor($this->agency->id));
        $this->assertSame(0.0, LeaseSetting::defaultLeaseFeeFor(999999));
    }

    public function test_the_settings_page_saves_a_default_and_a_blank_goes_back_to_zero(): void
    {
        $this->actingAs($this->admin)->get(route('corex.settings.leases.edit'))->assertOk()->assertSee('name="default_lease_fee"', false)->assertSee('value="0.00"', false);

        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60, 'default_lease_fee' => '1500'])
            ->assertRedirect(route('corex.settings.leases.edit'));
        $this->assertSame(1500.0, LeaseSetting::defaultLeaseFeeFor($this->agency->id));

        $this->actingAs($this->admin)->get(route('corex.settings.leases.edit'))->assertSee('value="1500.00"', false);

        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60, 'default_lease_fee' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, LeaseSetting::defaultLeaseFeeFor($this->agency->id), 'blank = back to the default, not charged');
    }

    public function test_a_save_that_does_not_post_the_fee_leaves_it_alone(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'expiry_notice_window_days' => 60, 'default_lease_fee' => 900]);

        // The onboarding wizard posts only the controls the step renders (agency-onboarding-setup.md §6.1).
        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 45])->assertSessionHasNoErrors();

        $this->assertSame(900.0, LeaseSetting::defaultLeaseFeeFor($this->agency->id));
    }

    public function test_a_negative_default_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60, 'default_lease_fee' => '-5'])
            ->assertSessionHasErrors('default_lease_fee');
    }

    public function test_another_agencys_default_is_never_used(): void
    {
        LeaseSetting::create(['agency_id' => $this->rival->id, 'expiry_notice_window_days' => 60, 'default_lease_fee' => 2500]);

        $this->assertSame(0.0, LeaseSetting::defaultLeaseFeeFor($this->agency->id));
        $this->assertSame(2500.0, LeaseSetting::defaultLeaseFeeFor($this->rival->id));
    }

    // ── the capture screen: setting -> prefill, per-lease value wins ─────────────────

    public function test_the_new_lease_screen_prefills_the_agency_default(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'expiry_notice_window_days' => 60, 'default_lease_fee' => 1500]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="lease_fee" value="1500\.00"/', $html);
    }

    public function test_an_agency_without_a_setting_sees_a_fee_of_zero(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="lease_fee" value="0\.00"/', $html);
    }

    public function test_a_new_lease_takes_the_fee_typed_on_the_screen_over_the_agency_default(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'expiry_notice_window_days' => 60, 'default_lease_fee' => 1500]);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['lease_fee' => '2300.50']))->assertSessionHasNoErrors();

        $this->assertSame('2300.50', (string) Lease::where('property_id', $this->property->id)->firstOrFail()->lease_fee);
    }

    public function test_a_cleared_fee_box_means_no_fee_on_record(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'expiry_notice_window_days' => 60, 'default_lease_fee' => 1500]);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['lease_fee' => '']))->assertSessionHasNoErrors();

        $this->assertNull(Lease::where('property_id', $this->property->id)->firstOrFail()->lease_fee);
    }

    public function test_a_capture_that_does_not_post_a_fee_gets_the_agency_default(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'expiry_notice_window_days' => 60, 'default_lease_fee' => 1500]);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame('1500.00', (string) Lease::where('property_id', $this->property->id)->firstOrFail()->lease_fee);
    }

    public function test_a_malformed_or_negative_fee_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['lease_fee' => 'lots']))->assertSessionHasErrors('lease_fee');
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['lease_fee' => '-1']))->assertSessionHasErrors('lease_fee');
        $this->assertSame(0, Lease::where('property_id', $this->property->id)->count());
    }

    // ── renewal carries it ───────────────────────────────────────────────────────────

    public function test_the_renewal_screen_starts_from_the_fee_of_the_term_being_renewed(): void
    {
        LeaseSetting::create(['agency_id' => $this->agency->id, 'expiry_notice_window_days' => 60, 'default_lease_fee' => 1500]);
        $current = $this->activeLease(['lease_fee' => 1800]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="lease_fee" value="1800\.00"/', $html, 'the renewed term\'s fee, not the agency default');
    }

    public function test_a_renewal_carries_the_fee_when_none_is_posted_and_takes_a_typed_one_when_it_is(): void
    {
        $current = $this->activeLease(['lease_fee' => 1800]);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), ['intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '9600'])
            ->assertSessionHasNoErrors();
        $carried = Lease::where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertSame('1800.00', (string) $carried->lease_fee);

        $other = $this->activeLease(['lease_fee' => 1800, 'start_date' => '2025-12-01', 'end_date' => '2026-11-30']);
        $this->property2($other);
        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $other), ['intent' => 'lease_only', 'start_date' => '2026-12-01', 'rental_amount' => '9600', 'lease_fee' => '2100'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2100.00', (string) Lease::where('previous_lease_id', $other->id)->firstOrFail()->lease_fee, 'the typed fee wins over the carried one');
        $this->assertSame('1800.00', (string) $other->fresh()->lease_fee, 'the old term is left alone');
    }

    public function test_the_renewal_service_carries_the_fee_forward(): void
    {
        $current = $this->activeLease(['lease_fee' => 1800]);

        $new = app(\App\Services\Rentals\LeaseRenewalService::class)->createRenewalTerm($current, [
            'start_date' => '2026-11-01', 'rental_amount' => 9600,
        ], $this->admin);

        $this->assertSame('1800.00', (string) $new->lease_fee);
    }

    // ── the lease screen ─────────────────────────────────────────────────────────────

    public function test_the_lease_screen_shows_the_fee_beside_rent_and_deposit(): void
    {
        $lease = $this->activeLease(['deposit_amount' => 9000, 'lease_fee' => 1500]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();

        $this->assertStringContainsString('Deposit:</span> R9,000.00', $html);
        $this->assertStringContainsString('Lease fee:</span> R1,500.00', $html);
        $this->assertStringContainsString('name="lease_fee"', $html, 'editable on the lease edit panel');
    }

    public function test_a_lease_with_no_fee_on_record_shows_a_dash_and_zero_shows_zero(): void
    {
        $none = $this->activeLease();
        $zero = $this->activeLease(['lease_fee' => 0, 'start_date' => '2025-12-01', 'end_date' => '2026-11-30']);
        $this->property2($zero);

        $this->assertStringContainsString('Lease fee:</span> —', $this->actingAs($this->admin)->get(route('corex.leases.show', $none))->getContent());
        $this->assertStringContainsString('Lease fee:</span> R0.00', $this->actingAs($this->admin)->get(route('corex.leases.show', $zero))->getContent());
    }

    public function test_the_lease_edit_changes_the_fee_and_leaves_it_alone_when_not_posted(): void
    {
        $lease = $this->activeLease(['lease_fee' => 1500]);

        $this->actingAs($this->admin)->put(route('corex.leases.update', $lease), ['deposit_amount' => '9000'])->assertSessionHasNoErrors();
        $this->assertSame('1500.00', (string) $lease->fresh()->lease_fee, 'not posted = kept');

        $this->actingAs($this->admin)->put(route('corex.leases.update', $lease), ['deposit_amount' => '9000', 'lease_fee' => '1750'])->assertSessionHasNoErrors();
        $this->assertSame('1750.00', (string) $lease->fresh()->lease_fee);
    }

    // ── the value the agreement prints ───────────────────────────────────────────────

    public function test_the_fee_is_a_registry_field_any_agency_can_map(): void
    {
        $registry = config('lease-agreement-fields.fields');

        $this->assertSame('money', $registry['lease_fee']['type']);
        $this->assertSame('lease', $registry['lease_fee']['side']);
        $this->assertSame('lease_fee', $registry['lease_fee']['column']);
        $this->assertArrayHasKey('lease_fee', app(LeaseAgreementValuesReader::class)->normaliseMap(['lease_fee' => ['field' => 'letting_fee']]));
    }

    public function test_the_fee_prints_in_the_document_money_format(): void
    {
        $values = app(LeaseAgreementDocumentValues::class)->forLease($this->activeLease(['lease_fee' => 1500]), $this->agreement(['lease_fee' => ['field' => 'lease_fee']]));

        $this->assertSame('R 1,500.00', $values['lease_fee']);
    }

    public function test_a_template_that_draws_its_own_r_gets_the_bare_number(): void
    {
        $values = app(LeaseAgreementDocumentValues::class)->forLease(
            $this->activeLease(['lease_fee' => 1500.5]), $this->agreement(['lease_fee' => ['field' => 'lease_fee']]), null, ['lease_fee' => 'bare'],
        );

        $this->assertSame('1,500.50', $values['lease_fee']);
    }

    public function test_a_zero_fee_prints_as_zero_like_a_zero_deposit_and_no_fee_on_record_prints_nothing(): void
    {
        $map = $this->agreement(['lease_fee' => ['field' => 'lease_fee'], 'deposit' => ['field' => 'deposit_amount']]);

        $zero = app(LeaseAgreementDocumentValues::class)->forLease($this->activeLease(['lease_fee' => 0, 'deposit_amount' => 0]), $map);
        $this->assertSame('R 0.00', $zero['lease_fee']);
        $this->assertSame('R 0.00', $zero['deposit'], 'the same rule as the deposit');

        $none = app(LeaseAgreementDocumentValues::class)->forLease($this->activeLease(['start_date' => '2025-12-01', 'end_date' => '2026-11-30']), $map);
        $this->assertArrayNotHasKey('lease_fee', $none);
    }

    public function test_an_agreement_whose_map_has_no_fee_line_is_given_no_fee(): void
    {
        $values = app(LeaseAgreementDocumentValues::class)->forLease($this->activeLease(['lease_fee' => 1500]), $this->agreement(['rent' => ['field' => 'monthly_rental']]));

        $this->assertArrayNotHasKey('lease_fee', $values, 'a second agency\'s agreement with no fee line never gets one');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'property_id' => $this->property->id, 'rental_amount' => '8500', 'start_date' => '2026-11-01',
            'tenant_contact_ids' => [$this->tenant->id],
        ], $overrides);
    }

    private function activeLease(array $overrides = []): Lease
    {
        $lease = Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => '2025-11-01', 'end_date' => '2026-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ], $overrides));
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return $lease;
    }

    /** A second active lease must sit on its own property (a property never has two active leases at once, §3.5). */
    private function property2(Lease $lease): void
    {
        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->admin->id,
            'title' => 'Second ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease->forceFill(['property_id' => $property->id])->save();
    }

    private function agreement(array $map): RentalLeaseTemplate
    {
        return (new RentalLeaseTemplate)->forceFill(['field_map' => $map]);
    }
}
