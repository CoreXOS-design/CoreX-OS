<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.3 (R0) — ONE "Create work order" action; the work order is the record and the job card
 * is the crew's paper. Input paths proven: Internal crew (work order + draft card in one go, lands on the card) and External
 * contractor (work order only, lands on the work order); the relaxed gate for each allowed fault status and a plain-words
 * refusal for each refused one; NO inherited approval; the "New Job Card" alias (carries context, never makes a card without
 * a work order); one creation announcement on every path; required-empty / optional-empty / malformed input; the
 * own / branch / agency scope of the fault, and the permission key.
 */
final class CreateWorkOrderActionTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    /** @var \Mockery\LegacyMockInterface */
    private $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->notifier = \Mockery::spy(NotificationDispatcher::class);
        $this->app->instance(NotificationDispatcher::class, $this->notifier);
    }

    private function assertCreatedAnnouncedOnce(): void
    {
        $this->notifier->shouldHaveReceived('fire')
            ->withArgs(fn ($user, $key) => $key === 'rental_work_order.created')
            ->once();
    }

    // ── Internal vs external ─────────────────────────────────────────────

    public function test_internal_crew_creates_the_work_order_and_its_draft_job_card_and_lands_on_the_card(): void
    {
        $fault = $this->faultReport();

        $response = $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'internal', 'title' => 'Geyser not heating', 'description' => 'No hot water since Monday',
        ]);

        $workOrder = RentalWorkOrder::firstOrFail();
        $card = RentalJobCard::firstOrFail();
        $response->assertRedirect(route('corex.rental-job-cards.show', $card));
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $workOrder->assignment_type);
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $workOrder->status);
        $this->assertSame($workOrder->id, $card->rental_work_order_id);
        $this->assertSame(RentalJobCard::STATUS_DRAFT, $card->status);
        $this->assertSame($fault->id, $workOrder->reported_fault_report_id);
        $this->assertSame($this->lease->id, $workOrder->lease_id, 'the tenancy travels with the fault');
        $fault->refresh();
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->status);
        $this->assertSame($workOrder->id, $fault->rental_work_order_id);
        $this->assertCreatedAnnouncedOnce();
    }

    public function test_external_contractor_creates_the_work_order_only_and_lands_on_it(): void
    {
        $fault = $this->faultReport();

        $response = $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'outside_supplier', 'trade_type' => 'plumbing', 'title' => 'Geyser not heating', 'description' => 'No hot water',
        ]);

        $workOrder = RentalWorkOrder::firstOrFail();
        $response->assertRedirect(route('corex.rental-work-orders.show', $workOrder));
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, $workOrder->assignment_type);
        $this->assertSame('plumbing', $workOrder->trade_type);
        $this->assertSame(0, RentalJobCard::count(), 'no job card for outside work');
        $this->assertCreatedAnnouncedOnce();
    }

    public function test_internal_is_the_default_when_the_choice_is_not_posted(): void
    {
        $fault = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'title' => 'Geyser not heating', 'description' => 'x',
        ])->assertRedirect();

        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, RentalWorkOrder::firstOrFail()->assignment_type);
        $this->assertSame(1, RentalJobCard::count());
    }

    // ── The relaxed gate (§17.3.2) ───────────────────────────────────────

    public function test_a_work_order_can_be_created_from_each_allowed_fault_status(): void
    {
        $allowed = [
            'reported' => ['status' => RentalFaultReport::STATUS_REPORTED],
            'awaiting approval' => ['status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING],
            'approved, agency appoints' => ['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS],
        ];

        foreach ($allowed as $label => $attrs) {
            $fault = $this->faultReport($attrs + ['title' => "Fault ({$label})"]);
            $this->assertNull($fault->workOrderBlockReason(), "{$label} must be allowed");

            $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
                'assignment_type' => 'outside_supplier', 'title' => "WO {$label}", 'description' => 'x',
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertNotNull($fault->fresh()->rental_work_order_id, "{$label}: the work order is linked");
        }
        $this->assertSame(3, RentalWorkOrder::count());
    }

    public function test_each_refused_fault_status_is_refused_with_a_plain_sentence_and_creates_nothing(): void
    {
        $refused = [
            'declined' => [['status' => RentalFaultReport::STATUS_DECLINED], 'owner declined'],
            'owner handling' => [['status' => RentalFaultReport::STATUS_OWNER_HANDLING, 'approval_route' => RentalFaultReport::ROUTE_OWNER_HANDLES], 'handling this repair themselves'],
            'resolved' => [['status' => RentalFaultReport::STATUS_RESOLVED, 'outcome' => RentalFaultReport::OUTCOME_NOT_REPAIRED, 'outcome_note' => 'x'], 'already resolved'],
            'cancelled' => [['status' => RentalFaultReport::STATUS_CANCELLED], 'was cancelled'],
            'approved but not agency appoints' => [['status' => RentalFaultReport::STATUS_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_OWNER_HANDLES], 'has not asked the agency'],
        ];

        foreach ($refused as $label => [$attrs, $words]) {
            $fault = $this->faultReport($attrs + ['title' => "Fault ({$label})"]);

            $response = $this->actingAs($this->admin)->from(route('corex.rental-fault-reports.show', $fault))
                ->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'x', 'description' => 'x']);

            $response->assertSessionHasErrors('rental_fault_report');
            $this->assertStringContainsString($words, (string) session('errors')->first('rental_fault_report'), $label);
            $this->assertNull($fault->fresh()->rental_work_order_id, $label);
        }
        $this->assertSame(0, RentalWorkOrder::count());
        $this->assertSame(0, RentalJobCard::count());
    }

    public function test_a_second_work_order_cannot_be_created_from_the_same_fault(): void
    {
        $fault = $this->faultReport();
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'a', 'description' => 'b'])->assertRedirect();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault->fresh()), ['assignment_type' => 'internal', 'title' => 'a', 'description' => 'b'])
            ->assertSessionHasErrors('rental_fault_report');

        $this->assertSame(1, RentalWorkOrder::count());
        $this->assertSame(1, RentalJobCard::count());
    }

    public function test_no_approval_is_inherited_from_the_fault(): void
    {
        $fault = $this->faultReport([
            'status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => RentalFaultReport::ROUTE_AGENCY_APPOINTS,
        ]);

        $workOrder = app(RentalWorkOrderService::class)->fromFaultReport($fault, $this->admin, ['title' => 'Geyser', 'description' => 'x']);

        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $workOrder->owner_approval_status);
        $this->assertNull($workOrder->approved_amount);
        $this->assertNull($workOrder->approval_basis);
    }

    // ── Input space ──────────────────────────────────────────────────────

    public function test_required_fields_are_rejected_with_messages_and_optional_ones_may_be_empty(): void
    {
        $fault = $this->faultReport();

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => '', 'description' => ''])
            ->assertSessionHasErrors(['title', 'description']);
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'sideways', 'title' => 'x', 'description' => 'y'])
            ->assertSessionHasErrors('assignment_type');
        $this->assertSame(0, RentalWorkOrder::count());

        // trade type is optional for outside work — the lazy-but-valid shortcut
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'outside_supplier', 'title' => 'x', 'description' => 'y'])
            ->assertSessionHasNoErrors();
        $this->assertNull(RentalWorkOrder::firstOrFail()->trade_type);
    }

    public function test_the_fault_screen_offers_one_create_work_order_button_and_explains_a_refusal(): void
    {
        $open = $this->faultReport();
        $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $open))
            ->assertOk()->assertSee('Create work order')->assertSee('Internal crew')->assertSee('External contractor')->assertDontSee('Raise work order');

        $declined = $this->faultReport(['status' => RentalFaultReport::STATUS_DECLINED, 'title' => 'Declined one']);
        $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $declined))
            ->assertOk()->assertSee('The owner declined this repair')->assertDontSee('id="raise-work-order-form"', false);
    }

    // ── Scope and permission ─────────────────────────────────────────────

    public function test_another_agencys_fault_is_a_404_and_an_out_of_scope_one_is_a_403(): void
    {
        [$otherAgency, $otherAgent, $otherProperty] = $this->otherAgencyWorld();
        $theirs = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherProperty->branch_id, 'property_id' => $otherProperty->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $otherAgent->id,
            'reported_channel' => RentalFaultReport::CHANNEL_PHONE, 'title' => 'Theirs', 'description' => 'x', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $otherAgent->id,
        ]);
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $theirs->id), ['assignment_type' => 'internal', 'title' => 'x', 'description' => 'y'])->assertStatus(404);

        $mine = $this->faultReport();   // created by the admin, not by this agent
        $agent = $this->userWith([
            'rental_fault_reports.view' => 'own', 'rental_fault_reports.raise_work_order' => 'own',
        ], 'agent', ['rental_fault_reports.view', 'rental_fault_reports.raise_work_order']);
        $this->actingAs($agent)->post(route('corex.rental-fault-reports.raise-work-order', $mine), ['assignment_type' => 'internal', 'title' => 'x', 'description' => 'y'])->assertStatus(403);
        $this->assertNull($mine->fresh()->rental_work_order_id);
    }

    public function test_the_raise_permission_key_is_enforced(): void
    {
        $fault = $this->faultReport();
        $viewer = $this->userWith(['rental_fault_reports.view' => 'all'], 'agent', ['rental_fault_reports.view', 'rental_fault_reports.raise_work_order']);

        $this->actingAs($viewer)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'x', 'description' => 'y'])->assertStatus(403);
        $this->assertSame(0, RentalWorkOrder::count());
    }

    // ── "New Job Card" is an alias ───────────────────────────────────────

    public function test_new_job_card_is_an_alias_for_the_work_order_form_with_internal_preselected(): void
    {
        $response = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.create', ['lease_id' => $this->lease->id, 'property_id' => $this->property->id]));

        $response->assertRedirect(route('corex.rental-work-orders.create', [
            'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'assignment_type' => 'internal',
        ]));
        $this->actingAs($this->admin)->get($response->headers->get('Location'))->assertOk();
    }

    public function test_the_alias_sends_a_fault_to_its_own_form_and_an_existing_work_order_to_itself(): void
    {
        $fault = $this->faultReport();
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.create', ['fault_report_id' => $fault->id]))
            ->assertRedirect(route('corex.rental-fault-reports.show', $fault));

        [$workOrder] = $this->internalJob();
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.create', ['rental_work_order_id' => $workOrder->id]))
            ->assertRedirect(route('corex.rental-work-orders.show', $workOrder));
    }

    public function test_the_work_order_form_creates_an_internal_work_order_with_its_card_and_an_external_one_without(): void
    {
        $internal = $this->actingAs($this->admin)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'assignment_type' => 'internal',
            'reported_by_type' => 'agent_noticed', 'title' => 'Loose tile', 'description' => 'Bathroom floor',
        ]);
        $card = RentalJobCard::firstOrFail();
        $internal->assertRedirect(route('corex.rental-job-cards.show', $card));
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $card->workOrder->assignment_type);

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.store'), [
            'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'assignment_type' => 'outside_supplier',
            'reported_by_type' => 'agent_noticed', 'title' => 'Roof leak', 'description' => 'Over the kitchen',
        ])->assertRedirect();
        $this->assertSame(1, RentalJobCard::count(), 'outside work never gets a job card');
        $this->assertSame(2, RentalWorkOrder::count());
    }

    // ── No card without its work order ───────────────────────────────────

    public function test_a_job_card_created_with_no_work_order_is_given_one_up_front(): void
    {
        $card = app(RentalJobCardService::class)->createStandalone([
            'property_id' => $this->property->id, 'lease_id' => $this->lease->id, 'title' => 'Service the gate motor',
        ], $this->admin);

        $this->assertNotNull($card->rental_work_order_id, 'the work order exists from the first save, not lazily at "send quote"');
        $workOrder = $card->workOrder;
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_INTERNAL, $workOrder->assignment_type);
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $workOrder->owner_approval_status);
        $this->assertSame('Service the gate motor', $workOrder->title);
        $this->assertCreatedAnnouncedOnce();
    }

    public function test_a_card_created_for_a_fault_links_through_the_same_gate_and_a_refused_fault_creates_nothing(): void
    {
        $fault = $this->faultReport();
        $card = app(RentalJobCardService::class)->createStandalone(['fault_report_id' => $fault->id, 'title' => 'Geyser'], $this->admin);

        $this->assertSame($fault->id, $card->workOrder->reported_fault_report_id);
        $this->assertSame(RentalFaultReport::STATUS_WORK_ORDER_RAISED, $fault->fresh()->status);

        $declined = $this->faultReport(['status' => RentalFaultReport::STATUS_DECLINED, 'title' => 'Declined']);
        try {
            app(RentalJobCardService::class)->createStandalone(['fault_report_id' => $declined->id, 'title' => 'Nope'], $this->admin);
            $this->fail('a declined fault must not produce a card');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('owner declined', $e->getMessage());
        }
        $this->assertSame(1, RentalJobCard::count(), 'the refused attempt left no card or work order behind');
        $this->assertSame(1, RentalWorkOrder::count());
    }

    // ── One announcement on every path ───────────────────────────────────

    public function test_every_creation_path_announces_the_new_work_order_exactly_once(): void
    {
        $paths = [
            'fault, internal' => fn () => $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $this->faultReport(['title' => 'F1'])), ['assignment_type' => 'internal', 'title' => 'a', 'description' => 'b']),
            'fault, external' => fn () => $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $this->faultReport(['title' => 'F2'])), ['assignment_type' => 'outside_supplier', 'title' => 'a', 'description' => 'b']),
            'work order form, internal' => fn () => $this->actingAs($this->admin)->post(route('corex.rental-work-orders.store'), ['property_id' => $this->property->id, 'assignment_type' => 'internal', 'reported_by_type' => 'agent_noticed', 'title' => 'a', 'description' => 'b']),
            'work order form, external' => fn () => $this->actingAs($this->admin)->post(route('corex.rental-work-orders.store'), ['property_id' => $this->property->id, 'assignment_type' => 'outside_supplier', 'reported_by_type' => 'agent_noticed', 'title' => 'a', 'description' => 'b']),
            'card with no work order' => fn () => app(RentalJobCardService::class)->createStandalone(['property_id' => $this->property->id, 'title' => 'a'], $this->admin),
        ];

        foreach ($paths as $label => $create) {
            $notifier = \Mockery::spy(NotificationDispatcher::class);
            $this->app->instance(NotificationDispatcher::class, $notifier);

            $create();

            $notifier->shouldHaveReceived('fire')->withArgs(fn ($user, $key) => $key === 'rental_work_order.created')->once();
            \Mockery::close();
        }
        $this->assertSame(5, RentalWorkOrder::count(), 'one work order per path');
    }
}
