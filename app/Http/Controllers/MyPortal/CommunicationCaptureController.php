<?php

namespace App\Http\Controllers\MyPortal;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationMailbox;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;

/**
 * My Portal → Communication Capture (AT-39, Communication Capture Setup Phase 2).
 * The self-service counterpart to Settings → Email Setup: a user manages THEIR
 * OWN mailbox credentials. Rows created/updated here are stamped set_by=user
 * (vs set_by=agency from the admin surface) — the dual-control provenance the
 * spec calls for.
 *
 * Security: gated by access_communication; a user can only ever touch their own
 * mailboxes (ownership asserted on every write). Same write-only password rule
 * as the agency surface — the password is never rendered back. There is NO
 * reveal here: retrieving a stored password stays the principal-only, audited
 * action on the agency surface.
 */
class CommunicationCaptureController extends Controller
{
    public function index()
    {
        $user = Auth::user()->loadMissing(['commMailboxes' => fn ($q) => $q->orderBy('email_address')]);

        // 2026-09-09 (Johan, auth-lock safeguard) — "Johan must be able to
        // see it before he clicks anything." Same budget visibility as the
        // compliance/settings mailbox screens.
        $hostBreaker = app(\App\Services\Communications\HostCircuitBreaker::class);
        $hostAuthStatus = [];
        foreach ($user->commMailboxes as $m) {
            foreach ($hostBreaker->hostsFor($m) as $host) {
                if (! isset($hostAuthStatus[$host])) {
                    $hostAuthStatus[$host] = [
                        'locked' => $hostBreaker->isAuthLocked($host),
                        'label' => $hostBreaker->authBudgetLabel($host),
                        'count' => $hostBreaker->authFailureCount($host),
                    ];
                }
            }
        }

        return view('my-portal.communication-capture.index', compact('user', 'hostAuthStatus'));
    }

    public function store(Request $request)
    {
        $data = $this->validateMailbox($request, true);

        $mailbox = new CommunicationMailbox();
        $mailbox->agency_id = Auth::user()->effectiveAgencyId();
        $mailbox->user_id   = Auth::id();
        $mailbox->set_by    = 'user';
        $mailbox->auth_type = 'imap';
        $this->fill($mailbox, $data);
        $mailbox->save();

        return back()->with('success', "Mailbox {$mailbox->email_address} linked. Your email will be captured to the archive.");
    }

    public function update(Request $request, CommunicationMailbox $mailbox)
    {
        $this->assertOwn($mailbox);
        $data = $this->validateMailbox($request, false);
        // A user editing their own mailbox re-stamps provenance to 'user' (dual control).
        $mailbox->set_by = 'user';
        $this->fill($mailbox, $data);
        $mailbox->save();

        return back()->with('success', "Mailbox {$mailbox->email_address} updated.");
    }

    public function destroy(CommunicationMailbox $mailbox)
    {
        $this->assertOwn($mailbox);
        $mailbox->delete();

        return back()->with('success', 'Mailbox archived.');
    }

    /**
     * AT-395 (2026-09-07) — same Test Connection action as the compliance and
     * settings/email-setup surfaces, so a mailbox configured for outgoing mail
     * here can be verified the same way. Ownership-asserted like every other
     * write in this controller.
     */
    public function testConnection(
        Request $request,
        CommunicationMailbox $mailbox,
        PerMailboxMailTransportBuilder $transportBuilder,
        ImapSentFolderAppender $appender,
        \App\Services\Communications\MailboxConnectionRateLimiter $rateLimiter,
        \App\Services\Communications\HostCircuitBreaker $hostBreaker
    ) {
        $this->assertOwn($mailbox);

        // 2026-09-08/09 (Johan) — the actual cause of today's Afrihost ban.
        // Checked BEFORE any real connection is made.
        if ($rateLimiter->tooManyAttempts($mailbox)) {
            $message = $rateLimiter->throttledMessage($mailbox);
            return back()
                ->with('test_connection_result', ['smtp' => ['ok' => false, 'message' => $message], 'imap_append' => ['ok' => false, 'message' => $message]])
                ->with('test_connection_mailbox_id', $mailbox->id);
        }

        // 2026-09-09 (Johan, auth-lock safeguard) — see CommunicationMailboxController::testConnection() for the full rationale.
        foreach ($hostBreaker->hostsFor($mailbox) as $lockedHost) {
            if ($hostBreaker->isAuthLocked($lockedHost)) {
                $message = "Blocked — {$lockedHost} has hit our internal login-failure limit ({$hostBreaker->authBudgetLabel($lockedHost)}) and Test Connection is refused to protect the mailbox from being locked out by the mail provider. Confirm the correct credentials, then ask an admin to reset the login lock.";
                return back()
                    ->with('test_connection_result', ['smtp' => ['ok' => false, 'message' => $message], 'imap_append' => ['ok' => false, 'message' => $message]])
                    ->with('test_connection_mailbox_id', $mailbox->id);
            }
        }
        $rateLimiter->hit($mailbox);

        $rawMime = null;
        try {
            $mailable = (new Mailable())
                ->from($mailbox->email_address, $mailbox->smtp_from_name ?: null)
                ->to($mailbox->email_address)
                ->subject('CoreX mailbox test — ' . now()->toDateTimeString())
                ->html('<p>This is a connection test from CoreX. It confirms this mailbox can send outgoing mail. Safe to ignore or delete.</p>');
            $rawMime = $transportBuilder->send($mailbox, $mailable);
            $smtp = ['ok' => true, 'message' => "Connected — test email sent to {$mailbox->email_address}. Check that inbox to confirm it arrived."];
            $mailbox->forceFill([
                'last_sent_at' => now(),
                'last_send_error' => null,
                'last_send_error_at' => null,
                'consecutive_send_failures' => 0,
            ])->save();
        } catch (OutgoingMailboxSendFailedException $e) {
            $smtp = ['ok' => false, 'message' => $e->getMessage()];
            $mailbox->forceFill([
                'last_send_error' => $e->sanitisedReason,
                'last_send_error_at' => now(),
                'last_send_error_detail' => $e->rawDetail,
                'consecutive_send_failures' => (int) $mailbox->consecutive_send_failures + 1,
            ])->save();
            $hostBreaker->recordAuthFailureIfApplicable(strtolower(trim((string) $mailbox->smtp_host)), $e->sanitisedReason);
        }

        $testMime = "Subject: CoreX Sent-folder test\r\nFrom: {$mailbox->email_address}\r\nTo: {$mailbox->email_address}\r\nDate: " . now()->toRfc2822String() . "\r\n\r\nThis is a Sent-folder write test from CoreX.";
        $append = $appender->append($mailbox, $rawMime ?? $testMime);
        $hostBreaker->recordAuthFailureIfApplicable(strtolower(trim((string) $mailbox->imap_host)), $append['reason'] ?? null);
        if ($append['reason'] === 'intercepted') {
            // AT-URGENT-2026-09-08/09 — a deliberate safety skip, not a
            // failure: nothing was attempted, so the mailbox's real
            // append-health fields are left exactly as they were. Wording
            // deliberately doesn't say "non-production" — outbound mail
            // interception can now also be forced on production.
            $imapAppend = ['ok' => true, 'message' => 'Skipped — outbound mail interception is currently on, so CoreX does not write a test message into the real Sent folder here.'];
        } else {
            $imapFailureClassifier = new \App\Services\Communications\MailFailureClassifier();
            $imapAppend = $append['ok']
                ? ['ok' => true, 'message' => 'Sent folder found and writable.']
                : ['ok' => false, 'message' => match ($append['reason']) {
                    'no_sent_folder' => $imapFailureClassifier->friendlyForMissingFolder(),
                    'append_failed' => 'Connected to the Sent folder, but writing to it was refused.',
                    'incomplete_credentials' => 'Mailbox is missing an outgoing host, username or password.',
                    default => $imapFailureClassifier->friendlyForConnect((string) $append['reason'])
                        . ' (the email itself may still have sent — see the SMTP result above).',
                }];
            $mailbox->forceFill($append['ok']
                ? ['last_sent_folder_append_at' => now(), 'last_sent_folder_append_error' => null, 'last_sent_folder_append_error_detail' => null]
                : ['last_sent_folder_append_error' => $append['reason'], 'last_sent_folder_append_error_detail' => $append['detail'] ?? null]
            )->save();
        }

        // 2026-09-08/09 (Johan, back-off on failure) — a human-proven-working
        // IMAP leg resets any back-off/disable state immediately.
        if ($append['ok']) {
            app(\App\Services\Communications\MailboxHealthRecorder::class)->resetBackoffOnManualSuccess($mailbox);
        }

        return back()
            ->with('test_connection_result', ['smtp' => $smtp, 'imap_append' => $imapAppend])
            ->with('test_connection_mailbox_id', $mailbox->id);
    }

    /** A user may only ever manage a mailbox that belongs to them. */
    private function assertOwn(CommunicationMailbox $mailbox): void
    {
        abort_unless((int) $mailbox->user_id === (int) Auth::id(), 403);
    }

    private function validateMailbox(Request $request, bool $creating): array
    {
        return $request->validate([
            'email_address'         => 'required|email|max:255',
            'imap_host'             => 'required|string|max:255',
            'imap_port'             => 'required|integer|min:1|max:65535',
            'username'              => 'required|string|max:255',
            'password'              => ($creating ? 'required' : 'nullable') . '|string|max:1024',
            'poll_inbox'            => 'nullable|boolean',
            'poll_sent'             => 'nullable|boolean',
            'poll_interval_minutes' => 'required|integer|min:1|max:1440',
            'active'                => 'nullable|boolean',
            // AT-395 (2026-09-07) — outgoing fields, self-service surface. Same
            // rules as Settings → Email Setup so a mailbox configured here is
            // just as capable of sending as one configured there.
            'outgoing_enabled'              => 'nullable|boolean',
            'use_imap_credentials_for_smtp' => 'nullable|boolean',
            'smtp_host'                     => 'nullable|required_if:outgoing_enabled,1|string|max:255',
            'smtp_port'                     => 'nullable|integer|min:1|max:65535',
            'smtp_encryption'               => 'nullable|in:tls,ssl,none',
            'smtp_username'                 => 'nullable|string|max:255',
            'smtp_password'                 => 'nullable|string|max:1024',
            'smtp_from_name'                => 'nullable|string|max:255',
            'outgoing_active'               => 'nullable|boolean',
        ]);
    }

    private function fill(CommunicationMailbox $mailbox, array $data): void
    {
        $mailbox->email_address         = trim($data['email_address']);
        $mailbox->imap_host             = trim($data['imap_host']);
        $mailbox->imap_port             = $data['imap_port'];
        $mailbox->username              = trim($data['username']);
        $mailbox->poll_inbox            = (bool) ($data['poll_inbox'] ?? false);
        $mailbox->poll_sent             = (bool) ($data['poll_sent'] ?? false);
        $mailbox->poll_interval_minutes = $data['poll_interval_minutes'];
        $mailbox->active                = (bool) ($data['active'] ?? false);

        // Write-only: only overwrite the stored password when a new one is given.
        // 2026-09-09 (Johan, real-attempt-honesty incident) — was `! empty($data['password'])`,
        // which silently discards a password of exactly "0" (PHP's empty() treats the
        // string "0" as falsy). $data has already passed through Laravel's
        // ConvertEmptyStringsToNull middleware, so a genuinely-blank field arrives as
        // null here — checking for null (not falsiness) is the correct "was a new
        // value actually given" test. Same fix, same reason, as EmailSetupController::fill().
        if (($data['password'] ?? null) !== null) {
            $mailbox->encrypted_password = $data['password'];
        }

        // AT-395 (2026-09-07) — outgoing fields. array_key_exists guard on
        // booleans per BUILD_STANDARD.md — an omitted checkbox never silently
        // coerces to false when this form section wasn't submitted at all.
        if (array_key_exists('outgoing_enabled', $data)) {
            $mailbox->outgoing_enabled = (bool) $data['outgoing_enabled'];
        }
        if (array_key_exists('use_imap_credentials_for_smtp', $data)) {
            $mailbox->use_imap_credentials_for_smtp = (bool) $data['use_imap_credentials_for_smtp'];
        }
        if (array_key_exists('outgoing_active', $data)) {
            $mailbox->outgoing_active = (bool) $data['outgoing_active'];
        }
        if (isset($data['smtp_host'])) {
            $mailbox->smtp_host = $data['smtp_host'];
        }
        if (isset($data['smtp_port'])) {
            $mailbox->smtp_port = $data['smtp_port'];
        }
        if (isset($data['smtp_encryption'])) {
            $mailbox->smtp_encryption = $data['smtp_encryption'];
        }
        if (isset($data['smtp_username'])) {
            $mailbox->smtp_username = $data['smtp_username'];
        }
        if (isset($data['smtp_from_name'])) {
            $mailbox->smtp_from_name = $data['smtp_from_name'];
        }
        // Same empty()-on-"0" gap as the IMAP password above — fixed the same way.
        if (($data['smtp_password'] ?? null) !== null) {
            $mailbox->smtp_encrypted_password = $data['smtp_password'];
        }
    }
}
