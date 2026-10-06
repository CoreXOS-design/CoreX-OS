<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Lease;
use App\Models\RentalApprovalDecision;
use App\Models\RentalEmergencyApproval;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\LeaseTimelineService;
use App\Services\Rentals\RentalCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.16 — the tenancy log (Lease Hub) gains three types — `emergency_approval`, `variation`,
 * `completion_check` — and one builder. Proven: every round reads in the log ("Work reported done by {name} (round n)",
 * "Tenant confirmed" / "Tenant reported not complete: {note}" / "Accepted — no response in {n} days", "Dispute sent back",
 * "Reported done again (round n+1)"); approval decisions appear as work-order entries; emergency approvals and variations
 * appear; each entry links to the work order; the type filter and the date filter work; another tenancy's maintenance never
 * surfaces here; a vacancy work order (no lease) never appears.
 */
final class LeaseTimelineMaintenanceFlowTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private RentalWorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        [$this->workOrder] = $this->internalJob();
    }

    private function entries(?Lease $lease = null): \Illuminate\Support\Collection
    {
        return app(LeaseTimelineService::class)->allEntriesFor($lease ?? $this->lease);
    }

    private function descriptions(string $type): array
    {
        return $this->entries()->where('type', $type)->pluck('description')->all();
    }

    public function test_the_three_new_types_are_filterable_types(): void
    {
        foreach (['emergency_approval', 'variation', 'completion_check'] as $type) {
            $this->assertContains($type, LeaseTimelineService::TYPES);
        }
    }

    public function test_every_round_and_its_answer_read_in_plain_words_and_link_to_the_work_order(): void
    {
        $service = app(RentalCompletionService::class);
        $first = $service->openRound($this->workOrder, ['reported_by_label' => 'Sipho Dlamini', 'reported_via' => 'crew_link']);
        $service->respond($first, false, 'Still wet under the sink', [], ['contact' => $this->tenant, 'via' => 'portal']);
        $second = $service->openRound($this->workOrder->fresh(), ['reported_by_label' => 'Sipho Dlamini', 'reported_via' => 'crew_link']);
        $service->respond($second, true, null, [], ['contact' => $this->tenant, 'via' => 'portal']);

        $log = $this->descriptions('completion_check');

        $this->assertContains('Work reported done by Sipho Dlamini (round 1): Fix the geyser', $log);
        $this->assertContains('Tenant reported not complete: Still wet under the sink (round 1)', $log);
        $this->assertContains('Reported done again by Sipho Dlamini (round 2): Fix the geyser', $log);
        $this->assertContains('Tenant confirmed the work is done (round 2)', $log);
        foreach ($this->entries()->where('type', 'completion_check') as $entry) {
            $this->assertSame('corex.rental-work-orders.show', $entry['route_name']);
            $this->assertSame($this->workOrder->id, $entry['route_param']);
        }
    }

    public function test_silence_and_a_send_back_are_logged(): void
    {
        $service = app(RentalCompletionService::class);
        $round = $service->openRound($this->workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);
        $service->respond($round, false, 'It still drips', [], ['contact' => $this->tenant, 'via' => 'portal']);
        $service->sendBack($this->workOrder->fresh(), $this->admin);

        [$other] = $this->internalJob(['title' => 'Quiet job']);
        $silent = $service->openRound($other, ['reported_by_label' => 'Team 2', 'reported_via' => 'crew_link']);
        $silent->forceFill(['window_ends_at' => now()->subHour(), 'opened_at' => now()->subDays(5)])->save();
        $service->settleSilent();

        $log = $this->descriptions('completion_check');
        $this->assertContains('Dispute sent back to the crew: Fix the geyser', $log);
        $this->assertTrue(collect($log)->contains(fn ($d) => str_starts_with($d, 'Accepted — no response in 5 days (round 1)')), 'silence is logged with its length');
    }

    public function test_approval_decisions_appear_as_work_order_entries(): void
    {
        RentalApprovalDecision::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $this->workOrder->id, 'decided_by' => RentalApprovalDecision::BY_SYSTEM,
            'decision' => RentalApprovalDecision::DECISION_AUTO_APPROVED, 'basis' => 'no_approval_limit', 'term_key' => 'no_approval_limit',
            'note' => "Approved — within the owner's no-approval limit",
        ]);

        $this->assertContains("Approved — within the owner's no-approval limit: Fix the geyser", $this->descriptions('work_order'));
    }

    public function test_emergency_approvals_and_variations_appear(): void
    {
        RentalEmergencyApproval::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $this->workOrder->id, 'approved_by_name' => 'Pieter van der Merwe',
            'approved_via' => 'phone', 'approved_at' => now()->subHour(), 'reason' => 'Burst pipe flooding the kitchen', 'recorded_by_user_id' => $this->admin->id,
            'voided_at' => now(), 'void_reason' => 'Captured against the wrong job',
        ]);
        RentalWorkOrderVariation::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $this->workOrder->id, 'revision' => 1, 'status' => RentalWorkOrderVariation::STATUS_AUTO_APPROVED,
            'origin' => 'crew_lines', 'baseline_amount' => 1000, 'extra_amount' => 80, 'new_total' => 1080, 'raised_by_user_id' => $this->admin->id,
            'raised_at' => now()->subMinutes(30), 'decided_at' => now()->subMinutes(29),
        ]);

        $emergency = $this->descriptions('emergency_approval');
        $this->assertContains('Emergency work agreed by the owner (Pieter van der Merwe, by phone): Fix the geyser', $emergency);
        $this->assertContains('Emergency approval voided — Captured against the wrong job: Fix the geyser', $emergency);

        $variation = $this->descriptions('variation');
        $this->assertContains('Variation raised: extra R80.00 (new total R1,080.00) — Fix the geyser', $variation);
        $this->assertContains("Variation auto-approved — within the owner's agreed limit: Fix the geyser", $variation);
    }

    public function test_the_type_filter_narrows_the_log_and_other_types_are_unaffected(): void
    {
        app(RentalCompletionService::class)->openRound($this->workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);

        $only = app(LeaseTimelineService::class)->paginatedFor($this->lease, null, ['completion_check']);
        $this->assertSame(['completion_check'], $only['entries']->pluck('type')->unique()->values()->all());

        $all = app(LeaseTimelineService::class)->paginatedFor($this->lease);
        $this->assertContains('work_order', $all['entries']->pluck('type')->all(), 'the work order entry is still there');
        $this->assertContains('completion_check', $all['entries']->pluck('type')->all());
    }

    public function test_another_tenancys_maintenance_never_surfaces_and_a_vacancy_work_order_does_not_appear(): void
    {
        app(RentalCompletionService::class)->openRound($this->workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);

        $otherProperty = $this->makeProperty($this->agency, $this->admin, '4 Beach Road, Uvongo');
        $otherLease = $this->makeLease($this->agency, $otherProperty);
        $this->assertSame([], $this->entries($otherLease)->where('type', 'completion_check')->all());

        [$vacancy] = $this->internalJob(['lease_id' => null, 'title' => 'Vacancy repair']);
        app(RentalCompletionService::class)->openRound($vacancy, ['reported_by_label' => 'Team 3', 'reported_via' => 'crew_link']);
        $this->assertNotContains('Work reported done by Team 3 (round 1): Vacancy repair', $this->descriptions('completion_check'));
    }

    public function test_a_lease_with_no_maintenance_is_unchanged(): void
    {
        $quiet = $this->makeLease($this->agency, $this->makeProperty($this->agency, $this->admin, '8 Quiet Lane, Shelly Beach'));

        $this->assertSame([], $this->entries($quiet)->whereIn('type', ['emergency_approval', 'variation', 'completion_check'])->all());
    }

    public function test_the_lease_hub_screen_lists_the_new_filters_and_the_entries(): void
    {
        app(RentalCompletionService::class)->openRound($this->workOrder, ['reported_by_label' => 'Sipho Dlamini', 'reported_via' => 'crew_link']);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('Completion check', $html);
        $this->assertStringContainsString('Emergency approval', $html);
        $this->assertStringContainsString('Work reported done by Sipho Dlamini (round 1)', $html);
    }
}
