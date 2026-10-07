<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use App\Services\Rentals\LeaseHubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, QA1 rentals test, 2026-10-07 — SUPERSEDES leases.md §1.3a ("linking creates and activates a
 * lease"). Linking a property to an approved application created lease 93 already ACTIVE with rent
 * copied from the approved amount, no dates chosen and no agreement: the e-sign path (§15) never came
 * into play. Now linking only links the tenant and sends the agent to the lease screen (§15.3),
 * pre-filled from the property; the lease is created there, as a draft, with the rent-above-approved
 * policy (warn-and-confirm, or block) enforced and logged.
 */
final class LeaseFromApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Agency $agency;
    private Branch $branch;
    private Property $property;
    private RentalApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Ayanda', 'last_name' => 'Tenant', 'email' => 'ayanda-' . uniqid() . '@example.test',
        ]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id,
            'title' => 'Flat to let', 'status' => 'active', 'listing_type' => 'rental',
            'address' => '401 Margate Boulevard', 'suburb' => 'Margate', 'city' => 'Margate',
            'rental_amount' => 11400, 'deposit_amount' => 11400,
        ]);
        $this->application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $this->user->id,
            'status' => 'approved', 'approved_rental_amount' => 10000, 'approved_deposit_amount' => 10000,
            'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);
    }

    private function link()
    {
        return $this->actingAs($this->user)->post(
            route('corex.rental-applications.link-tenant-property', $this->application),
            ['property_id' => $this->property->id],
        );
    }

    private function capture(array $extra = [])
    {
        return $this->actingAs($this->user)->post(route('corex.leases.store'), array_merge([
            'intent' => 'lease_only',
            'property_id' => $this->property->id,
            'rental_application_id' => $this->application->id,
            'tenant_contact_ids' => [$this->application->contact_id],
            'rental_amount' => 11400,
            'deposit_amount' => 11400,
            'start_date' => '2026-11-01',
            'end_date' => '2027-10-31',
        ], $extra));
    }

    private function application_leases(): int
    {
        return Lease::withoutGlobalScopes()->where('rental_application_id', $this->application->id)->count();
    }

    public function test_choosing_the_property_only_opens_the_lease_screen_and_writes_nothing(): void
    {
        $this->actingAs($this->user)->get(route('corex.rental-applications.show', $this->application))
            ->assertOk()
            ->assertSee('method="GET" action="' . route('corex.leases.create') . '"', false)
            ->assertSee('Continue to the lease');

        // Opening (and so cancelling) the lease screen links nothing and creates nothing.
        $this->actingAs($this->user)->get(route('corex.leases.create', [
            'property_id' => $this->property->id, 'rental_application_id' => $this->application->id,
        ]))->assertOk();

        self::assertSame(0, $this->application_leases());
        self::assertNull($this->application->fresh()->property_id);
        self::assertSame(0, \DB::table('contact_property')->where('contact_id', $this->application->contact_id)->count());
    }

    public function test_completing_the_lease_screen_links_the_property_and_the_tenant(): void
    {
        $this->capture(['rent_above_approved_reason' => 'agreed'])->assertSessionDoesntHaveErrors();

        self::assertSame($this->property->id, $this->application->fresh()->property_id);
        self::assertSame('tenant', \DB::table('contact_property')
            ->where('contact_id', $this->application->contact_id)->where('property_id', $this->property->id)->value('role'));
        self::assertSame(1, $this->application_leases());
    }

    public function test_an_approved_application_may_pick_a_different_property_than_the_one_it_carried(): void
    {
        $other = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id,
            'title' => 'Other flat', 'status' => 'active', 'listing_type' => 'rental', 'address' => '9 Other Road',
            'suburb' => 'Margate', 'city' => 'Margate', 'rental_amount' => 9000,
        ]);
        $this->application->update(['property_id' => $this->property->id]);

        $this->capture(['property_id' => $other->id, 'rental_amount' => 9000, 'deposit_amount' => 9000])->assertSessionDoesntHaveErrors();

        self::assertSame($other->id, $this->application->fresh()->property_id);
    }

    public function test_the_old_direct_link_endpoint_still_creates_no_lease(): void
    {
        $this->link()->assertRedirect();

        self::assertSame(0, $this->application_leases());
        self::assertSame('active', $this->property->fresh()->status);
    }

    public function test_the_lease_screen_opens_prefilled_with_the_applicant_and_the_properties_rent_not_the_approved_amount(): void
    {
        $html = $this->actingAs($this->user)->get(route('corex.leases.create', [
            'property_id' => $this->property->id,
            'rental_application_id' => $this->application->id,
        ]))->assertOk()->getContent();

        self::assertStringContainsString('name="rental_amount" value="11400"', $html);
        self::assertStringNotContainsString('name="rental_amount" value="10000"', $html);
        // Deposit = the approved deposit terms (10000 / 10000 = 1× rent) applied to the lease rent.
        self::assertStringContainsString('name="deposit_amount" value="11400"', $html);
        self::assertStringContainsString('Ayanda Tenant', $html);
        self::assertStringContainsString('name="rental_application_id" value="' . $this->application->id . '"', $html);
        self::assertStringContainsString('data-test="rent-above-approved"', $html);
        self::assertStringContainsString('Create lease only', $html);
        // Dates are the agent's to enter — never defaulted.
        self::assertStringContainsString('name="start_date" value=""', $html);
    }

    public function test_the_deposit_follows_the_approved_terms_when_they_are_not_one_month(): void
    {
        $this->application->update(['approved_deposit_amount' => 20000]); // 2× the approved rent

        $html = $this->actingAs($this->user)->get(route('corex.leases.create', [
            'property_id' => $this->property->id, 'rental_application_id' => $this->application->id,
        ]))->assertOk()->getContent();

        self::assertStringContainsString('name="deposit_amount" value="22800"', $html);
    }

    public function test_capture_creates_a_draft_lease_not_an_active_one_and_the_strip_does_not_say_signed(): void
    {
        $this->capture(['rent_above_approved_reason' => 'Landlord asking price; tenant agreed'])->assertSessionDoesntHaveErrors();

        $lease = Lease::withoutGlobalScopes()->where('rental_application_id', $this->application->id)->firstOrFail();
        self::assertSame('draft', $lease->status);
        self::assertSame('not_sent', $lease->signing_status);
        self::assertSame('rental_application', $lease->source);
        self::assertSame('11400.00', $lease->rental_amount);
        self::assertNotSame('let_out', $this->property->fresh()->status, 'A draft lease must not let the property out.');

        $signed = collect(app(LeaseHubService::class)->lifecycle($lease))->firstWhere('key', 'lease_signed');
        self::assertNotSame('done', $signed['state']);
    }

    public function test_an_active_lease_from_an_application_is_still_not_signed_without_a_signed_agreement(): void
    {
        $this->capture(['rent_above_approved_reason' => 'x', 'activate_immediately' => 1])->assertSessionDoesntHaveErrors();

        $lease = Lease::withoutGlobalScopes()->where('rental_application_id', $this->application->id)->firstOrFail();
        self::assertSame('active', $lease->status);
        $signed = collect(app(LeaseHubService::class)->lifecycle($lease))->firstWhere('key', 'lease_signed');
        self::assertNotSame('done', $signed['state']);
    }

    public function test_warn_mode_refuses_without_a_reason_and_creates_nothing(): void
    {
        $this->capture()->assertSessionHasErrors('rent_above_approved_reason');

        self::assertSame(0, $this->application_leases());
    }

    public function test_a_reason_is_logged_on_the_lease_history_and_the_application_audit_trail(): void
    {
        $this->capture(['rent_above_approved_reason' => 'Landlord asking price; tenant agreed']);

        $lease = Lease::withoutGlobalScopes()->where('rental_application_id', $this->application->id)->firstOrFail();
        $event = LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_RENT_ABOVE_APPROVED_CONFIRMED)->firstOrFail();
        self::assertStringContainsString('R11,400.00', $event->description);
        self::assertStringContainsString('R10,000.00', $event->description);
        self::assertSame('Landlord asking price; tenant agreed', $event->metadata['reason']);
        self::assertSame($this->user->id, $event->actor_user_id);

        self::assertTrue(RentalApplicationAuditLog::where('rental_application_id', $this->application->id)
            ->where('event_type', 'rent_above_approved_confirmed')->exists());
    }

    public function test_block_mode_refuses_even_with_a_reason(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['rent_above_approved_mode' => 'block']);

        $this->capture(['rent_above_approved_reason' => 'please'])->assertSessionHasErrors('rental_amount');

        self::assertSame(0, $this->application_leases());
    }

    public function test_rent_at_or_below_the_approved_amount_needs_no_reason_and_logs_nothing(): void
    {
        $this->capture(['rental_amount' => 10000, 'deposit_amount' => 10000])->assertSessionDoesntHaveErrors();

        $lease = Lease::withoutGlobalScopes()->where('rental_application_id', $this->application->id)->firstOrFail();
        self::assertSame(0, LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_RENT_ABOVE_APPROVED_CONFIRMED)->count());
    }

    public function test_an_application_with_no_approved_amount_is_never_warned(): void
    {
        $this->application->update(['approved_rental_amount' => null, 'approved_deposit_amount' => null]);

        $this->capture()->assertSessionDoesntHaveErrors();

        self::assertSame(1, $this->application_leases());
    }

    public function test_the_setting_defaults_to_warn_saves_and_rejects_junk(): void
    {
        self::assertSame('warn', RentalApplicationQualifyingSetting::rentAboveApprovedModeFor($this->agency->id));

        $this->actingAs($this->user)->post(route('corex.settings.rental-applications.rent-above-approved-mode'), ['rent_above_approved_mode' => 'block']);
        self::assertSame('block', RentalApplicationQualifyingSetting::rentAboveApprovedModeFor($this->agency->id));

        $this->actingAs($this->user)->post(route('corex.settings.rental-applications.rent-above-approved-mode'), ['rent_above_approved_mode' => 'nonsense'])
            ->assertSessionHasErrors('rent_above_approved_mode');
        self::assertSame('block', RentalApplicationQualifyingSetting::rentAboveApprovedModeFor($this->agency->id));

        // A wizard step that does not render the control never wipes it.
        $this->actingAs($this->user)->post(route('corex.settings.rental-applications.rent-above-approved-mode'), []);
        self::assertSame('block', RentalApplicationQualifyingSetting::rentAboveApprovedModeFor($this->agency->id));
    }
}
