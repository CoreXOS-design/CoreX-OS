<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.6 (Build I-4 top-up) — the "Ready to complete" pill beside the Complete
 * button. The pill's logic is client-side (it must react the moment an agent signs or records attendance, before
 * any reload), so what PHPUnit can and must guard is that it is wired into the page for an inspection that is
 * awaiting signature and that its readiness rule still reads the same four facts the server enforces. The live
 * behaviour is checked in a real browser on QA1.
 */
final class RentalInspectionReadyToCompletePillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_pill_and_its_readiness_rule_are_on_the_inspections_tab(): void
    {
        $agency = Agency::create(['name' => 'Pill Agency', 'slug' => 'pill-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => '3 Pill Road, Uvongo', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now(), 'created_by_user_id' => $agent->id,
        ]);
        $inspection = RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $agent->id, 'status' => RentalInspection::STATUS_AWAITING_SIGNATURE,
        ]);

        $html = $this->actingAs($agent)->get(route('corex.properties.show', $property->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="ready-to-complete"', $html);
        $this->assertStringContainsString('Ready to complete', $html);
        $this->assertStringContainsString('readyToComplete(', $html);
        // The rule: everything the parties owe, nothing the server alone decides (items, notes).
        $rule = substr($html, (int) strpos($html, 'readyToComplete(section) {'), 1500);
        foreach (["'awaiting_signature'", "'awaiting_wet_ink'", "party_role === 'agent'", 'tenantDisposition', 'landlordDisposition', 'board.complete'] as $fact) {
            $this->assertStringContainsString($fact, $rule, "the readiness rule must still check: {$fact}");
        }
        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $inspection->fresh()->status);
        // …and it follows the per-type rules the server's Complete uses: the payload says whether signatures and
        // attendance are needed for THIS inspection (a Routine one needs no attendance; signatures follow the agency setting).
        $this->assertStringContainsString('insp.signatures_required !== false', $rule);
        $this->assertStringContainsString('insp.attendance_required === false', $rule);
        $tail = \App\Models\RentalInspection::tabPayloadFor($property)['chain_tail'];
        $this->assertTrue($tail->signatures_required);
        $this->assertTrue($tail->attendance_required);
        $inspection->forceFill(['type' => RentalInspection::TYPE_AD_HOC])->save();
        $routine = \App\Models\RentalInspection::tabPayloadFor($property->fresh())['chain_tail'];
        $this->assertFalse($routine->attendance_required);
        $this->assertFalse($routine->signatures_required, 'Routine: signatures optional by default');
    }
}
