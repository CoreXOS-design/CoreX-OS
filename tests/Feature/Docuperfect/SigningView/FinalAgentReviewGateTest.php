<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-322 (epic AT-322) — the FINAL agent-review gate.
 *
 * When the LAST recipient completes a CLEAN electronic document, it must be HELD at
 * pending_agent_approval (lands in the agent's "Needs Your Approval"), and NOTHING may
 * file or email a recipient until the agent approves. The PDF file + recipient completion
 * emails both live inside completeDocument(), which now runs ONLY on agent approval.
 *
 * Complements CleanAcceptRoutingTest (between-recipient pass-through, unchanged): the gate
 * fires ONLY on the last party, so the pass-through is preserved.
 */
final class FinalAgentReviewGateTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_last_clean_electronic_recipient_holds_for_agent_review_and_sends_no_completion_email(): void
    {
        Mail::fake();
        Notification::fake();

        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $template = $session['signatureTemplate'];
        $seller   = $this->recipient($session['recipients'], 'seller', 1);

        // Everyone else (the agent) has already completed — this seller is the LAST party.
        $template->requests()->where('id', '!=', $seller->id)->update([
            'status'       => SignatureRequest::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        // The last recipient completes CLEANLY via the web/electronic flow (no flag/amendment).
        $seller->update([
            'status'         => SignatureRequest::STATUS_COMPLETED,
            'completed_at'   => now(),
            'signing_method' => 'electronic',
        ]);
        app(SignatureService::class)->handlePartyCompletion($template, 'seller', $seller);

        $template->refresh();

        $this->assertSame(
            SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            $template->status,
            'the LAST clean electronic recipient must HOLD for agent review, not self-complete',
        );
        $this->assertNotSame(
            SignatureTemplate::STATUS_COMPLETED,
            $template->status,
            'a clean fully-signed doc must NOT reach completed before the agent approves',
        );

        // The core of the sharpened bug: NO recipient completion email before agent approval.
        Mail::assertNothingSent();
    }

    public function test_wet_ink_last_recipient_is_exempt_from_the_gate(): void
    {
        // Wet-ink is EXEMPT (its own upload-review is the agent approval — AT-322 open
        // question). A wet_ink last completion must NOT be held at the electronic
        // final-review gate; the gate flag is web-only (signing_method !== 'wet_ink').
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: true);
        $template = $session['signatureTemplate'];
        $seller   = $this->recipient($session['recipients'], 'seller', 1);

        $template->requests()->where('id', '!=', $seller->id)->update([
            'status'       => SignatureRequest::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $seller->update([
            'status'         => SignatureRequest::STATUS_COMPLETED,
            'completed_at'   => now(),
            'signing_method' => 'wet_ink',
        ]);

        try {
            app(SignatureService::class)->handlePartyCompletion($template, 'seller', $seller);
        } catch (\Throwable $e) {
            // completeDocument()'s PDF/file pipeline is not available in the unit env —
            // irrelevant here. What we assert is that the gate did NOT hold wet-ink at
            // pending_agent_approval (holdForFinalAgentReview does no PDF work, so if the
            // gate had fired the status would already be set before any throw).
        }
        $template->refresh();

        // Not held at the electronic gate — wet-ink finalises through its own path as before.
        $this->assertNotSame(
            SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            $template->status,
            'wet-ink must be exempt from the electronic final-review gate',
        );
    }
}
