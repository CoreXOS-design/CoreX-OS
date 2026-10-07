<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Reconciliation 7 Oct 2026 — the job card's quote box said "The owner has approved this job" for a job that was only COVERED by the
 * owner's no-approval limit (nobody had asked him). The words now follow the approval basis, exactly as the approval panel's chip does.
 */
final class QuoteBoxApprovalWordingTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    public function test_a_job_inside_the_no_approval_limit_is_not_called_approved_by_the_owner(): void
    {
        $this->approvalWorld('Wording');
        [$card] = $this->internalJob(100.0);   // inside the agency's default R500 limit
        $this->sendQuote($card);

        $text = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent());

        $this->assertStringContainsString("Within the owner's no-approval limit — no quote to the owner is needed.", $text);
        $this->assertStringNotContainsString('The owner has approved this job', $text);
    }

    public function test_after_the_owner_approves_a_change_is_never_told_as_a_resend(): void
    {
        // §17.0 item 1 / §17.7.1 — once an amount is approved the quote is not re-sent: extra work is a variation, raised automatically.
        $this->approvalWorld('Changed Since Sent');
        [$card] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok']);
        $this->travel(5)->seconds();
        app(\App\Services\Rentals\RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Extra', 'quantity' => 1, 'unit' => 'each', 'unit_price' => 300], $this->admin);
        $this->assertTrue($card->fresh()->quoteChangedSinceSent(), 'the model fact: the card differs from the quote that was sent');

        $show = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent());
        $this->assertStringNotContainsString('Changed since sent', $show);
        $this->assertStringNotContainsString('Re-send to update the owner', $show);

        $list = $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-quote-changed', $list);
    }

    public function test_a_quote_still_waiting_for_the_owner_is_still_told_as_changed(): void
    {
        $this->approvalWorld('Changed Pending');
        [$card] = $this->internalJob(1000.0);
        $this->sendQuote($card);   // over the limit: waiting for the owner, nothing approved
        $this->travel(5)->seconds();
        app(\App\Services\Rentals\RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Extra', 'quantity' => 1, 'unit' => 'each', 'unit_price' => 300], $this->admin);

        $show = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent());
        $this->assertStringContainsString('Re-send to update the owner', $show);
        $this->assertStringContainsString('data-quote-changed', $this->actingAs($this->admin)->get(route('corex.rental-job-cards.index'))->getContent());
    }

    public function test_a_tenant_who_said_not_complete_is_never_shown_as_having_confirmed_it_fixed(): void
    {
        // §17.10 mirrors the tenant's answer into tenant_confirmed_at / tenant_confirmed_fixed; the card's sign-off area read "confirmed fixed" for ANY answer.
        $this->approvalWorld('Tenant Said No');
        [$card] = $this->internalJob(100.0);
        $card->forceFill(['tenant_confirmed_at' => now(), 'tenant_confirmed_fixed' => false])->save();

        $text = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent());

        $this->assertStringContainsString('Tenant — said the work is NOT complete', $text);
        $this->assertStringNotContainsString('Tenant — confirmed fixed', $text);

        $card->forceFill(['tenant_confirmed_fixed' => true])->save();
        $text = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->getContent());
        $this->assertStringContainsString('Tenant — confirmed fixed', $text);
    }

    public function test_a_job_the_owner_really_approved_still_says_so(): void
    {
        $this->approvalWorld('Wording Two');
        [$card] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok']);

        $text = $this->visibleText($this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent());

        $this->assertStringContainsString('The owner has approved this job.', $text);
    }
}
