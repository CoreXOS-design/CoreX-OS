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

        return view('my-portal.communication-capture.index', compact('user'));
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
        ImapSentFolderAppender $appender
    ) {
        $this->assertOwn($mailbox);

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
                'consecutive_send_failures' => (int) $mailbox->consecutive_send_failures + 1,
            ])->save();
        }

        $testMime = "Subject: CoreX Sent-folder test\r\nFrom: {$mailbox->email_address}\r\nTo: {$mailbox->email_address}\r\nDate: " . now()->toRfc2822String() . "\r\n\r\nThis is a Sent-folder write test from CoreX.";
        $append = $appender->append($mailbox, $rawMime ?? $testMime);
        $imapAppend = $append['ok']
            ? ['ok' => true, 'message' => 'Sent folder found and writable.']
            : ['ok' => false, 'message' => match ($append['reason']) {
                'no_sent_folder' => 'Connected, but no Sent folder could be found.',
                'append_failed' => 'Connected to the Sent folder, but writing to it was refused.',
                'auth_failed' => 'Login failed — check the username and password.',
                'incomplete_credentials' => 'Mailbox is missing an outgoing host, username or password.',
                'connect_failed' => 'Could not connect to the mail server to check the Sent folder (the email itself may still have sent — see the SMTP result above).',
                default => 'Could not connect to the mail server.',
            }];
        $mailbox->forceFill($append['ok']
            ? ['last_sent_folder_append_at' => now(), 'last_sent_folder_append_error' => null]
            : ['last_sent_folder_append_error' => $append['reason']]
        )->save();

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
        if (! empty($data['password'])) {
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
        if (! empty($data['smtp_password'])) {
            $mailbox->smtp_encrypted_password = $data['smtp_password'];
        }
    }
}
