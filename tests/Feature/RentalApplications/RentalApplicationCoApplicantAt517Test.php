<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Events\RentalApplication\RentalApplicationApproved;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactProperty;
use App\Models\ContactType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Services\Rentals\LeaseSigningLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Leases\BuildsLeaseAgreementFixture;
use Tests\TestCase;

/**
 * AT-517 - a Main applicant and an optional Co-applicant on ONE rental application, using the existing co_applicant block
 * plus rental_applications.co_applicant_contact_id. Spec: .ai/specs/rental-applications.md ("Co-applicant (AT-517)").
 */
final class RentalApplicationCoApplicantAt517Test extends TestCase
{
    use BuildsLeaseAgreementFixture;
    use RefreshDatabase;

    private const PARTNER_ID = '8501015800083';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpAgreementFixture();
    }

    private function application(array $over = []): RentalApplication
    {
        return RentalApplication::create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->tenant->id,
            'property_id' => $this->property->id, 'created_by_user_id' => $this->agent->id, 'status' => 'returned',
            'current_generation' => 1, 'token' => 'tok-' . uniqid(),
        ]);
    }

    private function typedBlock(): array
    {
        return ['full_name' => 'Zinzi Dlamini', 'id_number' => self::PARTNER_ID, 'email' => 'zinzi@example.test', 'cell' => '0821234567'];
    }

    // ═══ 1. New Application ═══

    public function test_new_application_stores_the_co_applicant_contact_and_prefills_the_block_from_it(): void
    {
        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID, '12 Marine Drive, Umhlanga');

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $this->tenant->id, 'co_applicant_contact_id' => $co->id, 'property_id' => $this->property->id,
        ])->assertRedirect();

        $app = RentalApplication::latest('id')->first();
        $this->assertSame($this->tenant->id, (int) $app->contact_id);
        $this->assertSame($co->id, (int) $app->co_applicant_contact_id);
        $this->assertSame('Zinzi Dlamini', $app->co_applicant['full_name']);
        $this->assertSame(self::PARTNER_ID, $app->co_applicant['id_number']);
        $this->assertSame($co->email, $app->co_applicant['email']);
        $this->assertSame($app->coApplicantContact->id, $co->id);
    }

    public function test_the_new_application_screen_offers_an_optional_co_applicant_picker_beside_the_main_applicant(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Main applicant', $html);
        $this->assertStringContainsString('data-qa="co-applicant-picker"', $html);
        $this->assertStringContainsString('name="co_applicant_contact_id"', $html);
        $this->assertStringContainsString('Co-applicant (optional)', $html);
        $this->assertStringNotContainsStringIgnoringCase('primary applicant', $html);
    }

    public function test_an_application_without_a_co_applicant_is_unchanged(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), ['contact_id' => $this->tenant->id])->assertRedirect();

        $app = RentalApplication::latest('id')->first();
        $this->assertNull($app->co_applicant_contact_id);
        $this->assertNull($app->co_applicant);
    }

    public function test_the_co_applicant_cannot_be_the_main_applicant(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $this->tenant->id, 'co_applicant_contact_id' => $this->tenant->id,
        ])->assertSessionHasErrors('co_applicant_contact_id');

        $this->assertSame(0, RentalApplication::count());
    }

    public function test_a_co_applicant_who_already_has_a_live_application_is_refused(): void
    {
        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $this->application(['contact_id' => $co->id, 'status' => 'sent']);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $this->tenant->id, 'co_applicant_contact_id' => $co->id,
        ])->assertSessionHasErrors('contact_id');

        $this->assertSame(1, RentalApplication::count());
    }

    // ═══ 3. Review screen: create the contact from what the applicant typed, and link ═══

    public function test_a_captured_co_applicant_is_created_as_a_contact_and_linked_and_logged(): void
    {
        $app = $this->application(['co_applicant' => $this->typedBlock()]);
        $before = Contact::count();

        $this->actingAs($this->agent)->post(route('corex.rental-applications.co-applicant.link', $app))->assertSessionHas('success');

        $app->refresh();
        $this->assertSame($before + 1, Contact::count());
        $co = $app->coApplicantContact;
        $this->assertSame('Zinzi', $co->first_name);
        $this->assertSame('Dlamini', $co->last_name);
        $this->assertSame(self::PARTNER_ID, $co->id_number);
        $this->assertSame($this->agency->id, (int) $co->agency_id);
        $this->assertNotNull(RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'contact_created_and_linked')->first());
    }

    public function test_a_duplicate_is_offered_instead_of_creating_a_second_contact_and_the_existing_one_can_be_linked(): void
    {
        $existing = $this->contact('Zinzi', 'Dlamini', '850101 5800 083');
        $app = $this->application(['co_applicant' => $this->typedBlock()]);
        $before = Contact::count();

        $this->actingAs($this->agent)->post(route('corex.rental-applications.co-applicant.link', $app))
            ->assertSessionHas('co_applicant_duplicates', fn ($d) => in_array($existing->id, array_column($d['contacts'], 'id'), true));
        $this->assertSame($before, Contact::count(), 'nothing is created while a match is waiting for a choice');
        $this->assertNull($app->fresh()->co_applicant_contact_id);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.co-applicant.link', $app), ['contact_id' => $existing->id])->assertSessionHas('success');

        $this->assertSame($before, Contact::count());
        $this->assertSame($existing->id, (int) $app->fresh()->co_applicant_contact_id);
        $this->assertNotNull(RentalApplicationAuditLog::where('rental_application_id', $app->id)->where('event_type', 'contact_linked')->first());
    }

    public function test_create_anyway_creates_a_second_contact_after_the_match_was_shown(): void
    {
        $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $app = $this->application(['co_applicant' => $this->typedBlock()]);
        $before = Contact::count();

        $this->actingAs($this->agent)->post(route('corex.rental-applications.co-applicant.link', $app), ['create_anyway' => 1])->assertSessionHas('success');

        $this->assertSame($before + 1, Contact::count());
        $this->assertNotNull($app->fresh()->co_applicant_contact_id);
    }

    public function test_nothing_is_linked_when_the_block_has_no_name_or_the_application_is_already_decided_or_linked(): void
    {
        $noName = $this->application(['co_applicant' => ['cell' => '0821234567']]);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.co-applicant.link', $noName))->assertSessionHas('error');
        $this->assertNull($noName->fresh()->co_applicant_contact_id);

        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $decided = $this->application(['status' => 'approved', 'co_applicant' => $this->typedBlock()]);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.co-applicant.link', $decided))->assertSessionHas('error');
        $this->assertNull($decided->fresh()->co_applicant_contact_id);

        $linked = $this->application(['co_applicant' => $this->typedBlock(), 'co_applicant_contact_id' => $co->id]);
        $this->actingAs($this->agent)->post(route('corex.rental-applications.co-applicant.link', $linked))->assertSessionHas('error');
    }

    public function test_the_review_screen_header_shows_the_captured_line_then_the_linked_co_applicant(): void
    {
        $app = $this->application(['co_applicant' => $this->typedBlock()]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="co-applicant-captured"', $html);
        $this->assertStringContainsString('Co-applicant captured', $html);
        $this->assertStringContainsString('Zinzi Dlamini', $html);
        $this->assertStringContainsString('Create contact and link', $html);

        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $app->forceFill(['co_applicant_contact_id' => $co->id])->save();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="co-applicant-linked"', $html);
        $this->assertStringContainsString(route('corex.contacts.show', $co), $html);
        $this->assertStringNotContainsString('Create contact and link', $html);
    }

    // ═══ 4. Decisions cover both contacts together ═══

    public function test_approval_caps_tags_and_marks_both_contacts(): void
    {
        ContactType::firstOrCreate(['name' => 'Tenant']);
        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $mine = $this->coreMatch($this->tenant, 30000);
        $theirs = $this->coreMatch($co, 25000);
        $app = $this->application(['status' => 'approved', 'approved_rental_amount' => 10500.90, 'co_applicant_contact_id' => $co->id]);

        event(new RentalApplicationApproved($app, $this->agent->id));

        $this->assertSame(10500, (int) $mine->fresh()->price_max);
        $this->assertSame(10500, (int) $theirs->fresh()->price_max, 'the Co-applicant is capped too');
        $this->assertSame('approved', $this->tenant->fresh()->rental_application_status);
        $this->assertSame('approved', $co->fresh()->rental_application_status);
        $tenantId = (int) ContactType::where('name', 'Tenant')->value('id');
        $this->assertTrue($co->fresh()->parentTypes()->where('contact_types.id', $tenantId)->exists());
    }

    public function test_the_model_cap_governs_a_later_edit_to_the_co_applicants_match_and_the_history_lists_the_application_for_both(): void
    {
        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $app = $this->application(['status' => 'approved', 'approved_rental_amount' => 10000, 'co_applicant_contact_id' => $co->id]);

        $match = $this->coreMatch($co, null);
        $match->price_max = 50000;
        $match->save();
        $this->assertSame(10000, (int) $match->fresh()->price_max);

        $this->assertTrue($co->visibleRentalApplicationsFor($this->agent)->whereKey($app->id)->exists());
        $this->assertTrue($this->tenant->visibleRentalApplicationsFor($this->agent)->whereKey($app->id)->exists());
    }

    public function test_linking_the_approved_application_to_the_property_links_both_as_tenants_and_unlinking_removes_both(): void
    {
        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $app = $this->application(['status' => 'approved', 'approved_rental_amount' => 10000, 'co_applicant_contact_id' => $co->id]);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.link-tenant-property', $app), ['property_id' => $this->property->id]);

        foreach ([$this->tenant, $co] as $c) {
            $this->assertSame('tenant', ContactProperty::where('contact_id', $c->id)->where('property_id', $this->property->id)->value('role'), $c->first_name);
        }

        $this->actingAs($this->agent)->delete(route('corex.rental-applications.unlink-tenant-property', $app));

        foreach ([$this->tenant, $co] as $c) {
            $this->assertFalse(ContactProperty::where('contact_id', $c->id)->where('property_id', $this->property->id)->exists(), $c->first_name);
        }
    }

    // ═══ 5. Lease: both are tenants and both sign ═══

    public function test_the_lease_screen_seeds_the_linked_co_applicant_as_the_second_tenant_with_no_id_number_needed(): void
    {
        $co = $this->contact('Zinzi', 'Dlamini', null);
        $app = $this->application(['status' => 'approved', 'approved_rental_amount' => 10000, 'co_applicant_contact_id' => $co->id]);

        $response = $this->actingAs($this->agent)->get(route('corex.leases.create', ['rental_application_id' => $app->id]))->assertOk();

        $this->assertSame([$this->tenant->id, $co->id], array_column($response->viewData('oldTenants'), 'id'));
        $this->assertNull($response->viewData('coApplicantOffer'));
    }

    public function test_the_old_id_number_match_still_seeds_the_partner_when_no_co_applicant_is_linked(): void
    {
        $partner = $this->contact('Zinzi', 'Dlamini', '850101 5800 083');
        $app = $this->application(['status' => 'approved', 'approved_rental_amount' => 10000]);
        $app->forceFill(['spouse_name' => 'Zinzi Dlamini', 'spouse_id' => self::PARTNER_ID])->save();

        $response = $this->actingAs($this->agent)->get(route('corex.leases.create', ['rental_application_id' => $app->id]))->assertOk();

        $this->assertSame([$this->tenant->id, $partner->id], array_column($response->viewData('oldTenants'), 'id'));
    }

    public function test_a_lease_with_both_tenants_gives_each_their_own_signer_and_the_co_applicant_their_own_address(): void
    {
        $co = $this->contact('Zinzi', 'Dlamini', self::PARTNER_ID);
        $this->application([
            'status' => 'approved', 'co_applicant_contact_id' => $co->id,
            'co_applicant' => $this->typedBlock() + ['address' => '12 Marine Drive, Umhlanga'],
        ]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 8500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $co->id, 'is_primary' => false]);

        $rows = collect(app(LeaseSigningLauncher::class)->recipientRows($lease, $this->agent));

        $tenants = $rows->where('role', 'tenant')->values();
        $this->assertSame([$this->tenant->id, $co->id], $tenants->pluck('_contact_id')->all());
        $this->assertSame('12 Marine Drive, Umhlanga', $tenants[1]['address']);
    }

    private function coreMatch(Contact $contact, ?int $max): ContactMatch
    {
        return ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $this->agent->id, 'name' => 'Rental search', 'listing_type' => 'rental',
            'status' => ContactMatch::STATUS_ACTIVE, 'price_max' => $max,
        ]);
    }
}
