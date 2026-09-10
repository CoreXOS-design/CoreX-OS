<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 2 follow-up, 2026-09-10 — Johan, QA1: the linked property could be
 * changed or cleared at any point, including while an authoriser was
 * actively deciding against it (status under_assessment) and after the
 * outcome email had already gone out naming it. Reproduced directly on a
 * real, fully-approved application before this fix — nothing crashed, but
 * the record no longer matched what was actually approved against.
 *
 * Locks in the fix's real guarantees, driving the REAL route
 * (linkProperty()) end to end rather than asserting on
 * RentalApplicationQualifyingSetting::isPropertyLinkLockedFor() in
 * isolation — same discipline as RentalApplicationDocumentLockTest, this
 * is exactly the class of rule a later refactor could quietly undo with
 * no failing assertion to catch it.
 */
final class RentalApplicationPropertyLinkLockTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function property(string $title): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => $title, 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za', 'phone' => '0821234567',
        ]);
    }

    private function application(string $status, ?int $propertyId = null): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact()->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status, 'property_id' => $propertyId,
            'submitted_at' => in_array($status, ['returned', 'under_assessment', 'approved', 'declined', 'reopened'], true) ? now() : null,
        ]);
    }

    // ── Default (locked) behaviour ───────────────────────────────────────

    public function test_open_statuses_still_allow_changing_the_property(): void
    {
        $propertyA = $this->property('Flat A');
        $propertyB = $this->property('Flat B');

        foreach (['draft', 'sent', 'in_progress', 'returned', 'reopened'] as $status) {
            $application = $this->application($status, $propertyA->id);

            $response = $this->actingAs($this->agent)->post(
                route('corex.rental-applications.review.link-property', $application),
                ['property_id' => $propertyB->id],
            );

            $response->assertRedirect();
            $this->assertSame($propertyB->id, $application->fresh()->property_id, "status={$status} must still allow the change");
        }
    }

    public function test_locked_statuses_refuse_the_change_with_a_real_403(): void
    {
        $propertyA = $this->property('Flat A');
        $propertyB = $this->property('Flat B');

        foreach (['under_assessment', 'approved', 'declined', 'withdrawn'] as $status) {
            $application = $this->application($status, $propertyA->id);

            $response = $this->actingAs($this->agent)->post(
                route('corex.rental-applications.review.link-property', $application),
                ['property_id' => $propertyB->id],
            );

            $response->assertStatus(403);
            $this->assertSame($propertyA->id, $application->fresh()->property_id, "status={$status} must refuse the change server-side, not just hide a button");
        }
    }

    public function test_locked_statuses_also_refuse_clearing_the_property(): void
    {
        $property = $this->property('Flat A');
        $application = $this->application('approved', $property->id);

        $response = $this->actingAs($this->agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => null],
        );

        $response->assertStatus(403);
        $this->assertSame($property->id, $application->fresh()->property_id);
    }

    // ── Agency setting turns the lock off ────────────────────────────────

    public function test_turning_the_setting_off_restores_the_old_unrestricted_behaviour(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['lock_property_after_submission' => false],
        );

        $propertyA = $this->property('Flat A');
        $propertyB = $this->property('Flat B');
        $application = $this->application('approved', $propertyA->id);

        $response = $this->actingAs($this->agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $propertyB->id],
        );

        $response->assertRedirect();
        $this->assertSame($propertyB->id, $application->fresh()->property_id);
    }

    public function test_default_is_locked_when_the_agency_has_never_configured_this(): void
    {
        $this->assertNull(RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->first());
        $this->assertTrue(RentalApplicationQualifyingSetting::lockPropertyAfterSubmissionFor($this->agency->id));
    }

    // ── Audit trail ───────────────────────────────────────────────────────

    public function test_a_permitted_change_is_still_fully_audited(): void
    {
        $propertyA = $this->property('Flat A');
        $propertyB = $this->property('Flat B');
        $application = $this->application('sent', $propertyA->id);

        $this->actingAs($this->agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $propertyB->id],
        )->assertRedirect();

        $log = RentalApplicationAuditLog::where('rental_application_id', $application->id)
            ->where('event_category', 'property_link')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame('changed', $log->event_type);
        $this->assertSame($propertyA->id, (int) $log->old_values['property_id']);
        $this->assertSame($propertyB->id, (int) $log->new_values['property_id']);
        $this->assertSame($this->agent->id, $log->user_id);
    }

    public function test_a_refused_change_leaves_no_audit_trail_at_all(): void
    {
        $property = $this->property('Flat A');
        $application = $this->application('approved', $property->id);

        $before = RentalApplicationAuditLog::where('rental_application_id', $application->id)
            ->where('event_category', 'property_link')->count();

        $this->actingAs($this->agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $this->property('Flat B')->id],
        )->assertStatus(403);

        $after = RentalApplicationAuditLog::where('rental_application_id', $application->id)
            ->where('event_category', 'property_link')->count();

        $this->assertSame($before, $after, 'a refused request must never create a log entry — nothing happened, so nothing is logged');
    }
}
