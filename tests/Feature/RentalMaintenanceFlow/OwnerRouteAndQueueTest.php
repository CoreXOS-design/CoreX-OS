<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Lease;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\RentalCommandCentreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan's walk of the owner's-own-contractor route (fault 76 -> WO 68) and his command-centre look, 9 Oct 2026:
 * O1 the contractor's name/number really post and save; O2 nothing is "sent to the contractor" on that route; O3 no quotes / spend limit / fee there;
 * C1 the queue rows for work waiting on the agent (and information-only rows for the owner / tenant); C2 one name per fault status.
 * rental-work-orders.md §17.36.
 */
final class OwnerRouteAndQueueTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('OwnerRoute');
    }

    private function sentFault(): RentalFaultReport
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => 'Pool pump broken', 'description' => 'Pump', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault->saveOwnerVersion(['owner_title' => 'Pool pump broken', 'owner_description' => 'The pump is dead.'], $this->admin);
        $fault->fresh()->requestApproval($this->admin);

        return $fault->fresh();
    }

    /** The fields a browser would POST from a rendered <form id="...">: every named, non-disabled input / select / textarea with its rendered value. */
    private function formFields(string $html, string $formId): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $form = (new \DOMXPath($dom))->query("//form[@id='{$formId}']")->item(0);
        $this->assertNotNull($form, "form #{$formId} is on the page");
        $fields = [];
        $xp = new \DOMXPath($dom);
        foreach ($xp->query('.//input|.//select|.//textarea', $form) as $el) {
            $name = $el->getAttribute('name');
            if ($name === '' || $el->hasAttribute('disabled')) {
                continue;
            }
            $fields[] = ['name' => $name, 'type' => strtolower($el->getAttribute('type')), 'value' => $el->getAttribute('value'), 'tag' => $el->nodeName];
        }

        return $fields;
    }

    // ── O1 ───────────────────────────────────────────────────────────────────

    public function test_o1_the_owners_contractor_details_typed_on_the_decision_form_are_saved_and_reach_the_work_order(): void
    {
        $fault = $this->sentFault();
        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $fields = $this->formFields($html, 'record-approval-form');

        $names = array_column($fields, 'name');
        $this->assertSame(1, count(array_keys($names, 'contractor_name', true)), 'exactly one contractor_name input on the decision form (no duplicate hidden one)');
        $this->assertSame(1, count(array_keys($names, 'contractor_phone', true)), 'exactly one contractor_phone input');

        // fill what the person types, post EXACTLY the rendered field set (a real browser posts hidden/x-show fields too)
        $values = ['decision' => 'approved', 'approval_route' => 'owner_handles', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner phoned',
            'contractor_name' => 'Pieter Pool Repairs', 'contractor_phone' => '082 555 0101'];
        $post = [];
        foreach ($fields as $f) {
            if (in_array($f['type'], ['radio', 'checkbox'], true) || in_array($f['name'], ['_token'], true)) {
                continue;
            }
            $post[$f['name']] = $values[$f['name']] ?? $f['value'];
        }
        $post = array_merge($post, $values);
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $fault), $post)->assertSessionHasNoErrors();

        $decision = $fault->fresh()->decision();
        $this->assertSame('Pieter Pool Repairs', $decision->contractor_name);
        $this->assertSame('082 555 0101', $decision->contractor_phone);

        // the appoint form (rendered from the decision) posts the same details through to the work order
        $page = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('Pieter Pool Repairs', $page);
        $this->assertStringNotContainsString('Name not given', $page);
        $appoint = $this->formFields($page, 'raise-work-order-form');
        $post2 = [];
        foreach ($appoint as $f) {
            if (! in_array($f['type'], ['radio', 'checkbox'], true) && $f['name'] !== '_token') {
                $post2[$f['name']] = $f['value'];
            }
        }
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), $post2)->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, $wo->assignment_type);
        $this->assertSame('Pieter Pool Repairs', $wo->contractor_name);
        $this->assertSame('082 555 0101', $wo->contractor_phone);
    }

    // ── O2 / O3 ──────────────────────────────────────────────────────────────

    private function ownerRouteWorkOrder(): RentalWorkOrder
    {
        $fault = $this->sentFault();
        $fault->recordApproval($this->admin, ['decision' => 'approved', 'approval_route' => 'owner_handles', 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok',
            'contractor_name' => 'Pieter Pool Repairs', 'contractor_phone' => '0825550101']);

        return app(\App\Services\Rentals\RentalWorkOrderService::class)->createFromFaultDecision($fault->fresh(), $this->admin, [])['work_order'];
    }

    public function test_o2_o3_the_owners_contractor_route_shows_no_sent_date_no_quotes_no_spend_limit_no_fee_and_says_what_happens_next(): void
    {
        $wo = $this->ownerRouteWorkOrder();
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();

        $this->assertStringContainsString('data-owner-arranges', $html);
        $this->assertStringContainsString('Owner arranges', $html);
        $this->assertStringNotContainsString('Sent to contractor:', $html, 'nothing was sent to anyone');
        $this->assertStringNotContainsString('>Quotes</h2>', $html);
        $this->assertStringNotContainsString('No-approval spend limit', $html);
        $this->assertStringNotContainsString('data-fee-collapse', $html);
        $this->assertStringNotContainsString('name="quote_date"', $html);
        $this->assertStringContainsString('data-owner-route', $html);
        $this->assertStringContainsString("Capture the contractor's name and number", $html);
        $this->assertStringContainsString('Set the appointment with the tenant', $html);
        $this->assertStringContainsString('mark the work order complete', $html);
        $this->assertStringContainsString('data-owner-arranges-note', $html);

        // the list reads the same way
        $list = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Owner arranges', $list);
        $this->assertSame('Owner arranges', $wo->fresh()->statusLabel());

        // an agency-contractor work order is untouched: it still has its Quotes block
        $other = $this->externalWorkOrder();
        $this->assertStringContainsString('>Quotes</h2>', $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $other))->getContent());
    }

    // ── C1 ───────────────────────────────────────────────────────────────────

    private function rowFor(string $title, ?User $as = null, string $scope = 'all'): ?array
    {
        return app(RentalCommandCentreService::class)->queueItems($as ?? $this->admin, $scope)->first(fn ($i) => str_contains((string) $i['detail'], $title));
    }

    public function test_c1_work_waiting_on_the_agent_has_a_row_that_opens_the_place_to_act_and_waiting_on_others_is_information(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        $quote = fn (RentalWorkOrder $wo, float $amount) => $wo->recordQuote(['agency_service_provider_id' => $this->supplier('S' . uniqid())->id, 'amount' => $amount, 'quote_date' => now(), 'detail_text' => 'x'], $this->admin);

        $none = $this->externalWorkOrder(['title' => 'WO-none']);
        $unchosen = $this->externalWorkOrder(['title' => 'WO-unchosen']);
        $quote($unchosen, 300); $quote($unchosen, 350);
        $declined = $this->externalWorkOrder(['title' => 'WO-declined']);
        $q = $quote($declined, 900); $declined->selectQuote($q, $this->admin);
        $declined->recordApproval($this->landlord, ['decision' => 'declined', 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL, 'evidence_text' => 'too dear']);
        $withOwner = $this->externalWorkOrder(['title' => 'WO-withowner']);
        $withOwner->selectQuote($quote($withOwner, 900), $this->admin);
        $send = $this->externalWorkOrder(['title' => 'WO-send']);
        $send->selectQuote($quote($send, 300), $this->admin);
        $noAppt = $this->externalWorkOrder(['title' => 'WO-noappt', 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now()->subDays(2), 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED]);
        $withAppt = $this->externalWorkOrder(['title' => 'WO-appt', 'status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now(), 'appointment_at' => now()->addDays(2), 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED]);
        $tenantCheck = $this->externalWorkOrder(['title' => 'WO-tenantcheck', 'status' => RentalWorkOrder::STATUS_IN_PROGRESS, 'appointment_at' => now()->subDay(), 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED]);
        RentalWorkCompletionRound::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'rental_work_order_id' => $tenantCheck->id, 'round_no' => 1,
            'outcome' => RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, 'opened_at' => now()->subDays(2), 'window_ends_at' => now()->addDays(3), 'reported_by_label' => 'Crew', 'reported_via' => 'office']);
        $answered = $this->externalWorkOrder(['title' => 'WO-answered', 'status' => RentalWorkOrder::STATUS_IN_PROGRESS, 'appointment_at' => now()->subDay(), 'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED]);
        RentalWorkCompletionRound::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'rental_work_order_id' => $answered->id, 'round_no' => 1,
            'outcome' => RentalWorkCompletionRound::OUTCOME_CONFIRMED, 'opened_at' => now()->subDays(3), 'responded_at' => now()->subDay(), 'window_ends_at' => now()->addDays(3), 'reported_by_label' => 'Crew', 'reported_via' => 'office']);
        $ownerRoute = $this->externalWorkOrder(['title' => 'WO-ownerroute', 'assignment_type' => RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR, 'status' => RentalWorkOrder::STATUS_ORDERED, 'agency_service_provider_id' => null]);
        [$cardNoCrew] = $this->internalJob(345);
        $cardNoCrew->forceFill(['title' => 'JC-nocrew'])->save();
        [$cardNoDate] = $this->internalJob(345);
        $cardNoDate->forceFill(['title' => 'JC-nodate', 'rental_crew_id' => $this->crew->id, 'status' => \App\Models\RentalJobCard::STATUS_APPROVED])->save();

        $expect = [
            'WO-none' => ['wo_capture_quote', false, 'corex.rental-work-orders.show'],
            'WO-unchosen' => ['wo_choose_quote', false, 'corex.rental-work-orders.show'],
            'WO-declined' => ['wo_quote_declined', false, 'corex.rental-work-orders.show'],
            'WO-withowner' => ['wo_with_owner', true, 'corex.rental-work-orders.show'],
            'WO-send' => ['wo_send', false, 'corex.rental-work-orders.show'],
            'WO-noappt' => ['wo_set_appointment', false, 'corex.rental-work-orders.show'],
            'WO-tenantcheck' => ['wo_tenant_check', true, 'corex.rental-work-orders.show'],
            'WO-answered' => ['wo_complete', false, 'corex.rental-work-orders.show'],
            'WO-ownerroute' => ['wo_set_appointment', false, 'corex.rental-work-orders.show'],
            'JC-nocrew' => ['jc_assign_crew', false, 'corex.rental-job-cards.show'],
            'JC-nodate' => ['jc_book', false, 'corex.rental-job-cards.show'],
        ];
        foreach ($expect as $title => [$type, $info, $route]) {
            $row = $this->rowFor($title);
            $this->assertNotNull($row, "{$title} has a queue row");
            $this->assertSame($type, $row['type'], $title);
            $this->assertSame($info, (bool) ($row['informational'] ?? false), "{$title}: informational");
            $this->assertSame($route, $row['route'], $title);
        }
        $this->assertNull($this->rowFor('WO-appt'), 'a sent work order WITH an appointment waits on nobody here');

        // information rows are never counted as "needs action", and they carry their age
        $items = app(RentalCommandCentreService::class)->queueItems($this->admin, 'all');
        $this->assertTrue($items->where('informational', true)->every(fn ($i) => $i['urgency'] >= 5), 'listed after the real actions');
        $this->assertStringContainsString('with owner', $this->rowFor('WO-withowner')['detail']);

        // scope: someone whose "own" does not include this property sees none of it
        $stranger = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->assertNull($this->rowFor('WO-none', $stranger, 'own'));
        $this->assertNull($this->rowFor('JC-nocrew', $stranger, 'own'));
        $this->assertNotNull($this->rowFor('WO-none', $this->admin, 'own'), 'the property agent sees it under own');
    }

    // ── C2 ───────────────────────────────────────────────────────────────────

    public function test_c2_one_name_per_fault_status_everywhere(): void
    {
        $this->assertSame('Owner approved', RentalFaultReport::statusWord('approved'));
        $this->assertSame('Owner arranges the repair', RentalFaultReport::statusWord('owner_handling'));
        $this->assertSame('Owner declined', RentalFaultReport::statusWord('declined'));

        $fault = $this->sentFault();
        $fault->recordApproval($this->admin, ['decision' => 'approved', 'approval_route' => 'owner_handles', 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok']);
        $list = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.index'))->assertOk()->getContent();
        $detail = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();

        foreach ([$list, $detail] as $html) {
            $this->assertStringContainsString('Owner arranges the repair', $html);
            $this->assertStringNotContainsString('Owner decided -', $html);
            $this->assertStringNotContainsString('Owner handling', $html);
        }
        $this->assertStringContainsString('Owner approved', $list, 'tile and filter');
        // print list and export use the same words
        $print = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.print-list'))->assertOk()->getContent();
        $this->assertStringContainsString('Owner arranges the repair', $print);
        $this->assertStringNotContainsString('Owner handling', $print);
    }
}
