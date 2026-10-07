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
