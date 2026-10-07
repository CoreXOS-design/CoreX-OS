<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseEscalation;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalLeaseTemplate;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\Rentals\LeaseCaptureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.2–§15.6, §15.18 (Builds L2 + L3a) — the ONE capture screen for a new lease and a
 * renewal, the lease-only path, the signed paper copy, and "Create lease & prepare for signing" through to the
 * agent landing on the prepared agreement (Fill & review) — the gate, the fixed signing order, nothing sent.
 *
 * Mirrors reality (BUILD_STANDARD §5/§5a): a wet-ink-signed predecessor, two drafts for one tenant on one
 * property, the same capture submitted twice, an already-active property, each optional field omitted, the
 * lazy-but-valid minimum (property + one tenant + rent + start date), one malformed value per validated
 * field, a forged agreement id, another agency's data, and a second agency whose lease carries none of the
 * first one's fields.
 */
final class LeaseCaptureTest extends TestCase
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
        $this->property = $this->makeProperty($this->agency, $this->branch, $this->admin);
        $this->tenant = $this->makeContact($this->agency, 'Thandi', 'Nkosi');
    }

    // ═══ the screen — R3: button (b) and the agreement fields depend on the agency's OWN lease agreement ═══

    public function test_an_agency_with_no_lease_agreement_gets_lease_only_a_disabled_button_and_the_message(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Create lease only', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" disabled[^>]*>\s*Create lease &amp; prepare for signing\s*<\/button>/', $html);
        $this->assertStringNotContainsString('value="lease_and_sign"', $html);
        $this->assertStringContainsString('Your agency has not set up a lease agreement yet.', $html);
        $this->assertStringContainsString('Settings → Rental lease agreements', $html);
        $this->assertStringContainsString(route('corex.rental-lease-templates.index'), $html, 'an administrator gets the link');
        // No agreement section at all (it exists for the agreement only).
        $this->assertStringNotContainsString('needed for signing', $html);
        $this->assertStringNotContainsString('data-qa="signing-checklist"', $html);
        // The paper copy and every normal field are there regardless.
        $this->assertStringContainsString('I already have the signed copy — attach it', $html);
    }

    public function test_a_user_who_cannot_manage_agreements_is_told_to_ask_an_administrator_without_a_link(): void
    {
        $agent = $this->makeUserWithPermissions('capture_agent', ['leases.view', 'leases.create', 'access_docuperfect', 'create_docuperfect_docs']);

        $html = $this->actingAs($agent)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Ask your agency administrator', $html);
        $this->assertStringNotContainsString(route('corex.rental-lease-templates.index'), $html);
    }

    public function test_a_user_without_document_access_is_not_offered_button_b_at_all(): void
    {
        $this->linkAgreement($this->agency);
        $agent = $this->makeUserWithPermissions('no_docs_agent', ['leases.view', 'leases.create', 'properties.view']);

        $html = $this->actingAs($agent)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Create lease &amp; prepare for signing', $html);
        $this->assertStringNotContainsString('value="lease_and_sign"', $html);
        $this->assertStringContainsString('Create lease only', $html);
    }

    public function test_a_linked_ready_agreement_enables_button_b_and_shows_only_the_fields_its_map_carries(): void
    {
        $this->linkAgreement($this->agency, [
            'adults' => ['field' => 'occupants', 'required' => true],
            'pets' => ['field' => 'pets_allowed'],
            'escalation_percent' => ['field' => 'esc'],
            'tenant_name_2' => ['field' => 'lessee_2'],      // a per-party member of a family — never asked for
        ]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button type="submit" name="intent" value="lease_and_sign"[^>]*>\s*Create lease &amp; prepare for signing/', $html);
        $this->assertStringNotContainsString('Your agency has not set up a lease agreement yet.', $html);
        $this->assertStringContainsString('name="agreement[adults]"', $html);
        $this->assertStringContainsString('name="agreement[pets]"', $html);
        $this->assertStringContainsString('name="agreement[escalation_percent]"', $html);
        $this->assertStringNotContainsString('name="agreement[electricity_arrangement]"', $html, 'a field the lease does not carry is not shown');
        $this->assertStringNotContainsString('name="agreement[other_conditions]"', $html);
        $this->assertStringNotContainsString('tenant_name_2', $html);
        $this->assertStringContainsString('needed for signing', $html);
        $this->assertStringContainsString('data-qa="signing-checklist"', $html, 'a required field puts the checklist on screen');
    }

    public function test_an_agreement_that_marks_nothing_required_shows_no_checklist(): void
    {
        $this->linkAgreement($this->agency, ['pets' => ['field' => 'pets_allowed']]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-qa="signing-checklist"', $html);
    }

    public function test_an_agreement_that_needs_attention_says_why_and_stays_unavailable(): void
    {
        $row = $this->linkAgreement($this->agency);
        $row->template->update(['archived_at' => now()]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Your lease agreement needs attention — This template is archived.', $html);
        $this->assertStringNotContainsString('value="lease_and_sign"', $html);
    }

    public function test_another_agencys_lease_agreement_never_reaches_this_agency(): void
    {
        $this->linkAgreement($this->rival, ['adults' => ['field' => 'occupants']]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Your agency has not set up a lease agreement yet.', $html);
        $this->assertStringNotContainsString('name="agreement[adults]"', $html);
    }

    public function test_a_schedule_field_exists_only_for_an_agency_whose_own_lease_carries_it(): void
    {
        // Second-agency test (§15.18 #14): the first agency's lease has a schedule line, the rival's does not.
        $this->linkAgreement($this->agency, ['other_deduction' => ['field' => 'deduction', 'label' => 'Maintenance retainer']]);
        $this->linkAgreement($this->rival, ['pets' => ['field' => 'pets_allowed']]);
        $rivalAdmin = User::factory()->create(['agency_id' => $this->rival->id, 'role' => 'admin', 'is_active' => true]);

        $own = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="agreement[other_deduction]"', $own);
        $this->assertStringContainsString('Maintenance retainer', $own, 'the label comes from the agency\'s own map');

        $theirs = $this->actingAs($rivalAdmin)->get(route('corex.leases.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('other_deduction', $theirs);
        $this->assertStringNotContainsString('Maintenance retainer', $theirs);
    }

    public function test_several_agreements_get_a_picker_with_the_default_preselected_and_one_group_each(): void
    {
        $first = $this->linkAgreement($this->agency, ['pets' => ['field' => 'p']], 'Standard');
        $second = $this->linkAgreement($this->agency, ['adults' => ['field' => 'a']], 'Long term', ['is_default' => true]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('id="lease-agreement-picker"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $second->id . '" selected>Long term<\/option>/', $html);
        $this->assertStringContainsString('data-qa="agreement-fields-' . $first->id . '"', $html);
        $this->assertStringContainsString('data-qa="agreement-fields-' . $second->id . '"', $html);
    }

    public function test_the_lease_type_field_follows_the_agency_setting_and_the_deposit_months_reach_the_screen(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="lease_type"', $html, 'hidden by default, like the lease edit panel');

        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['show_lease_type_field' => true, 'default_deposit_months' => 2]);
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="lease_type"', $html);
        // Js::from escapes quotes as " (and doubles the backslash for the JS string literal).
        $this->assertMatchesRegularExpression('/"depositMonths":2(\.0)?[,}]/', $this->alpineConfig($html));
    }

    // ═══ button (a) — "Create lease only" ═══

    public function test_lease_only_creates_a_draft_lease_with_its_tenants_and_an_event_and_no_document(): void
    {
        $second = $this->makeContact($this->agency, 'Sipho', 'Dlamini');

        $response = $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload([
            'tenant_contact_ids' => [$second->id, $this->tenant->id],
            'deposit_amount' => '17000', 'end_date' => '2027-10-31',
        ]));

        $lease = Lease::firstOrFail();
        $response->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('success', 'Lease created.');
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status);
        $this->assertSame($this->property->id, $lease->property_id);
        $this->assertSame($this->branch->id, $lease->branch_id);
        $this->assertSame('8500.00', (string) $lease->rental_amount);
        $this->assertSame('17000.00', (string) $lease->deposit_amount);
        $this->assertSame('manual', $lease->source);
        $this->assertSame(Lease::SIGNING_NOT_SENT, $lease->signing_status);
        $this->assertNull($lease->agreement_template_id);
        $this->assertSame([$second->id, $this->tenant->id], $lease->tenants()->orderBy('id')->pluck('contact_id')->all());
        $this->assertTrue($lease->tenants()->where('contact_id', $second->id)->value('is_primary') == 1, 'the first tenant chosen is the primary');
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_CREATED)->count());
        $this->assertSame(0, LeaseAgreementTerms::count(), 'no agreement linked, no agreement section, no terms row');
        $this->assertSame(0, Flow::count(), 'button (a) never makes a document');
    }

    public function test_the_lazy_but_valid_minimum_still_creates_the_lease(): void
    {
        $this->actingAs($this->admin)->post(route('corex.leases.store'), [
            'property_id' => $this->property->id, 'rental_amount' => '9000', 'start_date' => '2026-12-01',
            'tenant_contact_ids' => [$this->tenant->id],
        ])->assertSessionHasNoErrors();

        $lease = Lease::firstOrFail();
        $this->assertNull($lease->deposit_amount);
        $this->assertNull($lease->end_date);
        $this->assertFalse((bool) $lease->is_month_to_month);
        $this->assertNull($lease->lease_type);
    }

    public function test_agreement_details_are_stored_in_the_terms_row_and_extras(): void
    {
        $this->linkAgreement($this->agency, [
            'adults' => ['field' => 'a'], 'pets' => ['field' => 'p'], 'escalation_percent' => ['field' => 'e'],
            'escalation_month' => ['field' => 'm'], 'earliest_termination_date' => ['field' => 't'],
            'other_deduction' => ['field' => 'd', 'label' => 'Retainer'], 'garden_service' => ['field' => 'g', 'label' => 'Garden service'],
        ]);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload([
            'agreement' => [
                'adults' => '2', 'pets' => '  One small dog  ', 'escalation_percent' => '7.5', 'escalation_month' => '3',
                'earliest_termination_date' => '2027-02-28', 'other_deduction' => '150.00', 'garden_service' => 'Weekly',
                'not_in_the_map' => 'smuggled',
            ],
        ]))->assertSessionHasNoErrors();

        $terms = LeaseAgreementTerms::firstOrFail();
        $this->assertSame(Lease::firstOrFail()->id, $terms->lease_id);
        $this->assertSame(2, $terms->adults);
        $this->assertSame('One small dog', $terms->pets);
        $this->assertSame('7.50', (string) $terms->escalation_percent);
        $this->assertSame(3, $terms->escalation_month);
        $this->assertSame('2027-02-28', $terms->earliest_termination_date->toDateString());
        $this->assertEquals(['other_deduction' => '150.00', 'garden_service' => 'Weekly'], $terms->extra, 'MySQL JSON does not keep key order');
        $this->assertSame('captured', $terms->source);
        $this->assertSame($this->agency->id, $terms->agency_id);
    }

    public function test_a_field_the_agencys_lease_does_not_carry_is_dropped_even_when_posted(): void
    {
        $this->linkAgreement($this->agency, ['pets' => ['field' => 'p']]);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload([
            'agreement' => ['pets' => 'Cat', 'electricity_arrangement' => 'Prepaid', 'other_conditions' => 'No smoking'],
        ]))->assertSessionHasNoErrors();

        $terms = LeaseAgreementTerms::firstOrFail();
        $this->assertSame('Cat', $terms->pets);
        $this->assertNull($terms->electricity_arrangement);
        $this->assertNull($terms->other_conditions);
    }

    public function test_activate_immediately_makes_the_lease_active(): void
    {
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['activate_immediately' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(Lease::STATUS_ACTIVE, Lease::firstOrFail()->status);
    }

    public function test_an_activation_refused_by_the_overlap_guard_rolls_the_whole_capture_back(): void
    {
        $this->linkAgreement($this->agency, ['pets' => ['field' => 'p']]);
        Lease::create($this->leaseAttributes(['status' => Lease::STATUS_ACTIVE]));
        $before = ['leases' => Lease::count(), 'tenants' => LeaseTenant::count()];

        $this->actingAs($this->admin)->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->payload(['activate_immediately' => '1', 'agreement' => ['pets' => 'Cat']]))
            ->assertRedirect(route('corex.leases.create'))
            ->assertSessionHasErrors('status');

        $this->assertSame($before, ['leases' => Lease::count(), 'tenants' => LeaseTenant::count()], 'nothing half-created');
        $this->assertSame(0, LeaseAgreementTerms::count());
        $this->assertSame(0, LeaseEvent::where('event_type', LeaseEvent::TYPE_LEASE_CREATED)->count());
    }

    public function test_two_drafts_for_one_tenant_on_one_property_are_allowed(): void
    {
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['capture_key' => 'first']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['capture_key' => 'second']))->assertSessionHasNoErrors();

        $this->assertSame(2, Lease::count());
    }

    public function test_the_same_capture_submitted_twice_makes_one_lease(): void
    {
        $payload = $this->payload(['capture_key' => 'double-click']);

        $first = $this->actingAs($this->admin)->post(route('corex.leases.store'), $payload);
        $second = $this->actingAs($this->admin)->post(route('corex.leases.store'), $payload);

        $this->assertSame(1, Lease::count());
        $this->assertSame(1, LeaseTenant::count());
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'), 'the repeat lands on the same lease');
        $this->assertNotNull(Lease::firstOrFail()->capture_key);
        $this->assertSame(64, strlen((string) Lease::firstOrFail()->capture_key));
    }

    public function test_the_rental_application_must_be_for_the_chosen_property_and_the_agency(): void
    {
        $elsewhere = $this->makeProperty($this->agency, $this->branch, $this->admin);
        $app = RentalApplication::create(['contact_id' => $this->tenant->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $elsewhere->id, 'status' => 'draft', 'adults' => 3]);
        $theirs = RentalApplication::create(['contact_id' => $this->tenant->id, 'agency_id' => $this->rival->id, 'status' => 'draft']);

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['rental_application_id' => $app->id]))
            ->assertSessionHasErrors(['rental_application_id' => 'That rental application is for a different property.']);
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['rental_application_id' => $theirs->id]))
            ->assertSessionHasErrors('rental_application_id');
        $this->assertSame(0, Lease::count());

        $own = RentalApplication::create(['contact_id' => $this->tenant->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'draft']);
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['rental_application_id' => $own->id]))->assertSessionHasNoErrors();
        $this->assertSame('rental_application', Lease::firstOrFail()->source);
    }

    public function test_a_linked_rental_application_prefills_the_adults_the_lease_agreement_asks_for(): void
    {
        $this->linkAgreement($this->agency, ['adults' => ['field' => 'a']]);
        $app = RentalApplication::create(['contact_id' => $this->tenant->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => 'draft', 'adults' => 3]);

        $html = $this->actingAs($this->admin)
            ->get(route('corex.leases.create', ['property_id' => $this->property->id, 'rental_application_id' => $app->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('"adults":"3"', $this->alpineConfig($html));
    }

    #[DataProvider('malformedFields')]
    public function test_one_malformed_value_per_validated_field_is_a_clean_error_and_creates_nothing(string $field, mixed $value, string $errorKey): void
    {
        $this->linkAgreement($this->agency, [
            'adults' => ['field' => 'a'], 'escalation_percent' => ['field' => 'e'], 'escalation_month' => ['field' => 'm'],
            'earliest_termination_date' => ['field' => 't'], 'pets' => ['field' => 'p'], 'other_deduction' => ['field' => 'd'],
        ]);
        $payload = $this->payload();
        if ($field === 'is_month_to_month') {
            $payload['end_date'] = '2027-10-31';   // a month-to-month lease with an end date: one or the other
        }
        if (str_starts_with($field, 'agreement.')) {
            $payload['agreement'][substr($field, 10)] = $value;
        } else {
            $payload[$field] = $value;
        }

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $payload)->assertSessionHasErrors($errorKey);

        $this->assertSame(0, Lease::count());
        $this->assertSame(0, LeaseAgreementTerms::count());
    }

    public static function malformedFields(): array
    {
        return [
            'rent not a number' => ['rental_amount', 'abc', 'rental_amount'],
            'rent negative' => ['rental_amount', '-5', 'rental_amount'],
            'rent blank' => ['rental_amount', '', 'rental_amount'],
            'deposit negative' => ['deposit_amount', '-1', 'deposit_amount'],
            'start date garbage' => ['start_date', 'next week', 'start_date'],
            'start date blank' => ['start_date', '', 'start_date'],
            'end before start' => ['end_date', '2026-01-01', 'end_date'],
            'month-to-month with an end date' => ['is_month_to_month', '1', 'end_date'],
            'lease type too long' => ['lease_type', str_repeat('x', 41), 'lease_type'],
            'adults not a number' => ['agreement.adults', 'two', 'agreement.adults'],
            'adults negative' => ['agreement.adults', '-1', 'agreement.adults'],
            'escalation over 100' => ['agreement.escalation_percent', '150', 'agreement.escalation_percent'],
            'escalation month 13' => ['agreement.escalation_month', '13', 'agreement.escalation_month'],
            'earliest termination before start' => ['agreement.earliest_termination_date', '2026-01-01', 'agreement.earliest_termination_date'],
            'pets far too long' => ['agreement.pets', str_repeat('x', 300), 'agreement.pets'],
            'deduction negative' => ['agreement.other_deduction', '-1', 'agreement.other_deduction'],
            'tenants missing' => ['tenant_contact_ids', [], 'tenant_contact_ids'],
        ];
    }

    public function test_a_tenant_from_another_agency_is_refused(): void
    {
        $foreign = $this->makeContact($this->rival, 'Rival', 'Tenant');

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['tenant_contact_ids' => [$foreign->id]]))
            ->assertSessionHasErrors('tenant_contact_ids.0');
        $this->assertSame(0, Lease::count());
    }

    // ═══ button (b) — "Create lease & prepare for signing", up to the point the document would open (L3a) ═══

    public function test_prepare_for_signing_with_everything_present_creates_the_lease_and_opens_the_agreement_on_fill_and_review(): void
    {
        $row = $this->linkAgreement($this->agency, ['adults' => ['field' => 'a', 'required' => true], 'pets' => ['field' => 'p']]);
        $this->makeSignable();

        $response = $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload([
            'intent' => 'lease_and_sign', 'agreement_id' => $row->id, 'agreement' => ['adults' => '2'],
        ]));

        $lease = Lease::firstOrFail();
        $flow = Flow::firstOrFail();
        $response->assertRedirect(route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 5]));
        $this->assertStringContainsString('Nothing has been sent to anyone yet', (string) session('success'));
        $this->assertSame(Lease::STATUS_DRAFT, $lease->status, 'never activated by (b)');
        $this->assertSame((int) $row->docuperfect_template_id, (int) $lease->agreement_template_id, 'remembers which agreement it is for');
        $this->assertSame(Lease::SIGNING_PREPARED, $lease->signing_status);
        $this->assertSame($flow->id, $lease->signing_flow_id);
        $this->assertSame($lease->id, $flow->lease_id);
        $this->assertSame(5, $flow->current_step);
        $this->assertSame(2, LeaseAgreementTerms::firstOrFail()->adults);
        $this->assertSame(['agent', 'tenant', 'landlord'], array_column($flow->step_data['recipients']['recipients'], 'role'), 'always agent → tenant → landlord');
        $this->assertStringContainsString('prepared for signing', LeaseEvent::where('lease_id', $lease->id)->orderBy('id')->value('description'));
    }

    public function test_prepare_for_signing_with_a_signer_gap_creates_nothing_lists_it_with_a_link_and_keeps_the_input(): void
    {
        $this->linkAgreement($this->agency);
        // No landlord on the property, and the tenant has no ID number.

        $response = $this->actingAs($this->admin)->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign']));

        $response->assertRedirect(route('corex.leases.create'));
        $response->assertSessionHas('capture_gaps', fn (array $gaps) => collect($gaps)->pluck('label')->contains('A landlord linked to the property')
            && collect($gaps)->pluck('label')->contains('Thandi Nkosi (tenant) — ID or passport number')
            && collect($gaps)->every(fn ($g) => ! empty($g['fix_url'])));
        $this->assertSame(0, Lease::count(), 'rolled back — nothing created');
        $this->assertSame(0, LeaseTenant::count());
        $this->assertSame(0, Flow::count());

        // The screen shows the list with its links and keeps what was typed.
        $html = $this->actingAs($this->admin)->withSession(['capture_gaps' => [['key' => 'landlord', 'label' => 'A landlord linked to the property', 'fix_url' => 'https://x.test/fix']]])
            ->get(route('corex.leases.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="capture-gaps"', $html);
        $this->assertStringContainsString('A landlord linked to the property', $html);
        $this->assertStringContainsString('href="https://x.test/fix"', $html);
    }

    public function test_the_who_signs_panel_and_the_party_check_name_what_each_signer_still_needs(): void
    {
        $this->linkAgreement($this->agency);
        $noEmail = $this->makeContact($this->agency, 'Lerato', 'Mokoena');
        $noEmail->update(['email' => null]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="signers-panel"', $html);

        $this->actingAs($this->admin)->getJson(route('corex.leases.party-check', ['property_id' => $this->property->id, 'tenant_ids' => [$noEmail->id, $this->tenant->id]]))
            ->assertOk()
            ->assertJsonPath('property_chosen', true)
            ->assertJsonPath('landlord_missing', true)
            ->assertJsonPath('landlord_url', route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'contacts']))
            ->assertJsonPath('tenants.0.name', 'Lerato Mokoena')
            ->assertJsonPath('tenants.0.needs', ['email address', 'ID or passport number'])
            ->assertJsonPath('tenants.1.needs', ['ID or passport number']);

        $this->makeSignable();
        $this->actingAs($this->admin)->getJson(route('corex.leases.party-check', ['property_id' => $this->property->id, 'tenant_ids' => [$this->tenant->id]]))
            ->assertJsonPath('landlord_missing', false)
            ->assertJsonPath('landlords.0.needs', [])
            ->assertJsonPath('tenants.0.needs', []);
    }

    public function test_the_party_check_only_names_this_agencys_own_contacts_and_a_property_it_may_see(): void
    {
        $rivalContact = $this->makeContact($this->rival, 'Rival', 'Tenant');
        $rivalBranch = Branch::withoutGlobalScopes()->where('agency_id', $this->rival->id)->first() ?? Branch::create(['agency_id' => $this->rival->id, 'name' => 'Karoo']);
        $rivalAgent = User::factory()->create(['agency_id' => $this->rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin']);
        $rivalProperty = $this->makeProperty($this->rival, $rivalBranch, $rivalAgent);

        $this->actingAs($this->admin)->getJson(route('corex.leases.party-check', ['property_id' => $rivalProperty->id, 'tenant_ids' => [$rivalContact->id]]))
            ->assertOk()
            ->assertJsonPath('property_chosen', false)
            ->assertJsonPath('tenants', []);
    }

    public function test_the_commission_percentage_is_asked_for_only_when_the_agencys_lease_carries_a_service_fee(): void
    {
        $this->property->update(['commission_percent' => 10]);
        $this->linkAgreement($this->agency, ['agent_service_fee' => ['field' => 'service_fee']]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create', ['property_id' => $this->property->id]))->assertOk()->getContent();

        $this->assertStringContainsString('name="agreement[commission_percent]"', $html);
        $this->assertStringContainsString('Letting commission (%)', $html);
        $this->assertStringContainsString('"commission_percent":"10', $this->alpineConfig($html), 'starts at the property\'s own');
    }

    public function test_an_agency_whose_lease_has_no_service_fee_is_never_asked_for_a_commission(): void
    {
        $this->linkAgreement($this->agency);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="agreement[commission_percent]"', $html);
        $this->assertStringNotContainsString('Letting commission', $html);
    }

    public function test_prepare_for_signing_ignores_the_activate_tick(): void
    {
        $this->linkAgreement($this->agency);
        $this->makeSignable();

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign', 'activate_immediately' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertSame(Lease::STATUS_DRAFT, Lease::firstOrFail()->status);
    }

    public function test_prepare_for_signing_with_required_details_missing_names_them_creates_nothing_and_keeps_the_input(): void
    {
        $this->linkAgreement($this->agency, [
            'adults' => ['field' => 'a', 'required' => true],
            'pets' => ['field' => 'p', 'required' => true, 'label' => 'Pets allowed'],
            'escalation_percent' => ['field' => 'e'],
        ]);

        $this->actingAs($this->admin)->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign', 'agreement' => ['adults' => '', 'pets' => '   ', 'escalation_percent' => '6']]))
            ->assertRedirect(route('corex.leases.create'))
            ->assertSessionHasErrors(['agreement.adults' => 'Number of adults is needed for signing.', 'agreement.pets' => 'Pets allowed is needed for signing.']);

        $this->assertSame(0, Lease::count());
        $this->assertSame(0, LeaseTenant::count());
        $this->assertSame(0, LeaseAgreementTerms::count());

        // The screen comes back with the list and everything typed.
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();
        $this->assertStringContainsString('Number of adults is needed for signing.', $html);
        $this->assertStringContainsString('Pets allowed is needed for signing.', $html);
        $this->assertStringContainsString('name="rental_amount" value="8500"', $html);
        $this->assertStringContainsString('"escalation_percent":"6"', $this->alpineConfig($html));
    }

    public function test_a_required_detail_of_zero_counts_as_filled_in(): void
    {
        $this->linkAgreement($this->agency, ['max_other_persons' => ['field' => 'o', 'required' => true]]);
        $this->makeSignable();

        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign', 'agreement' => ['max_other_persons' => '0']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, LeaseAgreementTerms::firstOrFail()->max_other_persons);
    }

    public function test_a_forged_prepare_for_signing_with_no_lease_agreement_is_refused_and_creates_nothing(): void
    {
        $this->actingAs($this->admin)->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign']))
            ->assertSessionHasErrors(['intent' => 'Your agency has not set up a lease agreement yet.']);

        $this->assertSame(0, Lease::count());
    }

    public function test_another_agencys_or_a_made_up_agreement_id_is_refused_with_one_generic_message(): void
    {
        $this->linkAgreement($this->agency);
        $theirs = $this->linkAgreement($this->rival, ['pets' => ['field' => 'p']], 'Karoo Secret Lease');

        foreach ([$theirs->id, 987654] as $forged) {
            $this->actingAs($this->admin)->from(route('corex.leases.create'))
                ->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign', 'agreement_id' => $forged]))
                ->assertSessionHasErrors(['intent' => \App\Services\Rentals\LeaseAgreementTemplateGuard::NOT_AVAILABLE]);
        }
        $this->assertSame(0, Lease::count());
        $this->assertStringNotContainsString('Karoo', json_encode(session('errors')?->getBag('default')->all() ?? []));
    }

    public function test_the_ownerless_shared_template_cannot_be_the_agreement_for_anyone(): void
    {
        // The built-in kind: no owning agency. A row that points at it is never "ready", so it never links.
        $shared = new Template(['name' => 'Built-in lease', 'render_type' => 'pdf', 'is_esign' => true, 'signing_parties' => ['owner_party', 'acquiring_party', 'agent']]);
        $shared->agency_id = null;
        $shared->save();
        RentalLeaseTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Pointing at the shared one', 'docuperfect_template_id' => $shared->id,
            'category' => 'residential', 'is_active' => true, 'field_map' => $this->completeMap(),
        ]);

        $this->actingAs($this->admin)->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign']))
            ->assertSessionHasErrors('intent');
        $this->assertSame(0, Lease::count());
    }

    public function test_prepare_for_signing_without_document_access_is_refused_with_the_plain_message(): void
    {
        $this->linkAgreement($this->agency);
        $agent = $this->makeUserWithPermissions('no_docs_agent', ['leases.view', 'leases.create', 'properties.view']);

        $this->actingAs($agent)->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->payload(['intent' => 'lease_and_sign']))
            ->assertSessionHasErrors(['intent' => 'You do not have access to prepare agreements.']);
        $this->assertSame(0, Lease::count());
    }

    // ═══ the signed paper copy (R2) — on a brand-new lease, with no lease agreement linked ═══

    public function test_a_signed_paper_copy_on_a_new_lease_files_it_activates_and_records_it(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload([
            'intent' => 'paper_copy', 'signed_document' => UploadedFile::fake()->create('signed-lease.pdf', 100, 'application/pdf'),
        ]));

        $lease = Lease::firstOrFail();
        $response->assertRedirect(route('corex.leases.show', $lease));
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->status);
        $this->assertSame(Lease::SIGNING_SIGNED_ON_PAPER, $lease->signing_status);
        $this->assertSame(Lease::SOURCE_UPLOADED_SIGNED_COPY, $lease->source);

        $document = Document::where('source_type', 'lease')->where('source_id', $lease->id)->firstOrFail();
        $this->assertTrue($document->properties->contains($this->property->id));
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame($lease->id, $lease->signedDocument()?->source_id);
        $this->assertSame(1, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_LEASE_SIGNED_ON_PAPER)->count());
    }

    public function test_a_paper_copy_with_no_file_is_a_clean_error(): void
    {
        $this->actingAs($this->admin)->post(route('corex.leases.store'), $this->payload(['intent' => 'paper_copy']))
            ->assertSessionHasErrors('signed_document');

        $this->assertSame(0, Lease::count());
    }

    public function test_a_paper_copy_refused_by_the_overlap_guard_rolls_back_and_leaves_no_file_behind(): void
    {
        Storage::fake('local');
        Lease::create($this->leaseAttributes(['status' => Lease::STATUS_ACTIVE]));

        $this->actingAs($this->admin)->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->payload([
                'intent' => 'paper_copy', 'signed_document' => UploadedFile::fake()->create('signed-lease.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('status');

        $this->assertSame(1, Lease::count(), 'only the already-active one');
        $this->assertSame(0, Document::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'the stored file is removed with the rolled-back capture');
    }

    // ═══ the renewal screen and writer — same screen, same service (L4, L5, R7) ═══

    public function test_the_renewal_screen_is_the_capture_screen_with_the_tenants_read_only_and_the_new_lease_panel(): void
    {
        $this->linkAgreement($this->agency, ['pets' => ['field' => 'p'], 'adults' => ['field' => 'a']]);
        $current = $this->activeLease(['rental_amount' => 9000, 'deposit_amount' => 18000, 'end_date' => '2026-10-31', 'start_date' => '2025-11-01']);
        $terms = LeaseAgreementTerms::forLease($current);
        $terms->fill(['adults' => 2, 'pets' => 'One cat'])->save();

        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();

        $this->assertStringContainsString('Renew this lease', $html);
        $this->assertStringContainsString('Renew lease only', $html);
        $this->assertStringContainsString('Renew lease &amp; prepare for signing', $html);
        $this->assertStringContainsString('Thandi Nkosi', $html);
        $this->assertStringNotContainsString('tenant_contact_ids', $html, 'no tenant field on a renewal (R7)');
        $this->assertStringNotContainsString('id="lease-property-search"', $html);
        $this->assertStringContainsString('A renewal keeps the same tenants.', $html);
        $this->assertStringContainsString('Start a new lease for this property', $html);
        $this->assertStringContainsString(route('corex.leases.create', ['property_id' => $this->property->id]), $html);
        $this->assertStringContainsString('The current lease must be ended before the new one can be made active.', $html);
        // Pre-filled from the previous term.
        $this->assertStringContainsString('name="rental_amount" value="9000.00"', $html);
        $this->assertStringContainsString('name="deposit_amount" value="18000.00"', $html);
        $this->assertStringContainsString('name="start_date" value="2026-11-01"', $html, 'the day after the previous term ended');
        $this->assertStringContainsString('One cat', $html);
        $this->assertStringNotContainsString('Not on record', $html, 'both details are on record');
    }

    public function test_a_wet_ink_first_lease_renews_with_the_agreement_details_marked_not_on_record(): void
    {
        $this->linkAgreement($this->agency, ['pets' => ['field' => 'p'], 'adults' => ['field' => 'a']]);
        $current = $this->activeLease(['source' => 'uploaded_signed_copy']);   // a paper lease: no terms row at all

        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'Not on record — fill in'));
        $this->assertStringContainsString('Renew lease only', $html);
        $this->assertStringContainsString('Renew lease &amp; prepare for signing', $html);
        $this->assertStringContainsString('name="rental_amount" value="9000.00"', $html, 'what the system lease holds is still pre-filled');
    }

    public function test_a_renewal_without_a_lease_agreement_still_offers_lease_only_and_the_paper_copy(): void
    {
        $current = $this->activeLease();

        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();

        $this->assertStringContainsString('Renew lease only', $html);
        $this->assertStringContainsString('Your agency has not set up a lease agreement yet.', $html);
        $this->assertStringContainsString('I already have the signed copy — attach it', $html);
    }

    public function test_renewing_a_lease_that_is_not_active_is_a_clean_message_not_an_error_page(): void
    {
        $draft = Lease::create($this->leaseAttributes());

        $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $draft))
            ->assertRedirect(route('corex.leases.show', $draft))
            ->assertSessionHasErrors('lease');
    }

    public function test_renewal_lease_only_creates_a_chained_draft_with_the_same_tenants_and_ignores_a_forged_tenant_field(): void
    {
        $current = $this->activeLease();
        $stranger = $this->makeContact($this->agency, 'Not', 'Allowed');

        $response = $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '9600', 'deposit_amount' => '',
            'tenant_contact_ids' => [$stranger->id],
        ]);

        $draft = Lease::where('previous_lease_id', $current->id)->firstOrFail();
        $response->assertRedirect(route('corex.leases.show', $draft))->assertSessionHas('success', 'Renewal created.');
        $this->assertSame(Lease::STATUS_DRAFT, $draft->status);
        $this->assertSame([$this->tenant->id], $draft->tenants()->pluck('contact_id')->all(), 'tenants rebuilt from the previous term (R7)');
        $this->assertSame('9600.00', (string) $draft->rental_amount);
        $this->assertNull($draft->deposit_amount, 'a cleared deposit stays cleared');
        $this->assertSame(Lease::STATUS_ACTIVE, $current->fresh()->status, 'the current lease is untouched until the renewal goes live');
        $this->assertSame(1, LeaseEvent::where('lease_id', $draft->id)->where('event_type', LeaseEvent::TYPE_LEASE_CREATED)->count());
    }

    public function test_renewal_activate_immediately_expires_the_previous_term_and_records_the_escalation(): void
    {
        $current = $this->activeLease(['rental_amount' => 9000]);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '9900', 'activate_immediately' => '1',
        ])->assertSessionHasNoErrors();

        $new = Lease::where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertSame(Lease::STATUS_ACTIVE, $new->status);
        $this->assertSame(Lease::STATUS_EXPIRED, $current->fresh()->status);
        $this->assertSame($new->id, $current->fresh()->renewed_lease_id);
        $this->assertSame('10.00', (string) LeaseEscalation::where('lease_id', $new->id)->value('escalation_rate_percent'));
    }

    public function test_renewal_agreement_details_are_saved_on_the_new_term_and_the_old_term_is_left_alone(): void
    {
        $this->linkAgreement($this->agency, ['pets' => ['field' => 'p'], 'earliest_termination_date' => ['field' => 't']]);
        $current = $this->activeLease(['start_date' => '2025-11-01', 'end_date' => '2026-10-31']);
        LeaseAgreementTerms::forLease($current)->fill(['pets' => 'One cat', 'earliest_termination_date' => '2026-04-30'])->save();

        // The screen pre-fills the earliest termination date shifted by the same offset as the start date (365 days).
        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();
        $this->assertStringContainsString('"earliest_termination_date":"2027-04-30"', $this->alpineConfig($html));

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '9500',
            'agreement' => ['pets' => 'Two cats', 'earliest_termination_date' => '2027-04-30'],
        ])->assertSessionHasNoErrors();

        $new = Lease::where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertSame('Two cats', $new->agreementTerms->pets);
        $this->assertSame('One cat', $current->agreementTerms->fresh()->pets, 'the previous term keeps what it was signed with');
    }

    public function test_renewal_prepare_for_signing_checks_the_required_details_then_opens_a_new_agreement_for_the_new_term(): void
    {
        $this->linkAgreement($this->agency, ['pets' => ['field' => 'p', 'required' => true]]);
        $this->makeSignable();
        $current = $this->activeLease(['source' => 'uploaded_signed_copy']);

        $this->actingAs($this->admin)->from(route('corex.leases.renewal.create', $current))
            ->post(route('corex.leases.renewal.store', $current), ['intent' => 'lease_and_sign', 'start_date' => '2026-11-01', 'rental_amount' => '9500'])
            ->assertRedirect(route('corex.leases.renewal.create', $current))
            ->assertSessionHasErrors('agreement.pets');
        $this->assertSame(1, Lease::count());

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'lease_and_sign', 'start_date' => '2026-11-01', 'rental_amount' => '9500', 'agreement' => ['pets' => 'None'],
        ])->assertSessionHasNoErrors();

        $new = Lease::where('previous_lease_id', $current->id)->firstOrFail();
        $flow = Flow::firstOrFail();
        $this->assertSame(Lease::STATUS_DRAFT, $new->status);
        $this->assertStringContainsString('Renewal created.', (string) session('success'));
        $this->assertSame($new->id, $flow->lease_id, 'a NEW agreement for the NEW term');
        $this->assertSame(Lease::SIGNING_PREPARED, $new->signing_status);
        $this->assertSame($flow->id, $new->renewal_draft_flow_id, 'the older renewal pointer is kept');
        $this->assertSame(['agent', 'tenant', 'landlord'], array_column($flow->step_data['recipients']['recipients'], 'role'));
        $this->assertSame('9500.00', $flow->step_data['details']['monthly_rental']);
        $this->assertSame(Lease::SIGNING_NOT_SENT, $current->fresh()->signing_status, 'the term being renewed is left alone');
        $this->assertSame(Lease::STATUS_ACTIVE, $current->fresh()->status);
    }

    public function test_a_signed_paper_copy_on_a_renewal_activates_it_and_files_the_copy(): void
    {
        Storage::fake('local');
        $current = $this->activeLease(['rental_amount' => 8500]);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $current), [
            'intent' => 'paper_copy', 'start_date' => '2026-11-01', 'rental_amount' => '9200',
            'signed_document' => UploadedFile::fake()->create('renewal.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $new = Lease::where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertSame(Lease::STATUS_ACTIVE, $new->status);
        $this->assertSame(Lease::SIGNING_SIGNED_ON_PAPER, $new->signing_status);
        $this->assertSame(Lease::STATUS_EXPIRED, $current->fresh()->status);
        $this->assertNotNull(Document::where('source_type', 'lease')->where('source_id', $new->id)->first());
        $this->assertStringContainsString('lease-renewals/' . $new->id, Document::where('source_id', $new->id)->value('storage_path'));
    }

    public function test_the_retired_upload_route_still_works_through_the_same_service(): void
    {
        Storage::fake('local');
        $current = $this->activeLease(['rental_amount' => 8500, 'deposit_amount' => 17000]);

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.upload', $current), [
            'start_date' => '2026-11-01', 'rental_amount' => 9200, 'signed_document' => UploadedFile::fake()->create('renewal.pdf', 100, 'application/pdf'),
        ])->assertRedirect();

        $new = Lease::where('previous_lease_id', $current->id)->firstOrFail();
        $this->assertSame(Lease::STATUS_ACTIVE, $new->status);
        $this->assertSame('17000.00', (string) $new->deposit_amount, 'older callers that send no deposit keep the previous one');
    }

    public function test_another_agencys_lease_is_a_404_on_the_renewal_screen_and_post(): void
    {
        $current = $this->activeLease();
        $rivalAdmin = User::factory()->create(['agency_id' => $this->rival->id, 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($rivalAdmin)->get(route('corex.leases.renewal.create', $current))->assertNotFound();
        $this->actingAs($rivalAdmin)->post(route('corex.leases.renewal.store', $current), ['intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '1'])->assertNotFound();
        $this->assertSame(1, Lease::withoutGlobalScopes()->count(), 'nothing was created');
    }

    public function test_an_own_scope_agent_cannot_renew_someone_elses_lease(): void
    {
        $current = $this->activeLease();   // created by $this->admin
        $agent = $this->makeUserWithPermissions('own_scope_agent', ['leases.view', 'leases.renew'], 'own');

        $this->actingAs($agent)->get(route('corex.leases.renewal.create', $current))->assertForbidden();
        $this->actingAs($agent)->post(route('corex.leases.renewal.store', $current), ['intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '1'])->assertForbidden();
        $this->assertSame(1, Lease::count());
    }

    public function test_the_renewal_post_refuses_a_lease_that_is_not_active_cleanly(): void
    {
        $draft = Lease::create($this->leaseAttributes());

        $this->actingAs($this->admin)->post(route('corex.leases.renewal.store', $draft), ['intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '9000'])
            ->assertSessionHasErrors('lease');
        $this->assertSame(1, Lease::count());
    }

    // ═══ the API mirror (rule #7) ═══

    public function test_the_api_creates_a_lease_and_answers_with_its_state_and_link(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['capture_key' => 'api-1']));

        $lease = Lease::firstOrFail();
        $response->assertCreated()
            ->assertJsonPath('lease.id', $lease->id)
            ->assertJsonPath('signing_status', 'not_sent')
            ->assertJsonPath('redirect_url', route('corex.leases.show', $lease));

        // Replayed: the same lease, no duplicate.
        $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['capture_key' => 'api-1']))->assertCreated();
        $this->assertSame(1, Lease::count());
    }

    public function test_the_api_prepares_the_agreement_and_points_at_fill_and_review(): void
    {
        $this->linkAgreement($this->agency);
        $this->makeSignable();

        $response = $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['intent' => 'lease_and_sign']));

        $flow = Flow::firstOrFail();
        $response->assertCreated()
            ->assertJsonPath('signing_status', 'prepared')
            ->assertJsonPath('redirect_url', route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 5]));
    }

    public function test_the_api_lists_a_signer_gap_with_its_fix_link_and_creates_nothing(): void
    {
        $this->linkAgreement($this->agency);

        $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['intent' => 'lease_and_sign']))
            ->assertStatus(422)
            ->assertJsonPath('missing.0.key', 'landlord')
            ->assertJsonPath('missing.0.fix_url', route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'contacts']));
        $this->assertSame(0, Lease::count());
        $this->assertSame(0, Flow::count());
    }

    public function test_the_api_reports_where_a_leases_agreement_stands(): void
    {
        $this->linkAgreement($this->agency);
        $this->makeSignable();
        $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['intent' => 'lease_and_sign']))->assertCreated();
        $lease = Lease::firstOrFail();
        $flow = Flow::firstOrFail();

        $this->actingAs($this->admin)->getJson('/api/v1/leases/' . $lease->id . '/signing')
            ->assertOk()
            ->assertJsonPath('signing_status', 'prepared')
            ->assertJsonPath('signing_status_label', 'Being prepared')
            ->assertJsonPath('signers.0.role', 'agent')
            ->assertJsonPath('signers.1.role', 'tenant')
            ->assertJsonPath('signers.2.role', 'landlord')
            ->assertJsonPath('continue_url', route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 5]));
    }

    public function test_the_api_status_of_another_agencys_lease_is_a_404(): void
    {
        $rivalBranch = Branch::create(['agency_id' => $this->rival->id, 'name' => 'Karoo']);
        $rivalAgent = User::factory()->create(['agency_id' => $this->rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'admin', 'is_active' => true]);
        $lease = Lease::create($this->leaseAttributes());

        $this->actingAs($rivalAgent)->getJson('/api/v1/leases/' . $lease->id . '/signing')->assertNotFound();
    }

    public function test_the_api_answers_409_with_a_code_when_no_lease_agreement_is_linked(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['intent' => 'lease_and_sign']))
            ->assertStatus(409)->assertJsonPath('code', 'no_lease_agreement_linked');
        $this->assertSame(0, Lease::count());
    }

    public function test_the_api_answers_422_with_the_missing_list_for_required_agreement_details(): void
    {
        $this->linkAgreement($this->agency, ['adults' => ['field' => 'a', 'required' => true]]);

        $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['intent' => 'lease_and_sign']))
            ->assertStatus(422)
            ->assertJsonPath('missing.0.key', 'adults')
            ->assertJsonPath('missing.0.label', 'Number of adults');
        $this->assertSame(0, Lease::count());
    }

    public function test_the_api_validates_fields_and_renews_through_previous_lease_id(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', $this->payload(['rental_amount' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors('rental_amount');

        $current = $this->activeLease();
        $this->actingAs($this->admin)->postJson('/api/v1/leases/capture', [
            'previous_lease_id' => $current->id, 'intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '9700',
        ])->assertCreated();
        $this->assertSame($current->id, Lease::where('rental_amount', 9700)->firstOrFail()->previous_lease_id);
    }

    public function test_the_api_does_not_treat_another_agencys_lease_as_a_previous_lease(): void
    {
        // Its own test (the rival is the FIRST API caller): one app instance serves every request of a test, and
        // Sanctum keeps the first caller on its guard — a real request never sees that.
        $current = $this->activeLease();
        $rivalAdmin = User::factory()->create(['agency_id' => $this->rival->id, 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($rivalAdmin)->postJson('/api/v1/leases/capture', [
            'previous_lease_id' => $current->id, 'intent' => 'lease_only', 'start_date' => '2026-11-01', 'rental_amount' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('previous_lease_id');

        $this->assertSame(1, Lease::withoutGlobalScopes()->count(), 'nothing was created, and nothing hints the lease exists');
    }

    public function test_the_api_holds_a_user_without_the_permission_for_the_mode_out(): void
    {
        $viewer = $this->makeUserWithPermissions('viewer_only', ['leases.view']);

        $this->actingAs($viewer)->postJson('/api/v1/leases/capture', $this->payload())->assertForbidden();
        $this->assertSame(0, Lease::count());
    }

    // ═══ the lease edit lock, and the Leases list additions (§15.13) ═══

    public function test_a_lease_whose_agreement_is_out_for_signing_refuses_a_forged_edit(): void
    {
        $lease = Lease::create($this->leaseAttributes(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING, 'deposit_amount' => 5000]));

        $this->actingAs($this->admin)->put(route('corex.leases.update', $lease), ['deposit_amount' => 9999])
            ->assertSessionHasErrors(['lease' => Lease::LOCKED_FOR_SIGNING_MESSAGE]);

        $this->assertSame('5000.00', (string) $lease->fresh()->deposit_amount);

        $lease->update(['signing_status' => Lease::SIGNING_NOT_SENT]);
        $this->actingAs($this->admin)->put(route('corex.leases.update', $lease), ['deposit_amount' => 9999])->assertSessionHasNoErrors();
        $this->assertSame('9999.00', (string) $lease->fresh()->deposit_amount);
    }

    public function test_the_edit_panel_shows_the_governed_fields_read_only_with_the_reason_only_while_the_agreement_is_out(): void
    {
        $locked = Lease::create($this->leaseAttributes(['signing_status' => Lease::SIGNING_AWAITING_AGENT_REVIEW]));
        $open = Lease::create($this->leaseAttributes(['property_id' => $this->makeProperty($this->agency, $this->branch, $this->admin)->id]));

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $locked))->assertOk()->getContent();
        $this->assertStringContainsString(Lease::LOCKED_FOR_SIGNING_MESSAGE, $html);
        $this->assertMatchesRegularExpression('/name="deposit_amount"[^>]*\sdisabled/', $html);
        $this->assertMatchesRegularExpression('/name="end_date"[^>]*\sdisabled/', $html);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $open))->assertOk()->getContent();
        $this->assertStringNotContainsString(Lease::LOCKED_FOR_SIGNING_MESSAGE, $html);
        $this->assertDoesNotMatchRegularExpression('/name="deposit_amount"[^>]*\sdisabled/', $html);
    }

    public function test_the_leases_list_filters_by_agreement_shows_a_sub_label_and_exports_the_columns(): void
    {
        $plain = Lease::create($this->leaseAttributes());
        $other = $this->makeProperty($this->agency, $this->branch, $this->admin);
        $signed = Lease::create($this->leaseAttributes(['property_id' => $other->id, 'signing_status' => Lease::SIGNING_SIGNED, 'signed_at' => '2026-09-30 10:00:00']));

        $all = $this->actingAs($this->admin)->get(route('corex.leases.index'))->assertOk();
        $all->assertSee('Signed');
        $all->assertSee('name="agreement"', false);

        $filtered = $this->actingAs($this->admin)->get(route('corex.leases.index', ['agreement' => 'signed']))->assertOk();
        $filtered->assertSee('data-qa="lease-row-' . $signed->id . '"', false);
        $filtered->assertDontSee('data-qa="lease-row-' . $plain->id . '"', false);

        // A made-up filter value is ignored, not an error.
        $this->actingAs($this->admin)->get(route('corex.leases.index', ['agreement' => 'nonsense']))->assertOk()
            ->assertSee('data-qa="lease-row-' . $plain->id . '"', false);

        $csv = $this->actingAs($this->admin)->get(route('corex.leases.export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Agreement status', $csv);
        $this->assertStringContainsString('Signed on', $csv);
        $this->assertStringContainsString('2026-09-30', $csv);
        $this->assertStringContainsString('Not sent', $csv);
    }

    // ═══ the service itself ═══

    public function test_an_unknown_intent_is_refused(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(LeaseCaptureService::class)->capture($this->payload(), 'something_else', $this->admin);
    }

    public function test_a_property_the_user_may_not_see_is_not_found_by_the_service_either(): void
    {
        $rivalProperty = $this->makeProperty($this->rival, Branch::create(['agency_id' => $this->rival->id, 'name' => 'Karoo']), $this->admin);
        $this->actingAs($this->admin);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        app(LeaseCaptureService::class)->capture($this->payload(['property_id' => $rivalProperty->id]), LeaseCaptureService::INTENT_LEASE_ONLY, $this->admin);
    }

    // ═══ helpers ═══

    /** The page with Js::from's escaped quotes (", backslash-doubled inside the JS string) turned back into plain quotes. */
    private function alpineConfig(string $html): string
    {
        return str_replace(['\\\\u0022', '\\u0022'], '"', $html);
    }

    /** @return array<string,mixed> a complete, valid POST body for the New Lease screen */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'property_id' => $this->property->id, 'rental_amount' => '8500', 'start_date' => '2026-11-01',
            'tenant_contact_ids' => [$this->tenant->id],
        ], $overrides);
    }

    private function leaseAttributes(array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 9000, 'start_date' => '2025-11-01', 'end_date' => '2026-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ], $overrides);
    }

    /** An ACTIVE lease for $this->tenant on $this->property, ending 31 Oct 2026. */
    private function activeLease(array $overrides = []): Lease
    {
        $lease = Lease::create($this->leaseAttributes(array_merge(['status' => Lease::STATUS_ACTIVE], $overrides)));
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        return $lease;
    }

    /**
     * Everything the signing gate needs on the default fixtures: the tenant has an ID number and the property
     * has a landlord with an email and an ID number.
     */
    private function makeSignable(): void
    {
        $this->tenant->update(['id_number' => '8002025009081']);
        $landlord = $this->makeContact($this->agency, 'Pieter', 'Botha');
        $landlord->update(['id_number' => '7001015009087']);
        \App\Models\ContactProperty::create(['contact_id' => $landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord']);
    }

    private function makeProperty(Agency $agency, Branch $branch, User $agent): Property
    {
        return Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function makeContact(Agency $agency, string $first, string $last): Contact
    {
        $branch = $agency->id === $this->agency->id
            ? $this->branch
            : (Branch::withoutGlobalScopes()->where('agency_id', $agency->id)->first() ?? Branch::create(['agency_id' => $agency->id, 'name' => 'Karoo']));

        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first) . uniqid() . '@example.test',
        ]);
    }

    /**
     * A throwaway AGENCY-OWNED lease agreement, linked and ready: an e-sign template with a signing place for
     * agent, tenant and landlord, and a field map that carries the five link requirements plus $extraMap.
     *
     * @param array<string,mixed> $extraMap
     */
    private function linkAgreement(Agency $agency, array $extraMap = [], string $name = 'Residential lease', array $rowOverrides = []): RentalLeaseTemplate
    {
        $template = new Template([
            'name' => $name . ' ' . uniqid(), 'render_type' => 'pdf', 'is_esign' => true,
            'signing_parties' => ['owner_party', 'acquiring_party', 'agent'],
        ]);
        $template->agency_id = $agency->id;
        $template->save();

        return RentalLeaseTemplate::create(array_merge([
            'agency_id' => $agency->id, 'name' => $name, 'docuperfect_template_id' => $template->id,
            'category' => RentalLeaseTemplate::CATEGORY_RESIDENTIAL, 'is_active' => true,
            'field_map' => $this->completeMap() + $extraMap,
        ], $rowOverrides));
    }

    private function completeMap(): array
    {
        return [
            'rent' => ['field' => 'monthly_rental'], 'start_date' => ['field' => 'lease_start'], 'end_date' => ['field' => 'lease_end'],
            'tenant_name' => ['field' => 'lessee_name'], 'landlord_name' => ['field' => 'lessor_name'],
        ];
    }

    /** @param list<string> $permissions */
    private function makeUserWithPermissions(string $role, array $permissions, string $scope = 'all'): User
    {
        Role::create(['name' => $role, 'label' => $role, 'agency_id' => $this->agency->id]);
        foreach ($permissions as $key) {
            RolePermission::updateOrCreate(
                ['role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id],
                ['scope' => $scope],
            );
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role, 'is_active' => true]);
    }
}
