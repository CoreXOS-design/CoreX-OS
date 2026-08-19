<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Mail\Signatures\SigningRequestMail;
use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * AT-294 — recipient e-sign emails (invitation + completed doc) recorded their
 * outcome nowhere and swallowed failures (sent_at was even written BEFORE the
 * send, so a failed invitation read "sent"). This pins the honest per-recipient
 * status: a failed send records 'failed' + reason (never a false 'sent'), and a
 * resend re-delivers and flips it back to 'sent'.
 */
final class RecipientEmailSendStatusTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    public function test_failed_invitation_send_records_failed_not_a_false_sent(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        /** @var SignatureRequest $recipient */
        $recipient = $this->recipient($session['recipients'], 'seller', 1);

        // Simulate the mailer failing (SMTP down): Mail::to() throws.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unreachable'));

        app(SignatureService::class)->resendInvitationEmail($recipient);
        $recipient->refresh();

        $this->assertSame('failed', $recipient->invite_send_status, 'a failed send must be recorded, not swallowed');
        $this->assertStringContainsString('SMTP unreachable', (string) $recipient->invite_send_error);
    }

    public function test_resend_invitation_delivers_and_flips_status_to_sent(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 1, includeAgent: false);
        /** @var SignatureRequest $recipient */
        $recipient = $this->recipient($session['recipients'], 'seller', 1);
        $recipient->update(['invite_send_status' => 'failed', 'invite_send_error' => 'prior failure']);

        Mail::fake();

        app(SignatureService::class)->resendInvitationEmail($recipient);
        $recipient->refresh();

        Mail::assertSent(SigningRequestMail::class);
        $this->assertSame('sent', $recipient->invite_send_status);
        $this->assertNull($recipient->invite_send_error, 'a successful resend clears the prior error');
        $this->assertNotNull($recipient->sent_at, 'sent_at is written only after a successful send');
    }
}
