<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultRouting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultRoutingProfile;
use App\Models\RentalFaultRoutingRule;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Rentals\RentalFaultReportService;
use App\Services\Rentals\RentalFaultRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md §13 — Slice 3: fault routing
 * profiles + owner approval. Johan's ruling: "don't build one way where
 * there are options; build the options and let the agency set it up; it
 * can vary PER PROPERTY."
 */
final class RentalFaultRoutingServiceTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'RFRouting Agency', 'slug' => 'rfrouting-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function faultType(string $urgency, ?string $category = 'Plumbing'): RentalFaultType
    {
        return RentalFaultType::create([
            'agency_id' => $this->agency->id, 'name' => 'Test fault', 'category' => $category,
            'urgency' => $urgency, 'sort_order' => 1, 'created_by_user_id' => $this->admin->id,
        ]);
    }

    private function faultReport(?RentalFaultType $faultType = null): RentalFaultReport
    {
        return app(RentalFaultReportService::class)->report($this->property, [
            'rental_fault_type_id' => $faultType?->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT,
            'reported_channel' => RentalFaultReport::CHANNEL_IN_PERSON,
            'captured_by_user_id' => $this->admin->id,
            'title' => 'Test', 'description' => 'Test.', 'created_by_user_id' => $this->admin->id,
        ]);
    }

    // ── §13.1 — checked before speccing anything ─────────────────────────

    public function test_no_complex_grouping_entity_exists_confirming_the_investigation(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('complexes'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('properties', 'complex_id'));
    }

    // ── §13.2 — a seeded default profile always exists ────────────────────

    public function test_seeding_creates_a_default_profile_matching_todays_behaviour(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);

        $profile = RentalFaultRoutingProfile::where('agency_id', $this->agency->id)->whereNull('property_id')->firstOrFail();
        $this->assertSame('owner_first', $profile->emergency_route);
        $this->assertSame('agent_review', $profile->non_emergency_route);
    }

    public function test_seeding_is_idempotent(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);

        $this->assertSame(1, RentalFaultRoutingProfile::where('agency_id', $this->agency->id)->whereNull('property_id')->count());
    }

    // ── §13.3 — resolution: no fault type picked → agent_review, unresolved ──

    public function test_a_fault_report_with_no_fault_type_falls_back_to_agent_review(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);
        $report = $this->faultReport();

        $decision = app(RentalFaultRoutingService::class)->resolve($report);

        $this->assertSame('agent_review', $decision->route);
        $this->assertTrue($decision->isDefault());
    }

    // ── §13.3 — emergency/non-emergency route off the profile defaults ───

    public function test_emergency_fault_routes_to_owner_first_by_default(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);
        $report = $this->faultReport($this->faultType('emergency'));

        $decision = app(RentalFaultRoutingService::class)->resolve($report);

        $this->assertSame('owner_first', $decision->route);
        $this->assertFalse($decision->isDefault());
    }

    public function test_non_emergency_fault_routes_to_agent_review_by_default(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);
        $report = $this->faultReport($this->faultType('urgent'));

        $decision = app(RentalFaultRoutingService::class)->resolve($report);

        $this->assertSame('agent_review', $decision->route);
        $this->assertTrue($decision->isDefault());
    }

    // ── §13.4 — caretaker resolution ──────────────────────────────────────

    public function test_property_caretaker_contact_resolves_by_pivot_role(): void
    {
        $contact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Care', 'last_name' => 'Taker']);
        $this->property->contacts()->attach($contact->id, ['role' => 'caretaker']);

        $this->assertSame($contact->id, $this->property->fresh()->caretakerContact()->id);
    }

    public function test_caretaker_route_falls_back_to_agent_review_when_no_caretaker_linked(): void
    {
        RentalFaultRoutingProfile::query()->create([
            'agency_id' => $this->agency->id, 'property_id' => null,
            'emergency_route' => 'caretaker', 'non_emergency_route' => 'agent_review',
        ]);
        $report = $this->faultReport($this->faultType('emergency'));

        $decision = app(RentalFaultRoutingService::class)->resolve($report);

        $this->assertSame('agent_review', $decision->route);
    }

    public function test_caretaker_route_resolves_when_a_caretaker_is_linked(): void
    {
        RentalFaultRoutingProfile::query()->create([
            'agency_id' => $this->agency->id, 'property_id' => null,
            'emergency_route' => 'caretaker', 'emergency_caretaker_spend_limit' => 300, 'non_emergency_route' => 'agent_review',
        ]);
        $contact = Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Care', 'last_name' => 'Taker']);
        $this->property->contacts()->attach($contact->id, ['role' => 'caretaker']);
        $report = $this->faultReport($this->faultType('emergency'));

        $decision = app(RentalFaultRoutingService::class)->resolve($report->fresh());

        $this->assertSame('caretaker', $decision->route);
        $this->assertSame(300.0, $decision->spendLimit);
        $this->assertSame($contact->id, $decision->caretaker->id);
    }

    // ── §13.2/§13.3 — per-property override beats the agency default ──────

    public function test_a_property_override_profile_beats_the_agency_default(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id); // agency default: agent_review/agent_review
        RentalFaultRoutingProfile::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'emergency_route' => 'owner_first', 'emergency_owner_first_spend_limit' => 150,
            'non_emergency_route' => 'agent_review',
        ]);
        $report = $this->faultReport($this->faultType('emergency'));

        $decision = app(RentalFaultRoutingService::class)->resolve($report);

        $this->assertSame('owner_first', $decision->route);
        $this->assertSame(150.0, $decision->spendLimit);
        $this->assertTrue($decision->isPropertyOverride);
    }

    // ── §13.2/§13.3 — category/urgency rules, most-specific-wins ──────────

    public function test_a_category_specific_rule_overrides_the_profile_default(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);
        $profile = RentalFaultRoutingProfile::where('agency_id', $this->agency->id)->whereNull('property_id')->firstOrFail();
        $supplier = AgencyServiceProvider::create(['agency_id' => $this->agency->id, 'name' => 'Acme Plumbing', 'is_active' => true, 'created_by_id' => $this->admin->id]);
        RentalFaultRoutingRule::create([
            'agency_id' => $this->agency->id, 'rental_fault_routing_profile_id' => $profile->id,
            'category' => 'Plumbing', 'urgency' => null, 'route' => 'supplier',
            'agency_service_provider_id' => $supplier->id, 'spend_limit' => 750, 'sort_order' => 1, 'is_active' => true,
        ]);
        $report = $this->faultReport($this->faultType('urgent', 'Plumbing'));

        $decision = app(RentalFaultRoutingService::class)->resolve($report);

        $this->assertSame('supplier', $decision->route);
        $this->assertSame(750.0, $decision->spendLimit);
        $this->assertSame($supplier->id, $decision->supplier->id);
    }

    public function test_a_category_and_urgency_rule_beats_a_category_only_rule(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);
        $profile = RentalFaultRoutingProfile::where('agency_id', $this->agency->id)->whereNull('property_id')->firstOrFail();
        $supplierA = AgencyServiceProvider::create(['agency_id' => $this->agency->id, 'name' => 'Supplier A', 'is_active' => true, 'created_by_id' => $this->admin->id]);
        $supplierB = AgencyServiceProvider::create(['agency_id' => $this->agency->id, 'name' => 'Supplier B', 'is_active' => true, 'created_by_id' => $this->admin->id]);
        RentalFaultRoutingRule::create([
            'agency_id' => $this->agency->id, 'rental_fault_routing_profile_id' => $profile->id,
            'category' => 'Plumbing', 'urgency' => null, 'route' => 'supplier',
            'agency_service_provider_id' => $supplierA->id, 'spend_limit' => 500, 'sort_order' => 1, 'is_active' => true,
        ]);
        RentalFaultRoutingRule::create([
            'agency_id' => $this->agency->id, 'rental_fault_routing_profile_id' => $profile->id,
            'category' => 'Plumbing', 'urgency' => 'emergency', 'route' => 'supplier',
            'agency_service_provider_id' => $supplierB->id, 'spend_limit' => 1000, 'sort_order' => 2, 'is_active' => true,
        ]);
        $report = $this->faultReport($this->faultType('emergency', 'Plumbing'));

        $decision = app(RentalFaultRoutingService::class)->resolve($report);

        $this->assertSame($supplierB->id, $decision->supplier->id, 'the more specific category+urgency rule must win');
    }

    // ── §13.5 — every automatic routing decision is logged ────────────────

    public function test_a_routed_decision_is_logged_on_the_evidence_log_with_the_rule_that_fired(): void
    {
        RentalFaultRoutingProfile::create([
            'agency_id' => $this->agency->id, 'property_id' => null,
            'emergency_route' => 'owner_first', 'emergency_owner_first_spend_limit' => 200, 'non_emergency_route' => 'agent_review',
        ]);

        $report = $this->faultReport($this->faultType('emergency'));

        $this->assertDatabaseHas('rental_fault_report_updates', [
            'rental_fault_report_id' => $report->id, 'update_type' => 'routing_decision',
        ]);
        $history = $report->fresh()->history();
        $this->assertTrue($history->contains(fn ($e) => $e['action'] === 'Routing decision'));
    }

    public function test_the_plain_default_route_logs_nothing_because_no_decision_was_made(): void
    {
        RentalFaultRoutingProfile::seedDefaultFor($this->agency->id);
        $report = $this->faultReport($this->faultType('urgent'));

        $this->assertDatabaseMissing('rental_fault_report_updates', [
            'rental_fault_report_id' => $report->id, 'update_type' => 'routing_decision',
        ]);
    }

    // ── §13.3 — the routed spend limit persists on the report ─────────────

    public function test_the_resolved_route_and_spend_limit_persist_on_the_fault_report(): void
    {
        RentalFaultRoutingProfile::create([
            'agency_id' => $this->agency->id, 'property_id' => null,
            'emergency_route' => 'owner_first', 'emergency_owner_first_spend_limit' => 250, 'non_emergency_route' => 'agent_review',
        ]);

        $report = $this->faultReport($this->faultType('emergency'));

        $this->assertSame('owner_first', $report->fresh()->routed_via);
        $this->assertSame('250.00', $report->fresh()->routed_spend_limit);
    }

    // ── Owner approval — the routed spend limit gates exactly like the
    //    existing quote-gate mechanic, per Johan's own routing ruling ──────

    public function test_a_work_order_raised_from_a_routed_fault_report_gates_against_the_routes_own_limit(): void
    {
        // Property's OWN flat threshold is high (R5000) — if the gate used
        // that instead of the route's limit, this test's R400 quote would
        // wrongly need no approval.
        $this->property->update(['rental_no_approval_spend_threshold' => 5000]);

        RentalFaultRoutingProfile::create([
            'agency_id' => $this->agency->id, 'property_id' => null,
            'emergency_route' => 'owner_first', 'emergency_owner_first_spend_limit' => 100, 'non_emergency_route' => 'agent_review',
        ]);
        $report = $this->faultReport($this->faultType('emergency'));
        $this->assertSame('owner_first', $report->fresh()->routed_via);

        $workOrder = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_FAULT_REPORT, 'reported_fault_report_id' => $report->id,
            'title' => 'Test', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $supplier = AgencyServiceProvider::create(['agency_id' => $this->agency->id, 'name' => 'Acme', 'is_active' => true, 'created_by_id' => $this->admin->id]);
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 400, 'quote_date' => now()], $this->admin);

        $workOrder->selectQuote($quote, $this->admin);

        // R400 > the route's R100 limit → pending, even though it's well
        // under the property's own R5000 flat threshold.
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $workOrder->fresh()->owner_approval_status);
    }

    public function test_a_work_order_not_raised_from_a_routed_report_still_uses_the_property_threshold(): void
    {
        $this->property->update(['rental_no_approval_spend_threshold' => 500]);

        $workOrder = RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'title' => 'Test', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $supplier = AgencyServiceProvider::create(['agency_id' => $this->agency->id, 'name' => 'Acme', 'is_active' => true, 'created_by_id' => $this->admin->id]);
        $quote = $workOrder->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 400, 'quote_date' => now()], $this->admin);

        $workOrder->selectQuote($quote, $this->admin);

        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $workOrder->fresh()->owner_approval_status);
    }
}
