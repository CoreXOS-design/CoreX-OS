<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Services\WebTemplateDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rental re-walk, 14 Oct 2026: "Lessor address" and "Erf no" came up empty on the first agreement AND on the renewal agreement.
 * The pre-fill reads the landlord CONTACT's address and the PROPERTY's erf; the erf is kept in two columns (`erf_number`, and the form's
 * `property_number` box) and only the second was read. Both the first agreement and the renewal build their flow from the same
 * snapshot + recipient rows, so one test per path proves both.
 */
final class AgreementErfAndLessorPrefillTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $landlord;
    private Lease $lease;

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
            'title' => '2 Bed Flat Sea Point', 'status' => 'to_let', 'listing_type' => 'rental',
            'address' => 'Unit 7, Sea Court, 21 Marine Parade', 'street_number' => '21', 'street_name' => 'Marine Parade',
            'complex_name' => 'Sea Court', 'unit_number' => '7', 'suburb' => 'Sea Point', 'city' => 'Cape Town',
            'rental_amount' => 14500, 'deposit_amount' => 29000,
        ]);
        $this->tenant = $this->contact('Thandi', 'Ngcobo', 'thandi@example.test');
        $this->landlord = $this->contact('Pieter', 'Botha', 'pieter@example.test');
        DB::table('contact_property')->insert([
            'contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 14500, 'deposit_amount' => 29000,
            'start_date' => '2026-11-01', 'end_date' => '2027-10-31', 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
    }

    private function contact(string $first, string $last, string $email): Contact
    {
        $c = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '0821234567']);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $this->agency->id]);

        return $c->fresh();
    }

    private function resolved(array $stepData): array
    {
        return app(WebTemplateDataService::class)->resolve(0, $stepData, $this->agent);
    }

    // ── the erf ─────────────────────────────────────────────────────────────────────────

    public function test_the_erf_is_read_from_either_column_and_the_title_deed_figure_wins(): void
    {
        $launcher = app(LeaseSigningLauncher::class);

        self::assertSame('', $launcher->propertySnapshot($this->property->fresh())['erf'], 'nothing recorded -> blank (nothing to invent)');

        DB::table('properties')->where('id', $this->property->id)->update(['property_number' => '5678']);
        self::assertSame('5678', $launcher->propertySnapshot($this->property->fresh())['erf'], 'the form box still works');

        DB::table('properties')->where('id', $this->property->id)->update(['property_number' => null, 'erf_number' => '1234']);
        $snapshot = $launcher->propertySnapshot($this->property->fresh());
        self::assertSame('1234', $snapshot['erf'], 'an erf recorded only in erf_number used to print empty');
        self::assertSame('1234', $snapshot['erf_no']);
        self::assertSame('1234', $snapshot['erf_number']);

        DB::table('properties')->where('id', $this->property->id)->update(['property_number' => '5678']);
        self::assertSame('1234', $launcher->propertySnapshot($this->property->fresh())['erf'], 'the title-deed figure wins over the form box');
    }

    public function test_the_first_agreements_flow_data_fills_erf_no_and_lessor_address(): void
    {
        DB::table('properties')->where('id', $this->property->id)->update(['erf_number' => '1234']);
        DB::table('contacts')->where('id', $this->landlord->id)->update(['address' => '14 Vine Lane, Paarl']);

        $stepData = app(LeaseSigningLauncher::class)->buildStepData($this->lease->fresh(), [], $this->agent);
        $out = $this->resolved($stepData);

        self::assertSame('1234', $out['erf_no']);
        self::assertSame('1234', $out['property_erf_number']);
        self::assertSame('14 Vine Lane, Paarl', $out['lessor_address']);
        self::assertSame('14 Vine Lane, Paarl', $out['lessor1_address']);
    }

    public function test_the_renewals_flow_carries_the_same_erf_and_lessor_address(): void
    {
        DB::table('properties')->where('id', $this->property->id)->update(['erf_number' => '1234']);
        DB::table('contacts')->where('id', $this->landlord->id)->update(['address' => '14 Vine Lane, Paarl']);
        $template = new Template(['name' => 'Cape residential lease', 'render_type' => 'pdf', 'is_esign' => true]);
        $template->agency_id = $this->agency->id;
        $template->save();

        $renewal = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 15500, 'deposit_amount' => 29000,
            'start_date' => '2027-11-01', 'end_date' => '2028-10-31', 'source' => 'manual', 'created_by_user_id' => $this->agent->id,
            'previous_lease_id' => $this->lease->id,
        ]);
        LeaseTenant::create(['lease_id' => $renewal->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);

        $flow = app(LeaseSigningLauncher::class)->buildFlow($renewal->fresh(), $template->id, $this->agent);
        $out = $this->resolved($flow->step_data);

        self::assertSame('1234', $out['erf_no'], 'the renewal pre-fills the erf too');
        self::assertSame('14 Vine Lane, Paarl', $out['lessor_address'], 'and the landlord contact\'s address');
        self::assertSame('21 Marine Parade, Sea Point', $out['street_address']);
    }

    public function test_a_landlord_with_no_address_on_file_stays_blank_rather_than_borrowing_one(): void
    {
        $stepData = app(LeaseSigningLauncher::class)->buildStepData($this->lease->fresh(), [], $this->agent);
        $out = $this->resolved($stepData);

        self::assertSame('', $out['lessor_address']);
        self::assertSame('', $out['erf_no']);
    }
}
