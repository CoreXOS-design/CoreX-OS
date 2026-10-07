<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Exceptions\Rentals\LeaseCaptureIncompleteException;
use App\Exceptions\Rentals\NoLeaseAgreementLinkedException;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Rentals\LeaseAgreementDocumentValues;
use App\Services\Rentals\LeaseSigningLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.4, §15.13, §15.21 (Build L3a) — the launcher: the gate (a landlord, every signer's email
 * and ID), the e-sign flow with the signers in the ONE fixed order agent → tenant(s) → landlord(s), the agreement's
 * values seeded into Fill & review through the agency's own field map (including the calculated ones), the guard at
 * the moment of launch, double-click safety, the hook that tells the lease which envelope it became, and closing
 * an open agreement when its lease is cancelled.
 *
 * Mirrors reality (BUILD_STANDARD §5): joint tenants created out of order, a landlord with no email, a tenant with
 * no ID, a VAT-registered and a non-VAT agency, a second agency whose lease has no fee schedule at all, another
 * agency's / an ownerless template, a declined agreement, a lease cancelled with the document already out.
 */
final class LeaseSigningLauncherTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Sea view', 'status' => 'active', 'listing_type' => 'rental', 'commission_percent' => 10,
        ]);
        $this->tenant = $this->contact('Thandi', 'Nkosi', '8002025009081');
        $this->landlord = $this->contact('Pieter', 'Botha', '7001015009087');
        $this->link($this->landlord, 'landlord');
    }

    // ═══ the gate ═══

    public function test_a_complete_lease_has_nothing_missing(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();

        $this->assertSame([], app(LeaseSigningLauncher::class)->missing($lease, $row, [], $this->agent));
    }

    public function test_the_gate_names_every_gap_and_links_to_where_it_is_fixed(): void
    {
        $tenantNoId = $this->contact('Sipho', 'Dlamini', null);
        $landlordNoEmail = $this->contact('Anna', 'Smit', '6501015009087', null);
        ContactProperty::where('contact_id', $this->landlord->id)->delete();
        $this->link($landlordNoEmail, 'landlord');
        [$lease, $row] = $this->leaseWithAgreement([$tenantNoId]);

        $missing = app(LeaseSigningLauncher::class)->missing($lease, $row, [], $this->agent);

        $labels = array_column($missing, 'label');
        $this->assertContains('Sipho Dlamini (tenant) — ID or passport number', $labels);
        $this->assertContains('Anna Smit (landlord) — email address', $labels);
        $this->assertCount(2, $missing);
        foreach ($missing as $gap) {
            $this->assertNotEmpty($gap['fix_url'], 'every gap links to where it is fixed');
        }
        $this->assertSame(route('corex.contacts.show', $tenantNoId), collect($missing)->firstWhere('label', 'Sipho Dlamini (tenant) — ID or passport number')['fix_url']);
    }

    public function test_a_property_with_no_landlord_blocks_signing_and_links_to_its_contacts(): void
    {
        ContactProperty::where('contact_id', $this->landlord->id)->delete();
        [$lease, $row] = $this->leaseWithAgreement();

        $missing = app(LeaseSigningLauncher::class)->missing($lease, $row, [], $this->agent);

        $this->assertSame('landlord', $missing[0]['key']);
        $this->assertSame(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'contacts']), $missing[0]['fix_url']);
    }

    public function test_a_passport_number_is_as_good_as_an_id_number(): void
    {
        $foreign = $this->contact('Jean', 'Dupont', null);
        $foreign->update(['passport_number' => 'FR1234567']);
        [$lease, $row] = $this->leaseWithAgreement([$foreign]);

        $this->assertSame([], app(LeaseSigningLauncher::class)->missing($lease, $row, [], $this->agent));
    }

    public function test_a_party_address_is_asked_for_only_when_the_agencys_own_lease_prints_one(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();
        $this->assertSame([], app(LeaseSigningLauncher::class)->missing($lease, $row, [], $this->agent), 'this lease prints no address');

        $row->update(['field_map' => $row->field_map + ['tenant_address' => ['field' => 'lessee_address']]]);
        $missing = app(LeaseSigningLauncher::class)->missing($lease->fresh(), $row->fresh(), [], $this->agent);

        $this->assertSame(['Thandi Nkosi (tenant) — address'], array_column($missing, 'label'));
    }

    public function test_required_agreement_details_come_from_what_was_typed_or_what_is_saved(): void
    {
        [$lease, $row] = $this->leaseWithAgreement([], ['adults' => ['field' => 'occupants', 'required' => true]]);

        $this->assertSame(['Number of adults'], array_column(app(LeaseSigningLauncher::class)->missing($lease, $row, [], $this->agent), 'label'));
        $this->assertSame([], app(LeaseSigningLauncher::class)->missing($lease, $row, ['adults' => '2'], $this->agent), 'typed and not yet saved');

        $terms = LeaseAgreementTerms::forLease($lease);
        $terms->adults = 3;
        $terms->save();
        $this->assertSame([], app(LeaseSigningLauncher::class)->missing($lease->fresh(), $row, [], $this->agent), 'saved on the lease');
    }

    public function test_a_lease_with_a_service_fee_needs_a_commission_percentage_from_somewhere(): void
    {
        $this->property->update(['commission_percent' => null]);
        [$lease, $row] = $this->leaseWithAgreement([], ['agent_service_fee' => ['field' => 'service_fee']]);

        $this->assertSame(['Letting commission %'], array_column(app(LeaseSigningLauncher::class)->missing($lease, $row, [], $this->agent), 'label'));

        $this->assertSame([], app(LeaseSigningLauncher::class)->missing($lease, $row, ['commission_percent' => '10'], $this->agent), 'typed on the screen');
    }

    // ═══ the flow ═══

    public function test_the_signers_are_always_agent_then_tenants_then_landlords_whatever_order_they_were_added_in(): void
    {
        $second = $this->contact('Zodwa', 'Mkhize', '9001015009082');
        $coLandlord = $this->contact('Karel', 'Botha', '7201015009081');
        $this->link($coLandlord, 'landlord');
        // The landlord was linked FIRST, the secondary tenant was added before the primary one.
        [$lease, $row] = $this->leaseWithAgreement([$second, $this->tenant]);
        LeaseTenant::where('lease_id', $lease->id)->update(['is_primary' => false]);
        LeaseTenant::where('lease_id', $lease->id)->where('contact_id', $this->tenant->id)->update(['is_primary' => true]);

        $flow = app(LeaseSigningLauncher::class)->launch($lease->fresh(), $row, $this->agent);

        $recipients = $flow->step_data['recipients']['recipients'];
        $this->assertSame(['agent', 'tenant', 'tenant', 'landlord', 'landlord'], array_column($recipients, 'role'));
        $this->assertSame(['Thandi Nkosi', 'Zodwa Mkhize'], [$recipients[1]['name'], $recipients[2]['name']], 'primary tenant first');
        $this->assertSame([1, 2, 3, 4, 5], array_column($recipients, 'order'));
        $this->assertSame($this->agent->name, $recipients[0]['name']);
        $this->assertTrue($recipients[0]['readonly']);
    }

    public function test_each_signer_carries_their_own_contact_details_so_nothing_is_typed_again(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();

        $flow = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);

        $tenant = $flow->step_data['recipients']['recipients'][1];
        $this->assertSame($this->tenant->id, $tenant['_contact_id']);
        $this->assertSame($this->tenant->email, $tenant['email']);
        $this->assertSame('8002025009081', $tenant['id_number']);
        $this->assertSame('Thandi', $tenant['first_name']);
    }

    public function test_launch_lands_on_fill_and_review_and_prepares_the_lease(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();

        $flow = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);

        $this->assertSame(5, $flow->current_step);
        $this->assertSame($lease->id, $flow->lease_id);
        $this->assertSame($this->agent->id, $flow->user_id);
        $this->assertSame('esign', $flow->type);
        $this->assertSame((int) $row->docuperfect_template_id, $flow->template_id);
        $this->assertSame(route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 5]), app(LeaseSigningLauncher::class)->landingUrl($flow));

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_PREPARED, $lease->signing_status);
        $this->assertSame($flow->id, $lease->signing_flow_id);
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status, 'preparing never activates the lease');
        $this->assertSame('2026-11-01', $flow->step_data['details']['lease_start']);
        $this->assertSame('8500.00', $flow->step_data['details']['monthly_rental']);
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_PREPARED)->count());
    }

    public function test_pressing_it_twice_gives_back_the_same_flow(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();

        $first = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);
        $second = app(LeaseSigningLauncher::class)->launch($lease->fresh(), $row, $this->agent);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Flow::count());
        $this->assertSame(1, LeaseEvent::where('event_type', LeaseEvent::TYPE_AGREEMENT_PREPARED)->count());
    }

    public function test_a_renewal_also_keeps_the_older_draft_pointer(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();
        $lease->update(['previous_lease_id' => $lease->id]);

        $flow = app(LeaseSigningLauncher::class)->launch($lease->fresh(), $row, $this->agent);

        $this->assertSame($flow->id, $lease->fresh()->renewal_draft_flow_id);
    }

    public function test_a_gap_throws_and_creates_no_flow(): void
    {
        ContactProperty::where('contact_id', $this->landlord->id)->delete();
        [$lease, $row] = $this->leaseWithAgreement();

        try {
            app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);
            $this->fail('a missing landlord must stop the launch');
        } catch (LeaseCaptureIncompleteException $e) {
            $this->assertSame('landlord', $e->missing[0]['key']);
        }
        $this->assertSame(0, Flow::count());
        $this->assertSame(Lease::SIGNING_NOT_SENT, $lease->fresh()->signing_status);
    }

    // ═══ what Fill & review opens with ═══

    public function test_fill_and_review_opens_with_the_lease_and_agreement_values_in_the_right_fields(): void
    {
        [$lease, $row] = $this->leaseWithAgreement([], [
            'adults' => ['field' => 'occupants'], 'pets' => ['field' => 'pets_allowed'], 'escalation_percent' => ['field' => 'escalation'],
            'escalation_month' => ['field' => 'esc_month'], 'rent_in_words' => ['field' => 'rent_words'],
            'escalation_in_words' => ['field' => 'esc_words'], 'property_description' => ['field' => 'prop_full'],
        ], $this->documentFields());
        $terms = LeaseAgreementTerms::forLease($lease);
        $terms->fill(['adults' => 2, 'pets' => 'One small dog', 'escalation_percent' => 7.5, 'escalation_month' => 3]);
        $terms->save();

        $flow = app(LeaseSigningLauncher::class)->launch($lease->fresh(), $row, $this->agent);

        $values = $flow->step_data['fill_review']['fieldValues'];
        $byField = $this->valuesByFieldName($flow, $values);
        $this->assertSame('8500.00', $byField['monthly_rental']);
        $this->assertSame('2026-11-01', $byField['lease_start']);
        $this->assertSame('2027-10-31', $byField['lease_end']);
        $this->assertSame('2', $byField['occupants']);
        $this->assertSame('One small dog', $byField['pets_allowed']);
        $this->assertSame('7.5', $byField['escalation']);
        $this->assertSame('March', $byField['esc_month']);
        $this->assertSame('Eight thousand five hundred Rand', $byField['rent_words'], 'the resolver blanks the words on a non-sale document, so the launcher writes them');
        $this->assertSame('seven point five', $byField['esc_words']);
        $this->assertSame($this->property->buildDisplayAddress(), $byField['prop_full']);
        $this->assertArrayNotHasKey('lessee_name', $byField, 'names come from the recipients, not from this seed');
    }

    public function test_a_value_for_a_field_the_document_does_not_have_is_dropped_not_invented(): void
    {
        [$lease, $row] = $this->leaseWithAgreement([], ['adults' => ['field' => 'no_such_field']], $this->documentFields());
        $terms = LeaseAgreementTerms::forLease($lease);
        $terms->adults = 2;
        $terms->save();

        $flow = app(LeaseSigningLauncher::class)->launch($lease->fresh(), $row, $this->agent);

        $byField = $this->valuesByFieldName($flow, $flow->step_data['fill_review']['fieldValues']);
        $this->assertArrayNotHasKey('no_such_field', $byField);
        $this->assertArrayHasKey('monthly_rental', $byField);
    }

    public function test_the_service_fee_follows_the_agencys_own_vat_set_up(): void
    {
        $map = ['agent_service_fee' => ['field' => 'service_fee'], 'other_deduction' => ['field' => 'other_fee'], 'net_to_owner' => ['field' => 'net_owner']];
        [$lease, $row] = $this->leaseWithAgreement([], $map, $this->documentFields());
        $terms = LeaseAgreementTerms::forLease($lease);
        $terms->extra = ['other_deduction' => '150'];
        $terms->save();

        // Not VAT-registered: rent × commission, no VAT.
        $notRegistered = app(LeaseAgreementDocumentValues::class)->forLease($lease->fresh(), $row);
        $this->assertSame('850.00', $notRegistered['agent_service_fee']);
        $this->assertSame('7500.00', $notRegistered['net_to_owner'], '8500 − 850 − 150');

        // VAT-registered: the agency's own rate (15 unless its setting says otherwise).
        $this->agency->update(['vat_registered' => true]);
        $registered = app(LeaseAgreementDocumentValues::class)->forLease($lease->fresh(), $row);
        $this->assertSame('977.50', $registered['agent_service_fee']);
        $this->assertSame('7372.50', $registered['net_to_owner']);
    }

    /**
     * The worked example of §15.12.5 (one agency's real lease: 24 places, 23 distinct field names), loaded as a
     * FIXTURE map against a template that carries those 24 field names. The six contact places are filled by the
     * wizard's own resolver from the signers' rows; the other 18 are the launcher's — and the numbers are the ones
     * the spec's own example works out (rent R6 940, 10 % commission plus 15 % VAT = R798.10, less R150 = R5 991.90).
     */
    public function test_the_reference_lease_gets_every_non_contact_place_seeded_including_the_calculated_ones(): void
    {
        $this->agency->update(['vat_registered' => true]);
        $this->property->update(['commission_percent' => 10, 'unit_number' => '4', 'complex_name' => 'Sea Complex']);
        $places = [
            'property_description' => 'property_full', 'adults' => 'occupants', 'max_other_persons' => 'kids', 'rent' => 'monthly_rental',
            'rent_in_words' => 'price_in_words', 'escalation_percent' => 'escalation', 'escalation_in_words' => 'escalation_alpha',
            'escalation_month' => 'escalation_month', 'start_date' => 'property_lease_start_date', 'earliest_termination_date' => 'notice_date',
            'end_date' => 'property_lease_end_date', 'renewal_option_months' => 'renewal_period', 'pets' => 'what_pets_are_allowed',
            'other_conditions' => 'other_conditions', 'agent_service_fee' => 'agent_fee_in_rands', 'other_deduction' => 'lets_assists_fee',
            'net_to_owner' => 'owner_nett', 'tenant_name' => 'lessee_name_id', 'landlord_name' => 'lessor_full',
        ];
        $fields = [];
        foreach (array_values($places) as $i => $name) {
            $fields[] = ['id' => 'ref_' . $i, 'field_name' => $name, 'tag_type' => 'input'];
        }
        $map = [];
        foreach ($places as $key => $field) {
            $map[$key] = ['field' => $field];
        }
        $lease = Lease::create(['rental_amount' => 6940, 'start_date' => '2027-03-01', 'end_date' => '2028-02-28'] + $this->leaseAttributes());
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $terms = LeaseAgreementTerms::forLease($lease);
        $terms->fill([
            'adults' => 2, 'max_other_persons' => 1, 'escalation_percent' => 7.5, 'escalation_month' => 3,
            'earliest_termination_date' => '2028-01-31', 'renewal_option_months' => 12, 'pets' => 'One small dog',
            'other_conditions' => 'No smoking indoors.', 'extra' => ['other_deduction' => '150'],
        ]);
        $terms->save();
        $template = new Template(['name' => 'Reference lease', 'render_type' => 'pdf', 'is_esign' => true, 'signing_parties' => ['owner_party', 'acquiring_party', 'agent'], 'fields_json' => $fields]);
        $template->agency_id = $this->agency->id;
        $template->save();
        $row = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Reference', 'docuperfect_template_id' => $template->id, 'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true, 'field_map' => $map]);

        $flow = app(LeaseSigningLauncher::class)->launch($lease->fresh(), $row, $this->agent);

        $seeded = $this->valuesByFieldName($flow, $flow->step_data['fill_review']['fieldValues']);
        $expected = [
            'property_full' => $this->property->fresh()->buildDisplayAddress(),
            'occupants' => '2', 'kids' => '1', 'monthly_rental' => '6940.00', 'price_in_words' => 'Six thousand nine hundred and forty Rand',
            'escalation' => '7.5', 'escalation_alpha' => 'seven point five', 'escalation_month' => 'March',
            'property_lease_start_date' => '2027-03-01', 'notice_date' => '2028-01-31', 'property_lease_end_date' => '2028-02-28',
            'renewal_period' => '12', 'what_pets_are_allowed' => 'One small dog', 'other_conditions' => 'No smoking indoors.',
            'agent_fee_in_rands' => '798.10', 'lets_assists_fee' => '150.00', 'owner_nett' => '5991.90',
        ];
        ksort($expected);
        ksort($seeded);
        $this->assertSame($expected, $seeded);
    }

    public function test_the_commission_typed_on_the_screen_beats_the_propertys_own(): void
    {
        [$lease, $row] = $this->leaseWithAgreement([], ['agent_service_fee' => ['field' => 'service_fee']]);

        $values = app(LeaseAgreementDocumentValues::class)->forLease($lease, $row, ['commission_percent' => '12.5']);

        $this->assertSame('1062.50', $values['agent_service_fee']);
    }

    public function test_an_agency_whose_lease_has_no_schedule_gets_none_of_it(): void
    {
        [$lease, $row] = $this->leaseWithAgreement([], [], $this->documentFields());

        $values = app(LeaseAgreementDocumentValues::class)->forLease($lease, $row);

        foreach (['agent_service_fee', 'other_deduction', 'net_to_owner'] as $key) {
            $this->assertArrayNotHasKey($key, $values);
        }
        $this->assertNotContains('commission_percent', array_column(app(\App\Services\Rentals\LeaseCaptureService::class)->agreementFields($row), 'key'));
    }

    public function test_escalation_in_words(): void
    {
        $words = app(LeaseAgreementDocumentValues::class);

        $this->assertSame('ten', $words->percentInWords(10));
        $this->assertSame('seven point five', $words->percentInWords(7.5));
        $this->assertSame('twelve point two five', $words->percentInWords(12.25));
        $this->assertSame('twenty-one', $words->percentInWords(21));
        $this->assertSame('zero', $words->percentInWords(0));
    }

    // ═══ the guard at launch ═══

    public function test_another_agencys_template_is_refused_at_launch_with_no_hint(): void
    {
        $rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        [$lease] = $this->leaseWithAgreement();
        $foreign = $this->agreement($rival);

        $this->expectException(NoLeaseAgreementLinkedException::class);
        app(LeaseSigningLauncher::class)->launch($lease, $foreign, $this->agent);
    }

    public function test_an_ownerless_shared_template_is_refused_at_launch_even_for_its_would_be_owner(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();
        Template::query()->whereKey($row->docuperfect_template_id)->update(['agency_id' => null, 'is_global' => true]);

        try {
            app(LeaseSigningLauncher::class)->launch($lease, $row->fresh(), $this->agent);
            $this->fail('an ownerless template must never launch');
        } catch (NoLeaseAgreementLinkedException) {
            $this->assertSame(0, Flow::count());
        }
    }

    // ═══ the hook: the envelope becomes the lease's ═══

    public function test_the_hook_links_the_envelope_and_the_document_and_puts_the_lease_out_for_signing(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();
        $flow = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);
        [$envelope, $document] = $this->envelope();

        app(LeaseSigningLauncher::class)->linkEnvelope($flow, $envelope, $document);

        $lease = $lease->fresh();
        $this->assertSame(Lease::SIGNING_OUT_FOR_SIGNING, $lease->signing_status);
        $this->assertSame($envelope->id, $lease->signature_template_id);
        $this->assertSame($document->id, $lease->agreement_document_id);
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_OUT_FOR_SIGNING)->count());
        $this->assertDatabaseHas('signature_audit_log', ['signature_template_id' => $envelope->id, 'action' => 'lease_linked']);
    }

    public function test_the_hook_ignores_a_flow_that_is_not_the_leases_current_one_and_a_lease_less_flow(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();
        $current = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);
        $stale = Flow::create(['type' => 'esign', 'template_id' => $row->docuperfect_template_id, 'user_id' => $this->agent->id, 'current_step' => 6, 'status' => 'active', 'lease_id' => $lease->id, 'step_data' => []]);
        $plain = Flow::create(['type' => 'esign', 'template_id' => $row->docuperfect_template_id, 'user_id' => $this->agent->id, 'current_step' => 6, 'status' => 'active', 'step_data' => []]);
        [$envelope, $document] = $this->envelope();

        app(LeaseSigningLauncher::class)->linkEnvelope($stale, $envelope, $document);
        app(LeaseSigningLauncher::class)->linkEnvelope($plain, $envelope, $document);

        $this->assertSame(Lease::SIGNING_PREPARED, $lease->fresh()->signing_status);
        $this->assertNull($lease->fresh()->signature_template_id);
        $this->assertSame($current->id, $lease->fresh()->signing_flow_id);
    }

    public function test_the_wizards_prepare_signing_calls_the_hook_right_after_it_records_the_envelope(): void
    {
        // prepareSigning() is not driven over HTTP by any automated test on this box (the same limit
        // EsignDocumentNamingTest records), so what this pins is the one place the hook sits.
        $source = file_get_contents(base_path('app/Http/Controllers/Docuperfect/ESignWizardController.php'));

        $record = strpos($source, "\$flowStepData['signature_template_id'] = \$sigTemplate->id;");
        $hook = strpos($source, 'LeaseSigningLauncher::class)->linkEnvelope($flow, $sigTemplate, $document)');

        $this->assertNotFalse($record);
        $this->assertNotFalse($hook);
        $this->assertGreaterThan($record, $hook, 'the hook runs after the envelope is recorded on the flow');
        $this->assertLessThan(1500, $hook - $record, 'and right there, not somewhere later');
        $this->assertStringContainsString('if ($flow->lease_id)', substr($source, $hook - 120, 120), 'only for a flow launched from a lease');
    }

    // ═══ closing an open agreement ═══

    public function test_cancelling_a_lease_with_an_unsent_agreement_abandons_the_flow(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();
        $flow = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Tenant changed their mind'])
            ->assertRedirect();

        $lease = $lease->fresh();
        $this->assertSame(Lease::STATUS_CANCELLED, $lease->status);
        $this->assertSame(Lease::SIGNING_VOIDED, $lease->signing_status);
        $this->assertNull(Flow::find($flow->id), 'the wizard can no longer open it (soft-deleted)');
        $this->assertNotNull(Flow::withTrashed()->find($flow->id), 'nothing is hard-deleted');
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_AGREEMENT_VOIDED)->count());
    }

    public function test_cancelling_a_lease_with_the_agreement_out_cancels_the_envelope_and_tells_the_waiting_party(): void
    {
        Mail::fake();
        [$lease, $row] = $this->leaseWithAgreement();
        $flow = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);
        [$envelope, $document] = $this->envelope();
        $request = SignatureRequest::create([
            'signature_template_id' => $envelope->id, 'party_role' => 'tenant', 'signer_name' => 'Thandi Nkosi', 'signer_email' => 'thandi@example.test',
            'status' => 'pending', 'signing_order' => 2, 'token' => Str::random(40), 'token_expires_at' => now()->addDays(7),
        ]);
        app(LeaseSigningLauncher::class)->linkEnvelope($flow, $envelope, $document);

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'Rent changed'])->assertRedirect();

        $this->assertSame(SignatureTemplate::STATUS_CANCELLED, $envelope->fresh()->status);
        $this->assertSame('Rent changed', $envelope->fresh()->cancellation_reason);
        $this->assertSame('cancelled', $request->fresh()->status, 'the tenant\'s link stops working');
        $this->assertSame(Lease::SIGNING_VOIDED, $lease->fresh()->signing_status);
        Mail::assertSent(\App\Mail\Signatures\DocumentCancelledMail::class, fn ($mail) => $mail->hasTo('thandi@example.test'));
    }

    public function test_cancelling_a_lease_with_no_agreement_leaves_the_signing_state_alone(): void
    {
        $lease = Lease::create($this->leaseAttributes());

        $this->actingAs($this->agent)->post(route('corex.leases.cancel', $lease), ['cancel_reason' => 'x test'])->assertRedirect();

        $this->assertSame(Lease::SIGNING_NOT_SENT, $lease->fresh()->signing_status);
        $this->assertSame(0, LeaseEvent::where('event_type', LeaseEvent::TYPE_AGREEMENT_VOIDED)->count());
    }

    // ═══ "Prepare again" ═══

    public function test_prepare_again_after_a_declined_agreement_opens_a_fresh_one_for_the_same_lease(): void
    {
        [$lease, $row] = $this->leaseWithAgreement();
        $old = app(LeaseSigningLauncher::class)->launch($lease, $row, $this->agent);
        $lease->fresh()->update(['signing_status' => Lease::SIGNING_DECLINED]);

        $response = $this->actingAs($this->agent)->post(route('corex.leases.signing.prepare-again', $lease));

        $lease = $lease->fresh();
        $this->assertNotSame($old->id, $lease->signing_flow_id);
        $this->assertSame(Lease::SIGNING_PREPARED, $lease->signing_status);
        $response->assertRedirect(route('docuperfect.esign.step', ['flow' => $lease->signing_flow_id, 'step' => 5]));
        $this->assertSame(1, Lease::count(), 'the same lease, not a second one');
    }

    public function test_prepare_again_lists_what_is_still_missing_with_links(): void
    {
        [$lease] = $this->leaseWithAgreement();
        $this->tenant->update(['email' => null]);
        $lease->update(['signing_status' => Lease::SIGNING_EXPIRED]);

        $this->actingAs($this->agent)->from(route('corex.leases.show', $lease))->post(route('corex.leases.signing.prepare-again', $lease))
            ->assertRedirect(route('corex.leases.show', $lease))
            ->assertSessionHas('capture_gaps', fn (array $gaps) => $gaps[0]['label'] === 'Thandi Nkosi (tenant) — email address' && ! empty($gaps[0]['fix_url']));
        $this->assertSame(0, Flow::count());
    }

    public function test_prepare_again_is_refused_unless_the_agreement_actually_failed(): void
    {
        [$lease] = $this->leaseWithAgreement();
        $lease->update(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING]);

        $this->actingAs($this->agent)->from(route('corex.leases.show', $lease))->post(route('corex.leases.signing.prepare-again', $lease))
            ->assertSessionHasErrors('lease');
        $this->assertSame(0, Flow::count());
    }

    public function test_prepare_again_on_another_agencys_lease_is_a_404(): void
    {
        $rival = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $rivalBranch = Branch::create(['agency_id' => $rival->id, 'name' => 'Karoo']);
        $rivalAgent = User::factory()->create(['agency_id' => $rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);
        [$lease] = $this->leaseWithAgreement();
        $lease->update(['signing_status' => Lease::SIGNING_DECLINED]);

        $this->actingAs($rivalAgent)->post(route('corex.leases.signing.prepare-again', $lease))->assertNotFound();
    }

    // ═══ helpers ═══

    /**
     * A draft lease for $tenants (default: $this->tenant) on the property, plus an agency-owned lease agreement
     * linked and ready (the five link requirements + $extraMap), with $documentFields as the document's own fields.
     *
     * @param list<Contact> $tenants
     * @param array<string,mixed> $extraMap
     * @param list<array<string,mixed>> $documentFields
     * @return array{0: Lease, 1: RentalLeaseTemplate}
     */
    private function leaseWithAgreement(array $tenants = [], array $extraMap = [], array $documentFields = []): array
    {
        $lease = Lease::create($this->leaseAttributes());
        foreach ($tenants ?: [$this->tenant] as $i => $contact) {
            LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => $i === 0]);
        }

        return [$lease, $this->agreement($this->agency, $extraMap, $documentFields)];
    }

    /** @return array<string,mixed> */
    private function leaseAttributes(): array
    {
        return [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ];
    }

    /** @param array<string,mixed> $extraMap @param list<array<string,mixed>> $documentFields */
    private function agreement(Agency $agency, array $extraMap = [], array $documentFields = []): RentalLeaseTemplate
    {
        $template = new Template([
            'name' => 'Residential lease ' . uniqid(), 'render_type' => 'pdf', 'is_esign' => true,
            'signing_parties' => ['owner_party', 'acquiring_party', 'agent'], 'fields_json' => $documentFields,
        ]);
        $template->agency_id = $agency->id;
        $template->save();

        return RentalLeaseTemplate::create([
            'agency_id' => $agency->id, 'name' => 'Residential lease', 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true,
            'field_map' => [
                'rent' => ['field' => 'monthly_rental'], 'start_date' => ['field' => 'lease_start'], 'end_date' => ['field' => 'lease_end'],
                'tenant_name' => ['field' => 'lessee_name'], 'landlord_name' => ['field' => 'lessor_name'],
            ] + $extraMap,
        ]);
    }

    /** @return list<array<string,mixed>> the template's own fill-in fields, ids and all */
    private function documentFields(): array
    {
        $fields = [];
        foreach (['monthly_rental', 'lease_start', 'lease_end', 'lessee_name', 'lessor_name', 'occupants', 'pets_allowed', 'escalation', 'esc_month',
            'rent_words', 'esc_words', 'prop_full', 'service_fee', 'other_fee', 'net_owner'] as $i => $name) {
            $fields[] = ['id' => 'fld_' . $i, 'field_name' => $name, 'tag_type' => 'input', 'type' => 'text'];
        }

        return $fields;
    }

    /**
     * @param array<string,string> $fieldValues field id → value
     * @return array<string,string> field NAME → value
     */
    private function valuesByFieldName(Flow $flow, array $fieldValues): array
    {
        $names = collect($flow->step_data['fields'])->pluck('field_name', 'id');

        return collect($fieldValues)->mapWithKeys(fn ($v, $id) => [$names[$id] => $v])->all();
    }

    private function contact(string $first, string $last, ?string $idNumber, ?string $email = 'auto'): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => $last,
            'email' => $email === 'auto' ? strtolower($first) . uniqid() . '@example.test' : $email,
            'id_number' => $idNumber,
        ]);
    }

    private function link(Contact $contact, string $role): void
    {
        ContactProperty::create(['contact_id' => $contact->id, 'property_id' => $this->property->id, 'role' => $role]);
    }

    /** @return array{0: SignatureTemplate, 1: Document} */
    private function envelope(): array
    {
        $document = Document::create([
            'name' => 'Lease', 'document_type' => 'agreement', 'owner_id' => $this->agent->id,
            'agency_id' => $this->agency->id, 'web_template_data' => ['merged_html' => '<p>x</p>'],
        ]);
        $envelope = SignatureTemplate::create([
            'agency_id' => $this->agency->id, 'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $this->agent->id,
        ]);

        return [$envelope, $document];
    }
}
