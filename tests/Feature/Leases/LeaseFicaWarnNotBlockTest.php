<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan's QA1 rentals test, 7 Oct 2026: FICA blocked the agent from continuing with the tenant where it should have
 * warned; his ruling (8 Oct, relayed by the conductor): the FICA gate lifts on SUBMITTED, same as sales.
 *
 * The lease half of the path — link the property → create the lease → activate it → set up portal access, for the
 * tenant AND the landlord — walked in every FICA state (the same state on both parties). None of these steps stops
 * on FICA; they WARN, with the link to request/complete it, until FICA has been submitted. The one place a lease
 * agreement genuinely stops on FICA is the tenant's/landlord's own signing page (sales' external signer gate) —
 * that is proven in tests/Feature/Docuperfect/SigningView/FicaGateLiftsOnSubmittedTest.php.
 */
final class LeaseFicaWarnNotBlockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $landlord;
    private RentalApplication $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cr-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Flat to let', 'status' => 'active', 'listing_type' => 'rental',
            'address' => '401 Margate Boulevard', 'suburb' => 'Margate', 'city' => 'Margate',
            'rental_amount' => 10000, 'deposit_amount' => 10000,
        ]);
        $this->tenant = $this->contact('Ayanda', 'Mtolo', 'ayanda@example.test');
        $this->landlord = $this->contact('Siyabonga', 'Simamane', 'siya@example.test');
        DB::table('contact_property')->insert([
            'contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'contact_id' => $this->tenant->id, 'created_by_user_id' => $this->agent->id,
            'status' => 'approved', 'approved_rental_amount' => 10000, 'approved_deposit_amount' => 10000,
            'current_generation' => 1, 'token' => 'tok-' . uniqid(),
        ]);
    }

    private function contact(string $first, string $last, string $email): Contact
    {
        $c = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '0821234567',
        ]);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $this->agency->id]);

        return $c->fresh();
    }

    /** Same FICA state on both parties. */
    private function fica(?string $status): void
    {
        if ($status === null) {
            return;
        }
        foreach ([$this->tenant, $this->landlord] as $contact) {
            FicaSubmission::create([
                'contact_id' => $contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
                'requested_by' => $this->agent->id, 'status' => $status,
                'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
                'verified_at' => $status === 'approved' ? now() : null,
            ]);
        }
    }

    /** @return array<string, array{0:?string,1:bool}> [fica status, gate open] */
    public static function ficaStates(): array
    {
        return [
            'no FICA at all' => [null, false],
            'requested, not submitted (draft)' => ['draft', false],
            'sent back for corrections' => ['corrections_requested', false],
            'submitted' => ['submitted', true],
            'under review' => ['under_review', true],
            'agent approved' => ['agent_approved', true],
            'referred to the compliance officer' => ['referred_to_co', true],
            'approved' => ['approved', true],
        ];
    }

    /** @dataProvider ficaStates */
    public function test_link_create_activate_and_portal_access_all_go_through_in_every_fica_state(?string $status, bool $open): void
    {
        $this->fica($status);

        // 1. Link the property to the approved tenant.
        $this->actingAs($this->agent)->post(
            route('corex.rental-applications.link-tenant-property', $this->application),
            ['property_id' => $this->property->id],
        )->assertSessionDoesntHaveErrors();

        // 2. Create the lease (capture screen, lease only).
        $this->actingAs($this->agent)->post(route('corex.leases.store'), [
            'intent' => 'lease_only',
            'property_id' => $this->property->id,
            'rental_application_id' => $this->application->id,
            'tenant_contact_ids' => [$this->tenant->id],
            'rental_amount' => 10000,
            'deposit_amount' => 10000,
            'start_date' => '2026-11-01',
            'end_date' => '2027-10-31',
        ])->assertSessionDoesntHaveErrors();

        $lease = Lease::withoutGlobalScopes()->where('rental_application_id', $this->application->id)->first();
        $this->assertNotNull($lease, 'the lease is created whatever the FICA state');

        // 3. Activate it.
        $this->actingAs($this->agent)->post(route('corex.leases.activate', $lease))->assertSessionDoesntHaveErrors();
        $this->assertSame(Lease::STATUS_ACTIVE, $lease->fresh()->status, 'activation is never held up by FICA');

        // 4. Portal access — tenant AND landlord.
        foreach ([$this->tenant, $this->landlord] as $contact) {
            $this->actingAs($this->agent)
                ->post(route('corex.leases.portal-access.setup', [$lease, $contact->id]))
                ->assertSessionDoesntHaveErrors();
            $this->assertNotNull(ClientUser::where('email', $contact->email)->first(), "portal login for {$contact->first_name} is created whatever the FICA state");
        }
    }

    /** @dataProvider ficaStates */
    public function test_the_lease_screen_warns_for_a_party_who_has_not_submitted_and_says_nothing_once_they_have(?string $status, bool $open): void
    {
        $this->fica($status);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()->getContent();

        if ($open) {
            $this->assertStringNotContainsString('data-qa="lease-fica-warning"', $html);
        } else {
            // One warning each for the tenant and the landlord, each with the link to request/complete FICA.
            $this->assertSame(2, substr_count($html, 'data-qa="lease-fica-warning"'));
            $this->assertSame(2, substr_count($html, 'data-qa="lease-fica-link"'));
            $this->assertStringContainsString('You can carry on', $html);
        }
    }

    public function test_an_over_lease_shows_no_fica_warning(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_CANCELLED, 'rental_amount' => 10000, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id, 'cancelled_at' => now(), 'cancel_reason' => 'Testing',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $this->actingAs($this->agent)->get(route('corex.leases.show', $lease))->assertOk()
            ->assertDontSee('data-qa="lease-fica-warning"', false);
    }

    /** @dataProvider ficaStates */
    public function test_the_who_signs_check_reports_each_partys_fica_without_adding_it_to_what_blocks_preparing(?string $status, bool $open): void
    {
        $this->fica($status);

        $json = $this->actingAs($this->agent)->getJson(route('corex.leases.party-check', [
            'property_id' => $this->property->id,
            'tenant_ids' => [$this->tenant->id],
        ]))->assertOk()->json();

        foreach (['tenants', 'landlords'] as $side) {
            $this->assertCount(1, $json[$side]);
            $party = $json[$side][0];
            $this->assertSame($open, $party['fica']['open']);
            $this->assertSame($open, $party['fica']['warning'] === null);
            $this->assertNotContains('FICA', array_map('strtoupper', $party['needs']), 'FICA never joins the list of what blocks preparing');
        }
    }
}
