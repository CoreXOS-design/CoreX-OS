<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalJobCard;
use App\Models\RentalNotice;
use App\Models\RentalWorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirmed bug (2026-10-05): /corex/leases/{id} 500'd whenever that lease's
 * property had been soft-deleted — Lease::property() was a plain belongsTo
 * (excluded trashed), so leases/show.blade.php's "Link landlord" fallback
 * passed a null Property into route(), which throws trying to resolve the
 * {property} route parameter.
 *
 * Fix (class, not instance — .ai/BUILD_STANDARD.md §6 / §4 "deleted-related-
 * record must render gracefully"): every BelongsTo relation on the rentals
 * models that points at Property/Lease/Contact/supplier now uses
 * ->withTrashed(), matching the pre-existing RentalWorkOrder::property()
 * precedent. Every view that renders one of those relations either shows an
 * "(archived)" marker or suppresses a link that would otherwise 404 under
 * default route-model binding on the trashed record.
 *
 * Every test below loads a list screen and a detail screen with a
 * soft-deleted property (or lease, or tenant contact) attached and asserts
 * 200 — never a 500, never a dead link reached by clicking through.
 */
final class SoftDeletedRelatedRecordRenderTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'Soft-Delete Render Agency', 'slug' => 'sdr-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
    }

    private function property(array $attrs = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '19 Windsor Avenue, Margate', 'status' => 'active', 'listing_type' => 'rental',
        ], $attrs));
    }

    private function lease(Property $property, array $attrs = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'property_id' => $property->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9500, 'start_date' => now()->subMonths(6)->toDateString(), 'source' => 'manual',
            'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function contact(string $first = 'Thandiwe', string $last = 'Tenant'): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '-' . uniqid() . '@example.test',
        ]);
    }

    // ── Leases — the confirmed regression ───────────────────────────────

    public function test_lease_hub_renders_when_its_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $lease = $this->lease($property);
        $property->delete();

        $response = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease->fresh()));

        $response->assertOk();
        $response->assertSee('(archived)', false);
        $response->assertDontSee('No landlord linked</span>' . PHP_EOL . '                        <a', false);
    }

    public function test_leases_index_renders_when_a_rows_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $this->lease($property);
        $property->delete();

        $this->actingAs($this->admin)->get(route('corex.leases.index'))->assertOk();
    }

    public function test_lease_hub_renders_when_a_soft_deleted_tenant_contact_is_attached(): void
    {
        $property = $this->property();
        $lease = $this->lease($property);
        $tenant = $this->contact();
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $tenant->delete();

        $this->actingAs($this->admin)->get(route('corex.leases.show', $lease->fresh()))->assertOk();
    }

    public function test_lease_hub_renders_when_its_previous_lease_is_soft_deleted(): void
    {
        $property = $this->property();
        $previous = $this->lease($property, ['status' => Lease::STATUS_EXPIRED]);
        $current = $this->lease($property, ['previous_lease_id' => $previous->id, 'start_date' => now()->toDateString()]);
        $previous->delete();

        $response = $this->actingAs($this->admin)->get(route('corex.leases.show', $current->fresh()));

        $response->assertOk();
        $response->assertSee('(archived)', false);
    }

    // ── Rental fault reports ─────────────────────────────────────────────

    public function test_rental_fault_report_show_and_index_render_when_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $faultReport = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'reported_by_type' => 'agent', 'reported_by_user_id' => $this->admin->id, 'reported_channel' => 'agent_portal',
            'captured_by_user_id' => $this->admin->id,
            'title' => 'Leaky geyser', 'description' => 'Dripping in the roof.',
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_at' => now(),
        ]);
        $property->delete();

        $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.index'))->assertOk();
        $response = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $faultReport->fresh()));
        $response->assertOk();
        $response->assertSee('(archived)', false);
    }

    // ── Rental work orders ───────────────────────────────────────────────

    public function test_rental_work_order_show_and_index_render_when_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $workOrder = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'reported_by_type' => 'agent', 'reported_by_user_id' => $this->admin->id,
            'title' => 'Fix geyser', 'description' => 'Replace element.',
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_at' => now(),
            'created_by_user_id' => $this->admin->id,
        ]);
        $property->delete();

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.index'))->assertOk();
        $response = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $workOrder->fresh()));
        $response->assertOk();
        $response->assertSee('(archived)', false);
    }

    // ── Rental job cards ──────────────────────────────────────────────────

    public function test_rental_job_card_show_and_index_render_when_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $workOrder = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'reported_by_type' => 'agent', 'reported_by_user_id' => $this->admin->id,
            'title' => 'Mow the lawn', 'description' => 'Overgrown.',
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_at' => now(),
            'created_by_user_id' => $this->admin->id,
        ]);
        $jobCard = RentalJobCard::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'rental_work_order_id' => $workOrder->id,
            'title' => 'Mow the lawn', 'status' => 'pending',
        ]);
        $property->delete();

        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index'))->assertOk();
        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $jobCard->fresh()));
        $response->assertOk();
        $response->assertSee('(archived)', false);
    }

    // ── Rental inspections ────────────────────────────────────────────────

    public function test_rental_inspection_show_and_index_render_when_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $lease = $this->lease($property);
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id,
            'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $this->admin->id,
        ]);
        $property->delete();

        $this->actingAs($this->admin)->get(route('corex.rental-inspections.index'))->assertOk();
        $response = $this->actingAs($this->admin)->get(route('corex.rental-inspections.show', $inspection->fresh()));
        $response->assertOk();
        $response->assertSee('(archived)', false);
    }

    // ── Rental notices — the second confirmed crash site ────────────────
    // (rental-notices/show.blade.php built an unguarded route() straight
    // off $notice->lease with zero null check.)

    public function test_rental_notice_show_and_index_render_when_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $lease = $this->lease($property);
        $notice = RentalNotice::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id,
            'notice_type' => 'rent_increase', 'sent_to_tenant' => true, 'sent_to_landlord' => false,
            'sent_at' => now(), 'sent_by_user_id' => $this->admin->id,
        ]);
        $property->delete();

        $this->actingAs($this->admin)->get(route('corex.rental-notices.index'))->assertOk();
        $response = $this->actingAs($this->admin)->get(route('corex.rental-notices.show', $notice->fresh()));
        $response->assertOk();
        $response->assertSee('(archived)', false);
    }

    public function test_rental_notice_show_renders_when_its_own_lease_is_soft_deleted(): void
    {
        $property = $this->property();
        $lease = $this->lease($property);
        $notice = RentalNotice::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id,
            'notice_type' => 'rent_increase', 'sent_to_tenant' => true, 'sent_to_landlord' => false,
            'sent_at' => now(), 'sent_by_user_id' => $this->admin->id,
        ]);
        $lease->delete();

        $response = $this->actingAs($this->admin)->get(route('corex.rental-notices.show', $notice->fresh()));

        $response->assertOk();
        $response->assertDontSee('Back to Lease Hub');
    }

    // ── Rental applications ───────────────────────────────────────────────

    public function test_rental_application_show_and_index_render_when_property_and_contact_are_soft_deleted(): void
    {
        $property = $this->property();
        $applicant = $this->contact('Applicant', 'Soft-Deleted');
        $application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'contact_id' => $applicant->id, 'property_id' => $property->id,
            'created_by_user_id' => $this->admin->id, 'status' => 'sent',
        ]);
        $property->delete();
        $applicant->delete();

        $this->actingAs($this->admin)->get(route('corex.rental-applications.index'))->assertOk();
        $response = $this->actingAs($this->admin)->get(route('corex.rental-applications.show', $application->fresh()));
        $response->assertOk();
    }

    // ── Rentals Command Centre ────────────────────────────────────────────

    public function test_command_centre_renders_when_a_queue_items_property_is_soft_deleted(): void
    {
        $property = $this->property();
        $lease = $this->lease($property, ['notice_date' => now()->toDateString(), 'notice_given_by' => Lease::NOTICE_BY_TENANT, 'move_out_date' => now()->addDays(10)->toDateString()]);
        $property->delete();

        $this->actingAs($this->admin)->get(route('corex.rentals.command-centre.index'))->assertOk();
    }
}
