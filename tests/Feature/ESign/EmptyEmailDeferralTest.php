<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Mail\Signatures\SigningRequestMail;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-294 — empty-email recipient dead-end.
 *
 * Pre-fix: a recipient with no email hit Mail::to('') in sendSigningRequest(),
 * which threw and was swallowed — the ceremony parked as a healthy-looking
 * awaiting_* with no link and no agent-visible error (silent dead-end).
 *
 * Fix (ABSORB): sendSigningRequest() routes an email-less recipient into the
 * EXISTING deferred machinery (request → DEFERRED, template → AWAITING_DEFERRED)
 * — a visible, recoverable state; the token (minted at creation) survives, and
 * the agent resumes via resumeDeferredSigning() once they add an email.
 */
final class EmptyEmailDeferralTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_empty_email_send_parks_deferred_not_dead_end(): void
    {
        Mail::fake();
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $seller1->update(['signer_email' => '', 'status' => SignatureRequest::STATUS_WAITING]);

        app(SignatureService::class)->sendSigningRequest($seller1->fresh());

        $seller1->refresh();
        $this->assertSame(SignatureRequest::STATUS_DEFERRED, $seller1->status, 'email-less recipient parks DEFERRED');
        $this->assertSame(
            SignatureTemplate::STATUS_AWAITING_DEFERRED,
            $session['signatureTemplate']->fresh()->status,
            'template parks in the visible AWAITING_DEFERRED recovery bucket',
        );
        $this->assertNotEmpty($seller1->token, 'the signing token is preserved — nothing lost');
        Mail::assertNothingSent();
    }

    public function test_recipient_with_email_still_sends_normally(): void
    {
        Mail::fake();
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $seller1->update(['signer_email' => 'thandeka@example.test', 'status' => SignatureRequest::STATUS_WAITING]);

        app(SignatureService::class)->sendSigningRequest($seller1->fresh());

        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller1->fresh()->status);
        Mail::assertSent(SigningRequestMail::class);
    }

    public function test_resume_deferred_with_email_re_enters_the_flow(): void
    {
        Mail::fake();
        $session  = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        $template = $session['signatureTemplate'];
        $seller1  = $this->recipient($session['recipients'], 'seller', 1);
        $seller1->update(['signer_email' => '', 'status' => SignatureRequest::STATUS_WAITING]);

        // Park it deferred (the empty-email absorb).
        app(SignatureService::class)->sendSigningRequest($seller1->fresh());
        $this->assertSame(SignatureRequest::STATUS_DEFERRED, $seller1->fresh()->status);

        // Agent adds the email and resumes — the flow picks it back up.
        app(SignatureService::class)->resumeDeferredSigning(
            $template->fresh(),
            $seller1->fresh(),
            'Thandeka Zulu',
            'thandeka@example.test',
        );

        $seller1->refresh();
        $this->assertSame('thandeka@example.test', $seller1->signer_email);
        $this->assertNotSame(SignatureRequest::STATUS_DEFERRED, $seller1->status, 'no longer deferred after resume');
        Mail::assertSent(SigningRequestMail::class);
    }
}
