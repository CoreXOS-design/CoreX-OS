<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 2 (business logic) verification for .ai/specs/rental-inspections.md.
 * Deadline calculation, the fault-window decision, out-inspection signature
 * capture, and the cross-tenancy carry-forward query — the pieces this
 * stage adds on top of Stage 1's pure data model. Still no controller/route
 * (that's Stage 3) — every call here goes straight at the model layer.
 */
final class RentalInspectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'RI Workflow Agency', 'slug' => 'ri-workflow-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id,
            'title' => 'RI Workflow Property',
            'status' => 'active',
            'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 12000,
            'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeItem(string $label = 'Bedroom 1'): RentalInspectionItem
    {
        return RentalInspectionItem::create([
            'agency_id' => $this->agency->id,
            'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE,
            'label' => $label,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(string $type, ?Lease $lease = null): RentalInspection
    {
        return RentalInspection::create([
            'agency_id' => $this->agency->id,
            'lease_id' => ($lease ?? $this->lease)->id,
            'type' => $type,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeObservation(RentalInspection $inspection, RentalInspectionItem $item, string $condition, array $extra = []): RentalInspectionObservation
    {
        return RentalInspectionObservation::create(array_merge([
            'agency_id' => $this->agency->id,
            'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id,
            'observed_by_user_id' => $this->agent->id,
            'condition' => $condition,
            'notes' => $condition !== RentalInspectionObservation::CONDITION_GOOD ? 'Test note' : null,
            'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ], $extra));
    }

    public function test_completing_an_in_inspection_sets_the_fault_report_deadline_from_settings(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->makeObservation($inspection, $this->makeItem(), RentalInspectionObservation::CONDITION_GOOD);

        $inspection->markCompleted();

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->status);
        $this->assertNotNull($inspection->completed_at);
        $this->assertNotNull($inspection->fault_report_deadline_at);
        $this->assertEqualsWithDelta(
            $inspection->completed_at->copy()->addDays(7)->timestamp,
            $inspection->fault_report_deadline_at->timestamp,
            2,
            'default fault-report window is 7 days when no settings row exists',
        );
    }

    public function test_completing_an_in_inspection_honours_a_custom_fault_report_window(): void
    {
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'fault_report_window_days' => 14]);
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        $inspection->markCompleted();

        $this->assertEqualsWithDelta(
            $inspection->completed_at->copy()->addDays(14)->timestamp,
            $inspection->fault_report_deadline_at->timestamp,
            2,
        );
    }

    public function test_cannot_complete_an_inspection_with_an_unresolved_discrepancy(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $obs2 = $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED);
        \App\Models\RentalInspectionDiscrepancy::detectFor($obs2);

        $this->expectException(\LogicException::class);
        $inspection->markCompleted();
    }

    public function test_starting_the_signing_window_is_only_valid_for_an_out_inspection(): void
    {
        $inIns = $this->makeInspection(RentalInspection::TYPE_IN);

        $this->expectException(\LogicException::class);
        $inIns->startAwaitingSignature();
    }

    public function test_starting_the_signing_window_sets_the_deadline_from_settings(): void
    {
        RentalInspectionSetting::create(['agency_id' => $this->agency->id, 'out_inspection_signing_window_days' => 10]);
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $outIns->startAwaitingSignature();

        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $outIns->status);
        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $outIns->signing_deadline_at->timestamp, 2);
    }

    public function test_cannot_complete_an_out_inspection_with_no_signature_on_record(): void
    {
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->expectException(\LogicException::class);
        $outIns->markCompleted();
    }

    public function test_completing_an_out_inspection_succeeds_once_a_signature_exists(): void
    {
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);
        RentalInspectionSignature::capture($outIns, RentalInspectionSignature::SIGNER_TENANT, [
            'signer_contact_id' => null,
            'signature_path' => 'signatures/test.png',
        ]);

        $outIns->markCompleted();

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $outIns->status);
        $this->assertNull($outIns->fault_report_deadline_at, 'the fault-report window belongs to the in-inspection only');
    }

    public function test_agent_on_behalf_signature_requires_the_exact_refusal_phrase(): void
    {
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($outIns, RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF, [
            'signed_by_user_id' => $this->agent->id,
            'refused_note' => 'Tenant did not respond after several calls.',
        ]);
    }

    public function test_agent_on_behalf_signature_succeeds_with_the_exact_refusal_phrase(): void
    {
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $signature = RentalInspectionSignature::capture($outIns, RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF, [
            'signed_by_user_id' => $this->agent->id,
            'refused_note' => 'Called three times over the window — tenant refused to sign out inspection.',
        ]);

        $this->assertNotNull($signature->id);
        $this->assertSame(RentalInspectionSignature::SIGNER_AGENT_ON_BEHALF, $signature->signer_role);
    }

    public function test_window_decision_can_only_be_recorded_for_a_report_outside_the_window(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $observation = $this->makeObservation($inspection, $this->makeItem(), RentalInspectionObservation::CONDITION_DAMAGED, [
            'reported_outside_window' => false,
        ]);

        $this->expectException(\LogicException::class);
        $observation->recordWindowDecision(RentalInspectionObservation::WINDOW_DECISION_ACCEPTED, $this->agent);
    }

    public function test_window_decision_is_recorded_once_and_cannot_be_redecided(): void
    {
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $observation = $this->makeObservation($inspection, $this->makeItem(), RentalInspectionObservation::CONDITION_DAMAGED, [
            'source' => RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT,
            'reported_outside_window' => true,
        ]);

        $observation->recordWindowDecision(RentalInspectionObservation::WINDOW_DECISION_ACCEPTED, $this->agent, 'Fair enough, only two days late.');

        $observation->refresh();
        $this->assertSame(RentalInspectionObservation::WINDOW_DECISION_ACCEPTED, $observation->window_decision);
        $this->assertSame($this->agent->id, $observation->window_decision_by_user_id);
        $this->assertNotNull($observation->window_decision_at);

        $this->expectException(\LogicException::class);
        $observation->recordWindowDecision(RentalInspectionObservation::WINDOW_DECISION_REJECTED, $this->agent);
    }

    public function test_carry_forward_includes_observations_from_a_previous_tenancy(): void
    {
        $item = $this->makeItem('Geyser');
        $firstLeaseInspection = $this->makeInspection(RentalInspection::TYPE_OUT, $this->lease);
        $priorFault = $this->makeObservation($firstLeaseInspection, $item, RentalInspectionObservation::CONDITION_NOT_WORKING);

        $newLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 13000, 'start_date' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $newTenancyInspection = $this->makeInspection(RentalInspection::TYPE_IN, $newLease);

        $carriedItems = $newTenancyInspection->carryForwardItems();
        $carriedItem = $carriedItems->firstWhere('id', $item->id);

        $this->assertNotNull($carriedItem, 'the property-scoped item must appear regardless of which lease created the inspection');
        $this->assertTrue(
            $carriedItem->observations->pluck('id')->contains($priorFault->id),
            'a fault reported under a PREVIOUS tenancy must still be visible on the new one (§0.2)',
        );
    }
}
