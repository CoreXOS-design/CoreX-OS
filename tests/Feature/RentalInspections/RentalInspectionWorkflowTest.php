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
        // record() is the one real entry point (§14.1 fix 2) — create and
        // discrepancy-detection happen atomically.
        return RentalInspectionObservation::record(array_merge([
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
        // §15.7, Stage 5 — this fixture's lease has no tenants and this
        // property has no landlord, so the agent's own signature is the
        // only outstanding requirement.
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);

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
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);

        $inspection->markCompleted();

        $this->assertEqualsWithDelta(
            $inspection->completed_at->copy()->addDays(14)->timestamp,
            $inspection->fault_report_deadline_at->timestamp,
            2,
        );
    }

    /**
     * §0.4 — genuinely two different agents; both observations used
     * $this->agent until 2026-09-22 (RentalInspectionDiscrepancy::
     * sameAuthor() — a same-author correction is no longer a conflict).
     */
    public function test_cannot_complete_an_inspection_with_an_unresolved_discrepancy(): void
    {
        $item = $this->makeItem();
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        $secondAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_GOOD);
        $this->makeObservation($inspection, $item, RentalInspectionObservation::CONDITION_DAMAGED, ['observed_by_user_id' => $secondAgent->id]);

        $this->expectException(\LogicException::class);
        $inspection->markCompleted();
    }

    public function test_starting_the_signing_window_is_not_valid_for_an_ad_hoc_inspection(): void
    {
        // §15.3 (2026-09-20) — widened to BOTH in and out; TYPE_AD_HOC stays
        // excluded, matching its existing lighter-weight lifecycle.
        $adHoc = $this->makeInspection(RentalInspection::TYPE_AD_HOC);

        $this->expectException(\LogicException::class);
        $adHoc->startAwaitingSignature();
    }

    public function test_an_in_inspection_can_now_start_its_own_signing_window(): void
    {
        // §15.3 — in-inspection signing is a whole new path, built in Stage
        // 2. Proven here at the model layer that it genuinely works, not
        // just that the old exception is gone.
        $inIns = $this->makeInspection(RentalInspection::TYPE_IN);

        $inIns->startAwaitingSignature();

        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $inIns->status);
        $this->assertNotNull($inIns->signing_deadline_at);
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

    // ── §15.7, Stage 5 — the completion guard replaced on both types ────

    public function test_an_in_inspection_cannot_complete_while_a_tenant_is_undispositioned(): void
    {
        $tenant = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Naledi', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);

        try {
            $inspection->markCompleted();
            $this->fail('Expected a LogicException naming the outstanding tenant.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Naledi Tenant', $e->getMessage());
        }
    }

    public function test_an_in_inspection_completes_once_every_tenant_is_dispositioned_and_the_agent_signs(): void
    {
        $tenant = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Naledi', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $inspection = $this->makeInspection(RentalInspection::TYPE_IN);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id, 'refusal_reason_preset' => 'not_present',
        ]);
        RentalInspectionSignature::capture($inspection, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);

        $inspection->markCompleted();

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->status);
    }

    public function test_completion_is_blocked_on_an_outstanding_landlord_even_when_every_tenant_is_done(): void
    {
        // created_by_user_id set to the acting agent — ContactScope's config
        // fallback for a bare/unseeded test agency scopes role 'agent' to
        // 'own' contacts, found by cc1 (2026-09-20) as a real test-fixture
        // defect elsewhere in this same feature, confirmed NOT a product
        // bug against real QA1 data.
        $landlord = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Lindiwe', 'last_name' => 'Landlord', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\ContactProperty::create(['contact_id' => $landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord']);
        $inspection = $this->makeInspection(RentalInspection::TYPE_OUT);
        // No tenants on this lease at all — landlord is the only outstanding party.

        try {
            $inspection->markCompleted();
            $this->fail('Expected a LogicException naming the outstanding landlord.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('landlord', $e->getMessage());
        }
    }

    public function test_an_ad_hoc_inspection_is_exempt_from_the_signing_requirement(): void
    {
        // §15.3/§15.7 — Johan's ruling names "both in and out" specifically;
        // ad-hoc keeps its existing lighter-weight lifecycle.
        $adHoc = $this->makeInspection(RentalInspection::TYPE_AD_HOC);

        $adHoc->markCompleted();

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $adHoc->status);
    }

    public function test_completing_an_out_inspection_succeeds_once_a_signature_exists(): void
    {
        // markCompleted()'s guard is still "any signature exists" in Stage 1
        // (§15.7's full 3-party guard replaces it in Stage 5) — this proves
        // that unchanged guard still works against the new table shape.
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);
        RentalInspectionSignature::capture($outIns, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/test.png',
        ]);

        $outIns->markCompleted();

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $outIns->status);
        $this->assertNull($outIns->fault_report_deadline_at, 'the fault-report window belongs to the in-inspection only');
    }

    /**
     * §15 — the old agent_on_behalf/REQUIRED_REFUSAL_PHRASE mechanism is
     * retired. Its real successor, per-party refusal with the agent's own
     * attestation, is built in Stage 4 (§15.11) — these two tests replace
     * the old phrase-validation tests with the Stage 1 model's actual
     * refusal shape, proven directly at the model layer.
     */
    public function test_a_tenant_refusal_is_a_real_disposition_not_a_signature(): void
    {
        $tenant = \App\Models\Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Tenant', 'email' => uniqid() . '@example.test',
        ]);
        \App\Models\LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $signature = RentalInspectionSignature::capture($outIns, RentalInspectionSignature::PARTY_TENANT, RentalInspectionSignature::DISPOSITION_REFUSED, [
            'party_contact_id' => $tenant->id,
            'refusal_reason_preset' => 'refused_no_reason',
        ]);

        $this->assertSame(RentalInspectionSignature::DISPOSITION_REFUSED, $signature->disposition);
        $this->assertNull($signature->party_signature_path);
        // §15.7, Stage 5 — a refusal satisfies the tenant's own requirement,
        // but the agent must still sign to attest to the complete record
        // (§15.2a) before the inspection can actually complete.
        RentalInspectionSignature::capture($outIns, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, [
            'party_signature_path' => 'signatures/agent.png',
        ]);
        $outIns->markCompleted();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $outIns->status);
    }

    public function test_agent_signature_requires_a_real_signature_image(): void
    {
        $outIns = $this->makeInspection(RentalInspection::TYPE_OUT);

        $this->expectException(\InvalidArgumentException::class);
        RentalInspectionSignature::capture($outIns, RentalInspectionSignature::PARTY_AGENT, RentalInspectionSignature::DISPOSITION_SIGNED, []);
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
