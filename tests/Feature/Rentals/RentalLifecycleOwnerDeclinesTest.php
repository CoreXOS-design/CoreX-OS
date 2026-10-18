<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Mail\Rentals\RentalTenantStatusChangeMail;
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Rentals\Concerns\DrivesRentalLifecycle;
use Tests\TestCase;

/**
 * THE RENTAL LIFE, NEGATIVE CHAIN - the owner declines the repair, with a reason. Same chain up to the owner's decision; the
 * tenant must then see the NEUTRAL line only ("Not approved - your agent will contact you"): never the reason, never the agent's
 * note, never the word "declined", on the portal, in the mail or on the progress line. The agent still sees the reason, can no
 * longer raise a work order, and the owner's decision cannot be changed. See RentalLifecycleEndToEndTest for the ledger.
 */
final class RentalLifecycleOwnerDeclinesTest extends TestCase
{
    use DrivesRentalLifecycle;
    use RefreshDatabase;

    private const REASON = 'SECRET reason: too expensive this year';
    private const NEUTRAL = 'Not approved — your agent will contact you';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildLifecycleWorld();
    }

    public function test_the_owner_declines_with_a_reason_and_the_tenant_sees_only_the_neutral_line(): void
    {
        $this->driveToActiveLeaseWithInspection();

        $this->step('8.1', 'the owner declines on the Faults screen - a reason is required', 'cc6', function () {
            $this->asPortal($this->landlord);
            $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$this->fault->id}/decision", ['decision' => 'decline', 'note' => ''], ['X-Submission-Key' => 'e2e-' . uniqid()])->assertStatus(422);
            $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$this->fault->id}/decision", ['decision' => 'decline', 'note' => self::REASON], ['X-Submission-Key' => 'e2e-' . uniqid()])->assertOk();
            $this->assertSame(RentalFaultReport::STATUS_DECLINED, $this->fault->fresh()->status);
            $this->assertSame('declined', RentalApproval::where('rental_fault_report_id', $this->fault->id)->sole()->decision);
        }, ['7.4']);

        $this->step('8.2', 'the tenant is mailed the neutral line - no reason, no "declined"', 'cc6', function () {
            Mail::assertQueued(RentalTenantStatusChangeMail::class, function ($m) {
                $html = $m->render();

                return $m->hasTo(self::TENANT_EMAIL) && $m->stepLabel === self::NEUTRAL && ! str_contains($html, 'SECRET') && ! stripos($html, 'declined');
            });
        }, ['8.1']);

        $this->step('8.3', 'the tenant\'s progress line is the neutral one and stops at the owner\'s step', 'cc6', function () {
            $line = $this->progress('tenant');
            $this->assertSame('not_approved', $line['ended']);
            $this->assertSame(self::NEUTRAL, $line['current_label']);
            $this->assertSame(['sent_to_agent', 'agent_reviewing', 'sent_to_owner', 'owner_decided'], array_column($line['steps'], 'key'));
        }, ['8.1']);

        $this->step('8.4', 'the tenant portal shows the neutral line and neither the reason nor the agent\'s note', 'cc6', function () {
            $this->asPortal($this->tenant);
            $body = $this->portalGet("/fault-reports/{$this->fault->id}")->assertOk()->getContent();
            $this->assertStringContainsString('Not approved', $body);
            $this->assertStringNotContainsString('SECRET', $body);
            $this->assertStringNotContainsString('I recommend repair', $body);
            $this->assertSame('not_approved', $this->portalGet("/fault-reports/{$this->fault->id}")->json('fault_report.progress.ended'));
        }, ['8.1']);

        $this->step('8.5', 'the tenant portal does not say "declined" anywhere (the raw status must not leak next to the neutral line)', 'cc6', function () {
            $this->asPortal($this->tenant);
            foreach (["/fault-reports/{$this->fault->id}", '/fault-reports'] as $uri) {
                $this->assertStringNotContainsString('declined', strtolower($this->portalGet($uri)->assertOk()->getContent()), "tenant {$uri} carries the word 'declined'");
            }
        }, ['8.1']);

        $this->step('8.6', 'the owner sees their own decision ("You declined") and cannot change it', 'cc6', function () {
            $this->asPortal($this->landlord);
            $detail = $this->portalGet("/landlord/fault-reports/{$this->fault->id}")->assertOk();
            $this->assertStringContainsString('You declined', json_encode($detail->json('fault_report.progress')));
            $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$this->fault->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency'], ['X-Submission-Key' => 'e2e-' . uniqid()])->assertStatus(422);
            $this->assertSame(RentalFaultReport::STATUS_DECLINED, $this->fault->fresh()->status);
        }, ['8.1']);

        $this->step('8.7', 'the agent sees the reason read-only and can no longer raise a work order', 'cc6', function () {
            $this->asStaff();
            $this->get(route('corex.rental-fault-reports.show', $this->fault))->assertOk()->assertSee('SECRET reason');
            $this->post(route('corex.rental-fault-reports.raise-work-order', $this->fault), ['assignment_type' => 'internal', 'title' => 'Fix', 'description' => 'x'])->assertSessionHasErrors('rental_fault_report');
            $this->assertNull($this->fault->fresh()->workOrder);
        }, ['8.1']);

        $this->step('8.8', 'the agent records the outcome "owner declined" and the fault is closed without a work order', 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.outcome.store', $this->fault), ['outcome' => 'owner_declined', 'outcome_note' => 'Owner chose not to repair.'])->assertSessionHasNoErrors();
            $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
            $this->asPortal($this->tenant);
            $this->assertStringNotContainsString('Owner chose not to repair', $this->portalGet("/fault-reports/{$this->fault->id}")->getContent(), 'the outcome note is the agent\'s, not the tenant\'s');
        }, ['8.1']);

        $this->finishLifecycle('negative-owner-declines');
    }
}
