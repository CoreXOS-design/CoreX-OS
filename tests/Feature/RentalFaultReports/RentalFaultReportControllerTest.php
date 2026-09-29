<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultReports;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 1 verification for .ai/specs/rental-work-orders.md §3.2a/§0c/§3a —
 * reporting captures who/how/who-typed-it-in, editing is guarded to before
 * the record moves past 'reported', and photos reuse the same
 * client_idempotency_key dedup pattern as rental_inspection_photos.
 */
final class RentalFaultReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RFR Controller Agency', 'slug' => 'rfr-controller-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    public function test_reporting_a_fault_records_who_how_and_who_captured_it(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);

        $response = $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT,
            'reported_by_contact_id' => $contact->id,
            'reported_channel' => RentalFaultReport::CHANNEL_WHATSAPP,
            'title' => 'Geyser burst',
            'description' => 'Water everywhere in the ceiling.',
        ]);

        $faultReport = RentalFaultReport::firstOrFail();
        $response->assertRedirect(route('corex.rental-fault-reports.show', $faultReport));

        $this->assertSame(RentalFaultReport::REPORTED_BY_TENANT, $faultReport->reported_by_type);
        $this->assertSame($contact->id, $faultReport->reported_by_contact_id);
        $this->assertSame(RentalFaultReport::CHANNEL_WHATSAPP, $faultReport->reported_channel);
        // §3.2a — every row is agent-captured today; captured_by_user_id
        // names the agent, distinct from reported_by_contact_id (the tenant).
        $this->assertSame($this->admin->id, $faultReport->captured_by_user_id);
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $faultReport->status);
        $this->assertSame(RentalFaultReport::APPROVAL_NOT_REQUIRED, $faultReport->owner_approval_status);
    }

    public function test_reporting_with_a_lease_records_the_tenancy_it_happened_during(): void
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id,
            'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Cracked tile',
            'description' => 'Noticed during a routine visit.',
        ])->assertRedirect();

        $faultReport = RentalFaultReport::firstOrFail();
        $this->assertSame($lease->id, $faultReport->lease_id);
        $this->assertSame($this->admin->id, $faultReport->reported_by_user_id);
    }

    public function test_reporting_without_a_lease_is_allowed_for_the_vacancy_case(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'title' => 'Fence damage',
            'description' => 'Noticed between tenancies.',
        ])->assertRedirect();

        $faultReport = RentalFaultReport::firstOrFail();
        $this->assertNull($faultReport->lease_id);
    }

    public function test_editing_is_blocked_once_the_report_has_moved_past_reported(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Original title', 'description' => 'x', 'status' => RentalFaultReport::STATUS_RESOLVED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->put(route('corex.rental-fault-reports.update', $faultReport), [
            'title' => 'Changed title', 'description' => 'changed',
        ])->assertStatus(409);

        $this->assertSame('Original title', $faultReport->fresh()->title);
    }

    public function test_editing_while_still_reported_updates_the_reportable_facts(): void
    {
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Original title', 'description' => 'x', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->put(route('corex.rental-fault-reports.update', $faultReport), [
            'title' => 'Corrected title', 'description' => 'Corrected description.',
        ])->assertRedirect(route('corex.rental-fault-reports.show', $faultReport));

        $this->assertSame('Corrected title', $faultReport->fresh()->title);
    }

    public function test_uploading_a_photo_attaches_it_to_the_fault_report(): void
    {
        Storage::fake('public');
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Fault', 'description' => 'x', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.photos.store', $faultReport), [
            'photo' => UploadedFile::fake()->image('damp.jpg'),
        ])->assertCreated();

        $this->assertSame(1, $faultReport->photos()->count());
    }

    public function test_a_retried_photo_upload_with_the_same_key_returns_the_existing_record(): void
    {
        Storage::fake('public');
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON, 'captured_by_user_id' => $this->admin->id,
            'title' => 'Fault', 'description' => 'x', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.photos.store', $faultReport), [
            'photo' => UploadedFile::fake()->image('damp.jpg'), 'client_idempotency_key' => $key,
        ])->assertCreated();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.photos.store', $faultReport), [
            'photo' => UploadedFile::fake()->image('damp-retry.jpg'), 'client_idempotency_key' => $key,
        ])->assertOk();

        $this->assertSame(1, $faultReport->photos()->count());
    }
}
