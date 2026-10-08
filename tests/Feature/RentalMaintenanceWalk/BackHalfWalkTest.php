<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceWalk;

use App\Mail\Rentals\RentalContractorWorkOrderMail;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\RentalFaultReport;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Services\Rentals\RentalFaultProgressService as Progress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * The BACK HALF of maintenance walked end to end, the way an agent, the owner, the tenant and a crew member really do it, on all
 * three routes (agency contractor, owner's contractor, internal crew): work order -> quote / authorisation -> appointment ->
 * (job card) -> started -> reported complete -> tenant check -> completed -> cost recorded -> closed. After EVERY step it asserts
 * what the tenant and the owner see (the progress line, never a price for the tenant, never a job card) so a step that leaves the
 * line out of step fails here. Every address is @example.invalid; mail goes through the recording fake / Mail::fake().
 */
final class BackHalfWalkTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        // Settings that make the world deterministic: a R500 no-approval limit, so a R1,500 quote needs the owner.
    }

    // ── helpers: what each person sees ───────────────────────────────────────────────────────

    private function tenantLine(RentalFaultReport $fault): array
    {
        return app(Progress::class)->forFault($fault->fresh(), Progress::AUDIENCE_TENANT);
    }

    private function ownerLine(RentalFaultReport $fault): array
    {
        return app(Progress::class)->forFault($fault->fresh(), Progress::AUDIENCE_OWNER);
    }

    /** @var array<int, \App\Models\ClientUser> */
    private array $logins = [];

    private function loginOf(\App\Models\Contact $c): \App\Models\ClientUser
    {
        return $this->logins[$c->id] ??= $this->clientUserFor($c);
    }

    private function asTenant(): void
    {
        Sanctum::actingAs($this->loginOf($this->tenant), ['client']);
    }

    /** The crew (and anyone on a secure link) has no login at all. */
    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function asOwner(): void
    {
        Sanctum::actingAs($this->loginOf($this->landlord), ['client']);
    }

    /** The fault goes: reported -> agent version -> sent -> owner approves with the given route. Returns the fault. */
    private function approvedFault(string $route, array $contractor = []): RentalFaultReport
    {
        $fault = $this->faultReport();
        $fault->saveOwnerVersion(['owner_title' => $fault->title], $this->admin);
        $fault->fresh()->requestApproval($this->admin);
        $this->asOwner();
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$fault->id}/decision", array_merge(
            ['decision' => 'approve', 'handled_by' => $route], $contractor
        ))->assertOk();

        return $fault->fresh();
    }

    /** Every office screen of this job must draw (200) in whatever state it is in - a Blade error at one state is a dead screen. */
    private function screensDraw(RentalFaultReport $fault, string $state): void
    {
        $fault = $fault->fresh();
        $wo = $fault->workOrder;
        $this->asGuest();
        $urls = [
            route('corex.rental-fault-reports.show', $fault), route('corex.rental-fault-reports.index'),
            route('corex.rental-work-orders.index'), route('corex.rental-job-cards.index'),
            route('corex.rentals.command-centre.index'),
        ];
        if ($wo) {
            $urls[] = route('corex.rental-work-orders.show', $wo);
            $urls[] = route('corex.rental-work-orders.pdf', $wo);
            if ($wo->jobCard) {
                $urls[] = route('corex.rental-job-cards.show', $wo->jobCard);
                $urls[] = route('corex.rental-job-cards.print', $wo->jobCard);
            }
        }
        foreach ($urls as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk("[{$state}] {$url} must draw");
        }
    }

    // ═════════════════════════ ROUTE 1 — AGENCY CONTRACTOR (outside supplier) ═════════════════════════

    public function test_route_agency_contractor_end_to_end(): void
    {
        $supplier = AgencyServiceProvider::create([
            'agency_id' => $this->agency->id, 'name' => 'Ramsgate Plumbing', 'email' => 'plumber.' . uniqid() . '@example.invalid',
            'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
        $fault = $this->approvedFault('agency');
        $this->assertSame('owner_decided', $this->tenantLine($fault)['current']);

        // 1 - the agent creates the work order from the fault (agency's contractor).
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'outside_supplier', 'title' => 'Fix the burst pipe', 'description' => 'Under the sink',
            'agency_service_provider_id' => $supplier->id,
        ])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $this->assertNotNull($wo, 'a work order exists');
        $this->assertSame(RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, $wo->assignment_type);
        // Not "sent to the contractor" yet: the work order still needs a quote and the owner's authorisation.
        $this->assertSame('owner_decided', $this->tenantLine($fault)['current']);

        // 2 - quote captured and selected: R1,500 is over the R500 limit -> the owner is asked.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $wo), [
            'agency_service_provider_id' => $supplier->id, 'amount' => 1500, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace pipe',
        ])->assertSessionHasNoErrors();
        $quote = RentalWorkOrderQuote::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.select', [$wo, $quote]))->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);

        // The owner sees the quote waiting ON the work order, with the amount; the tenant sees no price at all.
        $this->asOwner();
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertOk()->json('work_orders'))->firstWhere('id', $wo->id);
        $this->assertSame('pending', $row['client']['owner_approval_status']);
        $this->asTenant();
        $tenantBody = $this->getJson('/api/v1/client/rentals/work-orders')->assertOk()->getContent();
        $this->assertStringNotContainsString('1500', $tenantBody);
        $this->assertStringNotContainsString('1,500', $tenantBody);

        // 3 - work cannot be sent to the contractor until the owner authorises it.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])
            ->assertSessionHasErrors('rental_work_order');
        $this->assertSame(RentalWorkOrder::STATUS_REPORTED, $wo->fresh()->status);

        $this->screensDraw($fault, 'agency route: quote waiting on the owner');

        // 4 - the owner approves the quote in the portal.
        $this->asOwner();
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);

        // 5 - the agent sends the work order to the contractor (PDF mail, status ordered).
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
        $this->assertSame('Sent to contractor for scheduling', $this->tenantLine($fault)['current_label']);
        $this->assertSame('sent_to_contractor', $this->ownerLine($fault)['current']);
        $this->assertCount(1, $this->mailer->sentOf(RentalContractorWorkOrderMail::class));
        $this->assertSame($supplier->email, $this->mailer->sentOf(RentalContractorWorkOrderMail::class)[0][0]);

        // 6 - the appointment is set (agent), then changed.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), [
            'appointment_at' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d\TH:i'), 'appointment_note' => 'Gate code 1234',
        ])->assertSessionHasNoErrors();
        $line = $this->tenantLine($fault);
        $this->assertSame('appointment_set', $line['current']);
        $this->assertStringContainsString('Ramsgate Plumbing', collect($line['steps'])->firstWhere('key', 'appointment_set')['detail']);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.appointment.store', $wo), [
            'appointment_at' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();
        $this->assertStringContainsString('10:00', collect($this->tenantLine($fault)['steps'])->firstWhere('key', 'appointment_set')['detail']);

        // 7 - work started.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.start-progress', $wo))->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $this->assertSame('in_progress', $this->tenantLine($fault)['current']);
        $this->assertSame('in_progress', $this->ownerLine($fault)['current']);

        // 8 - the contractor reports done (agent captures it) -> the tenant is asked to check.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $wo), ['reported_via' => 'phone', 'note' => 'Done', 'date_done' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $round->outcome);
        $line = $this->tenantLine($fault);
        $this->assertSame('Work completed — please check', $line['current_label']);
        $this->assertSame('check', end($line['steps'])['action']);

        // 9 - the tenant says NOT complete -> reopened, and the line says so.
        $this->asTenant();
        $this->postJson("/api/v1/client/rentals/work-orders/{$wo->id}/completion-response", ['fixed' => false, 'note' => 'Still dripping a little'])->assertOk();
        $this->screensDraw($fault, 'agency route: disputed');
        $this->assertSame(RentalWorkOrder::STATUS_DISPUTED, $wo->fresh()->status);
        $this->assertSame('Not complete — being put right', $this->tenantLine($fault)['current_label']);
        // ...and the agent cannot close it while it is disputed.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1500])
            ->assertSessionHasErrors('rental_work_order');

        // 10 - the contractor puts it right and reports done again -> round 2 -> the tenant confirms.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $wo), ['reported_via' => 'phone', 'date_done' => now()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $this->assertSame(2, RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
        $this->asTenant();
        $this->postJson("/api/v1/client/rentals/work-orders/{$wo->id}/completion-response", ['fixed' => true])->assertOk();

        // 11 - the agent closes it: cost recorded against the owner.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 1500, 'completion_notes' => 'Invoice 114'])
            ->assertSessionHasNoErrors();
        $wo = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->status);
        $this->assertSame('owner', $wo->paid_by);
        $this->assertSame('1500.00', (string) $wo->cost_amount);
        $line = $this->tenantLine($fault);
        $this->assertSame('completed', $line['current']);
        $this->assertSame('Work completed', $line['current_label']);
        $this->assertSame(array_fill(0, 8, 'done'), array_column($line['steps'], 'state'));
        $this->screensDraw($fault, 'agency route: completed');

        // Closed: the agent records the fault's outcome; the fault resolves and the tenant is NOT mailed a second "completed".
        $before = count(\Illuminate\Support\Facades\Mail::queued(\App\Mail\Rentals\RentalTenantStatusChangeMail::class));
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $fault), ['outcome' => 'repaired', 'repaired_at' => now()->toDateString(), 'outcome_note' => 'Pipe replaced'])
            ->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $fault->fresh()->status);
        $this->assertSame('completed', $this->tenantLine($fault)['current']);
        $this->assertCount($before, \Illuminate\Support\Facades\Mail::queued(\App\Mail\Rentals\RentalTenantStatusChangeMail::class));
    }

    // ═════════════════════════ ROUTE 2 — OWNER'S OWN CONTRACTOR ═════════════════════════

    public function test_route_owners_contractor_end_to_end(): void
    {
        $fault = $this->approvedFault('own', ['contractor_name' => 'Bob the Plumber', 'contractor_phone' => '0831234567']);

        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'owner_contractor', 'title' => 'Fix the tap', 'description' => 'x',
            'contractor_name' => 'Bob the Plumber', 'contractor_phone' => '0831234567',
        ])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $this->assertNotNull($wo);
        $this->assertTrue($wo->isOwnerContractor());
        $this->assertSame("Sent to the owner's contractor for scheduling", $this->tenantLine($fault)['current_label']);

        // The owner books the appointment from the portal; the tenant is told (existing appointment mail) and the line shows who.
        $this->asOwner();
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/appointment", ['appointment_at' => now()->addDays(2)->setTime(8, 30)->format('Y-m-d\TH:i'), 'note' => 'Call first'])->assertOk();
        $step = collect($this->tenantLine($fault)['steps'])->firstWhere('key', 'appointment_set');
        $this->assertSame('done', $step['state']);
        $this->assertStringContainsString('Bob the Plumber', $step['detail']);
        $this->assertSame('appointment_set', $this->ownerLine($fault)['current']);

        // The owner reports it started, then finished.
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/progress", ['action' => 'started'])->assertOk();
        $this->assertSame('in_progress', $this->tenantLine($fault)['current']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/progress", ['action' => 'finished', 'note' => 'All done'])->assertOk();
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->first();
        $this->assertNotNull($round, 'the owner reporting it finished opens the tenant check');
        $this->assertSame(RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT, $round->outcome);
        $this->assertSame('Work completed — please check', $this->tenantLine($fault)['current_label']);

        // The tenant confirms; the agent closes and records the cost against the TENANT's deposit.
        $this->asTenant();
        $this->postJson("/api/v1/client/rentals/work-orders/{$wo->id}/completion-response", ['fixed' => true])->assertOk();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'deposit_deduction', 'cost_amount' => 850])
            ->assertSessionHasNoErrors();
        $this->assertSame('deposit_deduction', $wo->fresh()->paid_by);
        $this->assertSame('Work completed', $this->tenantLine($fault)['current_label']);
        $this->assertSame(array_fill(0, 8, 'done'), array_column($this->tenantLine($fault)['steps'], 'state'));
    }

    // ═════════════════════════ ROUTE 3 — INTERNAL CREW (work order + job card + crew link) ═════════════════════════

    public function test_route_internal_crew_end_to_end(): void
    {
        $fault = $this->approvedFault('agency');
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'internal',   // 8 Oct 2026: the title comes from the fault ('Geyser not heating')
        ])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $card = $wo->jobCard;
        $this->assertNotNull($card, 'the internal route creates the job card with the work order');
        $this->assertSame('Sent to our maintenance team for scheduling', $this->tenantLine($fault)['current_label']);

        // The job card is the crew's paper: assign the crew, add a task and priced lines.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.assign-crew', $card), ['rental_crew_id' => $this->crew->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.tasks.store', $card), ['description' => 'Drain the geyser'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.lines.store', $card), ['type' => 'part', 'description' => 'Element 3kW', 'quantity' => 1, 'unit_price' => 1200, 'unit_cost' => 700])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.lines.store', $card), ['type' => 'labour', 'description' => 'Fit element', 'quantity' => 2, 'unit_price' => 300, 'unit_cost' => 150])->assertSessionHasNoErrors();

        // Over the R500 limit: the quote goes to the owner, work cannot be scheduled or started until the owner agrees.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasErrors('rental_job_card');
        $this->asOwner();
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/work-orders')->json('work_orders'))->firstWhere('id', $wo->id);
        $this->assertSame('pending', $row['client']['owner_approval_status']);
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertOk();
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $wo->fresh()->owner_approval_status);

        // Schedule (the booking mirrors to the work order's appointment: the tenant's line shows it and who is coming).
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => now()->addDays(2)->setTime(9, 0)->format('Y-m-d H:i')])->assertSessionHasNoErrors();
        $step = collect($this->tenantLine($fault)['steps'])->firstWhere('key', 'appointment_set');
        $this->assertSame('done', $step['state']);
        $this->assertStringContainsString('our maintenance team', $step['detail']);

        // The crew link: the crew opens the page, ticks the task, adds a photo.
        $issued = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueForJobCard($card->fresh(), $this->admin);
        $base = '/secure/job-cards/' . $issued['raw_token'];
        $this->get($base)->assertOk()->assertSee('Geyser not heating');
        $task = $card->fresh()->tasks()->first();
        $this->postJson("{$base}/tasks/{$task->id}/tick")->assertOk();
        $this->post("{$base}/photos", ['photo_type' => 'completed', 'photos' => [\Illuminate\Http\UploadedFile::fake()->image('done.jpg')]])->assertSessionHasNoErrors();

        // The agent starts the job -> work in progress on the line.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $this->assertSame('in_progress', $this->tenantLine($fault)['current']);

        // The crew signs it complete from the link -> the tenant is asked to check.
        $this->post("{$base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();
        $round = RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->first();
        $this->assertNotNull($round, 'crew completion opens a tenant check round');
        $this->assertSame('Work completed — please check', $this->tenantLine($fault)['current_label']);

        // The tenant says it is NOT complete: work order AND card reopen; the crew link is live again; the tenant sees "being put right".
        $this->asTenant();
        $this->postJson("/api/v1/client/rentals/work-orders/{$wo->id}/completion-response", ['fixed' => false, 'note' => 'Still no hot water'])->assertOk();
        $this->assertSame(RentalWorkOrder::STATUS_DISPUTED, $wo->fresh()->status);
        $this->assertSame('disputed', $card->fresh()->status);
        $this->assertSame('Not complete — being put right', $this->tenantLine($fault)['current_label']);
        $this->asGuest();
        $this->get($base)->assertOk()->assertSee('Still no hot water');

        // The crew puts it right and signs again; round 2; the tenant confirms.
        $this->post("{$base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, RentalWorkCompletionRound::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->count());
        $this->asTenant();
        $this->postJson("/api/v1/client/rentals/work-orders/{$wo->id}/completion-response", ['fixed' => true])->assertOk();

        $this->screensDraw($fault, 'internal route: awaiting the tenant check / confirmed');

        // The agent signs off and closes the job card -> the work order closes with it; cost recorded.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.agent-sign-off', $card))->assertSessionHasNoErrors();
        // The cost is carried by whoever the agent says (here: the tenant damaged the element) - not hard-wired to the owner.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.complete', $card), ['paid_by' => 'tenant'])->assertSessionHasNoErrors();
        $wo = $wo->fresh();
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->status);
        $this->assertSame('tenant', $wo->paid_by);
        $this->assertNotNull($wo->cost_amount);
        $line = $this->tenantLine($fault);
        $this->assertSame('Work completed', $line['current_label']);
        $this->assertSame(array_fill(0, 8, 'done'), array_column($line['steps'], 'state'));

        // What the tenant sees through the API is the work order: never the job card, never a price.
        $this->asTenant();
        $body = $this->getJson('/api/v1/client/rentals/work-orders')->assertOk()->getContent();
        foreach (['job_card', 'unit_cost', 'cost_total', 'selling_amount', '1200', '700'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "tenant payload must not carry {$needle}");
        }
    }

    // ═════════════════════════ SIDE PATHS ═════════════════════════

    private function supplier(): AgencyServiceProvider
    {
        return AgencyServiceProvider::create([
            'agency_id' => $this->agency->id, 'name' => 'Side Plumbing', 'email' => 'side.' . uniqid() . '@example.invalid',
            'is_active' => true, 'created_by_id' => $this->admin->id,
        ]);
    }

    private function externalWo(RentalFaultReport $fault, AgencyServiceProvider $supplier): RentalWorkOrder
    {
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), [
            'assignment_type' => 'outside_supplier', 'title' => 'Side job', 'description' => 'x', 'agency_service_provider_id' => $supplier->id,
        ])->assertSessionHasNoErrors();

        return $fault->fresh()->workOrder;
    }

    private function quoteAndSelect(RentalWorkOrder $wo, AgencyServiceProvider $supplier, float $amount): RentalWorkOrderQuote
    {
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.store', $wo), [
            'agency_service_provider_id' => $supplier->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'Quote ' . $amount,
        ])->assertSessionHasNoErrors();
        $quote = RentalWorkOrderQuote::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->orderByDesc('id')->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.quotes.select', [$wo, $quote]))->assertSessionHasNoErrors();

        return $quote;
    }

    public function test_owner_declines_the_quote_then_a_cheaper_quote_within_the_limit_goes_ahead(): void
    {
        $supplier = $this->supplier();
        $fault = $this->approvedFault('agency');
        $wo = $this->externalWo($fault, $supplier);
        $this->quoteAndSelect($wo, $supplier, 1500);

        $this->asOwner();
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'decline'])->assertOk();
        $this->assertSame(RentalWorkOrder::APPROVAL_DECLINED, $wo->fresh()->owner_approval_status);

        // Nothing can be sent to the contractor on a declined quote, and the owner cannot decide a second time.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])->assertSessionHasErrors('rental_work_order');
        $this->asOwner();
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertStatus(422);

        // The tenant is never told about the owner's decline (no reason, no word "declined").
        $this->asTenant();
        $this->assertStringNotContainsString('decline', strtolower($this->getJson('/api/v1/client/rentals/work-orders')->assertOk()->getContent()));

        // A new, cheaper quote inside the no-approval limit is selected: the work order can go ahead again.
        $this->quoteAndSelect($wo, $supplier, 450);
        $this->assertNotSame(RentalWorkOrder::APPROVAL_DECLINED, $wo->fresh()->owner_approval_status);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $wo), ['agency_service_provider_id' => $supplier->id])->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->fresh()->status);
    }

    public function test_cancelling_a_work_order_cancels_its_job_card_and_the_fault_can_get_a_new_work_order(): void
    {
        $fault = $this->approvedFault('agency');
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'T', 'description' => 'D'])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $card = $wo->jobCard;

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cancel', $wo), ['cancel_reason' => 'Owner changed his mind'])->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrder::STATUS_CANCELLED, $wo->fresh()->status);
        $this->assertSame('cancelled', $card->fresh()->status, 'the crew\'s paper is cancelled with the work order');
        $this->assertSame('cancelled', $this->tenantLine($fault)['current']);
        $this->assertSame('cancelled', $this->ownerLine($fault)['current']);
        $this->screensDraw($fault, 'cancelled');

        // The fault is not stuck behind the cancelled work order: a fresh one can be created.
        $this->assertNull($fault->fresh()->workOrderBlockReason());
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault->fresh()), ['assignment_type' => 'internal', 'title' => 'Again', 'description' => 'D'])->assertSessionHasNoErrors();
        $new = $fault->fresh()->workOrder;
        $this->assertNotSame($wo->id, $new->id);
        $this->assertSame('sent_to_contractor', $this->tenantLine($fault)['current']);
    }

    public function test_cancelling_a_job_card_cancels_its_work_order_too(): void
    {
        $fault = $this->approvedFault('agency');
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'T', 'description' => 'D'])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $card = $wo->jobCard;

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.cancel', $card), ['cancel_reason' => 'Not needed'])->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $card->fresh()->status);
        $this->assertSame(RentalWorkOrder::STATUS_CANCELLED, $wo->fresh()->status, 'a card cancelled alone would leave an orphan open work order');
        $this->assertSame('cancelled', $this->tenantLine($fault)['current']);
    }

    public function test_a_completed_work_order_cannot_be_cancelled_and_nothing_half_cancels(): void
    {
        $fault = $this->approvedFault('own', ['contractor_name' => 'Bob']);
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'owner_contractor', 'title' => 'T', 'description' => 'D', 'contractor_name' => 'Bob'])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $wo->forceFill(['status' => RentalWorkOrder::STATUS_IN_PROGRESS])->save();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.complete', $wo), ['paid_by' => 'owner', 'cost_amount' => 100])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cancel', $wo), ['cancel_reason' => 'oops'])->assertSessionHasErrors('rental_work_order');
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    public function test_emergency_work_the_owner_agreed_by_phone_can_start_without_a_quote(): void
    {
        $fault = $this->approvedFault('agency');
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'Burst geyser', 'description' => 'Flooding'])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $card = $wo->jobCard;
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.assign-crew', $card), ['rental_crew_id' => $this->crew->id])->assertSessionHasNoErrors();

        // Nothing priced, nothing authorised: the job cannot start.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasErrors('rental_job_card');

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.emergency-approval.store', $wo), [
            'approved_by_name' => 'Pieter', 'approved_via' => 'phone', 'approved_at' => now()->subHour()->format('Y-m-d\TH:i'), 'reason' => 'Water everywhere',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasNoErrors();
        $this->assertSame(RentalWorkOrder::STATUS_IN_PROGRESS, $wo->fresh()->status);
        $this->assertSame('in_progress', $this->tenantLine($fault)['current']);
    }

    public function test_extra_work_after_approval_goes_to_the_owner_as_a_variation_and_is_decided_on_the_work_order(): void
    {
        $fault = $this->approvedFault('agency');
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'title' => 'Geyser', 'description' => 'D'])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $card = $wo->jobCard;
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.assign-crew', $card), ['rental_crew_id' => $this->crew->id]);
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.lines.store', $card), ['type' => 'part', 'description' => 'Element', 'quantity' => 1, 'unit_price' => 1000, 'unit_cost' => 600])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->asOwner();
        $this->postJson("/api/v1/client/rentals/landlord/work-orders/{$wo->id}/decision", ['decision' => 'approve'])->assertOk();

        // The agent adds a line beyond what the owner approved: a variation is raised for the owner.
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.lines.store', $card), ['type' => 'part', 'description' => 'Thermostat', 'quantity' => 1, 'unit_price' => 800, 'unit_cost' => 400])->assertSessionHasNoErrors();
        $this->asOwner();
        $d = $this->getJson('/api/v1/client/rentals/landlord/decisions')->assertOk()->json();
        $this->assertCount(1, $d['variations']);
        $this->assertSame($wo->id, $d['variations'][0]['work_order_id'], 'the shell places the card on this work order');
        $vid = $d['variations'][0]['id'];
        $this->postJson("/api/v1/client/rentals/landlord/variations/{$vid}/decision", ['decision' => 'approve', 'revision' => $d['variations'][0]['revision']])->assertOk();
        $this->assertCount(0, $this->getJson('/api/v1/client/rentals/landlord/decisions')->json('variations'));
    }

    public function test_a_disputed_work_order_is_a_command_centre_item_until_it_is_put_right(): void
    {
        $fault = $this->approvedFault('own', ['contractor_name' => 'Bob']);
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'owner_contractor', 'title' => 'Tap', 'description' => 'D', 'contractor_name' => 'Bob'])->assertSessionHasNoErrors();
        $wo = $fault->fresh()->workOrder;
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.start-progress', $wo))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $wo), ['reported_via' => 'phone', 'date_done' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->asTenant();
        $this->postJson("/api/v1/client/rentals/work-orders/{$wo->id}/completion-response", ['fixed' => false, 'note' => 'Still leaking'])->assertOk();

        $this->asGuest();
        $svc = app(\App\Services\Rentals\RentalCommandCentreService::class);
        $this->actingAs($this->admin);
        $items = $svc->queueItems($this->admin, 'all')->filter(fn ($i) => $i['type'] === 'work_order_disputed');
        $this->assertCount(1, $items);
        $this->assertSame($wo->id, $items->first()['route_params']['rentalWorkOrder']);

        // Reported done again -> back in progress -> the item leaves the queue.
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.contractor-done', $wo), ['reported_via' => 'phone', 'date_done' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertCount(0, $svc->queueItems($this->admin, 'all')->filter(fn ($i) => $i['type'] === 'work_order_disputed'));
    }
}
