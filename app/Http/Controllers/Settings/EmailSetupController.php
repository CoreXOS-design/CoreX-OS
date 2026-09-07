<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Communications\MailboxCredentialReveal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Settings → Email Setup (AT-37, Communication Capture Setup Phase 1). The
 * agency's per-user IMAP capture control centre: an admin links each user's
 * mailbox credentials so their email feeds the Communication Archive.
 *
 * Security model (spec §2): the stored password is WRITE-ONLY. It is encrypted
 * at rest (model 'encrypted' cast) and never rendered or returned by any of the
 * list/edit paths here — the password input always posts a new value or is left
 * blank to keep the current one. The ONLY read path is reveal(), which is gated
 * by the separate principal-only `reveal_mailbox_credential` permission and
 * writes an audit row on every use.
 *
 * Management actions (index/store/update/destroy) gate on
 * manage_communication_mailboxes via the route group; reveal() gates on
 * reveal_mailbox_credential.
 */
class EmailSetupController extends Controller
{
    /** A user list, each with their linked capture mailboxes. */
    public function index()
    {
        $agencyId = Auth::user()->effectiveAgencyId();

        $users = User::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->where('is_active', true)
            ->with(['commMailboxes' => fn ($q) => $q->orderBy('email_address')])
            ->orderBy('name')
            ->get();

        return view('settings.email-setup.index', compact('users'));
    }

    /** Create a capture mailbox for a specific user (set_by = agency). */
    public function store(Request $request, User $user)
    {
        $this->assertSameAgency($user);
        $data = $this->validateMailbox($request, true);

        $mailbox = new CommunicationMailbox();
        $mailbox->agency_id = Auth::user()->effectiveAgencyId();
        $mailbox->user_id   = $user->id;
        $mailbox->set_by    = 'agency';
        $mailbox->auth_type = 'imap';
        $this->fill($mailbox, $data);
        $mailbox->save();

        return back()->with('success', "Capture mailbox {$mailbox->email_address} linked to {$user->name}.");
    }

    /** Update an existing mailbox. Password overwritten only when supplied. */
    public function update(Request $request, CommunicationMailbox $mailbox)
    {
        $data = $this->validateMailbox($request, false);
        $this->fill($mailbox, $data);
        $mailbox->save();

        return back()->with('success', "Mailbox {$mailbox->email_address} updated.");
    }

    /** Archive (soft-delete) a mailbox. */
    public function destroy(CommunicationMailbox $mailbox)
    {
        $mailbox->delete();

        return back()->with('success', 'Capture mailbox archived.');
    }

    /**
     * AT-395 §7.6 — Agency Onboarding Setup Wizard saver. Creates or updates
     * the CURRENT ADMIN's own mailbox with the outgoing fields. Skippable —
     * the wizard step's own 'savers' dispatch only calls this when the step
     * was actually submitted (not skipped), and this no-ops gracefully if
     * the step was submitted with nothing filled in (a subset-of-fields post
     * per BUILD_STANDARD §6.1 — never coerce an absent field to a destructive
     * value on an EXISTING row; only apply defaults when creating a fresh one).
     */
    public function onboardingSaveOutgoing(Request $request, \App\Models\Agency $agency): void
    {
        if (! $request->filled('email_address')) {
            return; // nothing entered — treat exactly like a skip, write nothing.
        }

        $user = Auth::user();
        $mailbox = CommunicationMailbox::where('agency_id', $agency->id)->where('user_id', $user->id)->first();
        $creating = ! $mailbox;
        $mailbox ??= new CommunicationMailbox([
            'agency_id' => $agency->id,
            'user_id' => $user->id,
            'set_by' => 'user',
            'auth_type' => 'imap',
        ]);

        $mailbox->email_address = trim((string) $request->input('email_address'));
        if ($creating) {
            // Sensible defaults for a fresh row (BUILD_STANDARD §2 — every
            // NOT-NULL column gets a value): this step only asks about
            // outgoing mail, so a brand-new mailbox reuses the same host for
            // IMAP (the common cPanel/Afrihost-style case) rather than
            // leaving imap_host unset.
            $mailbox->imap_host = trim((string) $request->input('smtp_host', ''));
            $mailbox->imap_port = 993;
            $mailbox->username = trim((string) $request->input('username', $mailbox->email_address));
            $mailbox->poll_interval_minutes = 15;
            $mailbox->active = true;
            if ($request->filled('password')) {
                $mailbox->encrypted_password = $request->input('password');
            }
        }

        $mailbox->outgoing_enabled = $request->boolean('outgoing_enabled');
        $mailbox->use_imap_credentials_for_smtp = true;
        $mailbox->smtp_host = trim((string) $request->input('smtp_host', ''));
        $mailbox->smtp_port = (int) $request->input('smtp_port', 587);
        $mailbox->smtp_encryption = $request->input('smtp_encryption', 'tls');
        $mailbox->outgoing_active = true;
        $mailbox->save();
    }

    /** AT-395 §7.1 — restore from archive. Never a hard delete anywhere in CoreX. */
    public function restore(int $mailbox)
    {
        $model = CommunicationMailbox::onlyTrashed()->findOrFail($mailbox);
        $this->assertSameAgency($model->user ?? Auth::user());
        $model->restore();

        return back()->with('success', "Mailbox {$model->email_address} restored.");
    }

    /** AT-395 §6 — Test Connection, both legs independently reported. */
    public function testConnection(
        CommunicationMailbox $mailbox,
        \App\Services\Communications\PerMailboxMailTransportBuilder $transportBuilder,
        \App\Services\Communications\ImapSentFolderAppender $appender
    ) {
        $rawMime = null;
        try {
            $mailable = (new \Illuminate\Mail\Mailable())
                ->from($mailbox->email_address, $mailbox->smtp_from_name ?: null)
                ->to($mailbox->email_address)
                ->subject('CoreX mailbox test — ' . now()->toDateTimeString())
                ->html('<p>This is a connection test from CoreX. It confirms this mailbox can send outgoing mail. Safe to ignore or delete.</p>');
            $rawMime = $transportBuilder->send($mailbox, $mailable);
            $smtp = ['ok' => true, 'message' => "Connected — test email sent to {$mailbox->email_address}. Check that inbox to confirm it arrived."];
        } catch (\App\Exceptions\Communications\OutgoingMailboxSendFailedException $e) {
            $smtp = ['ok' => false, 'message' => $e->getMessage()];
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
                default => 'Could not connect to the mail server.',
            }];

        return back()->with('test_connection_result', ['smtp' => $smtp, 'imap_append' => $imapAppend]);
    }

    /**
     * The one sanctioned read of a stored password. Gated by the principal-only
     * reveal_mailbox_credential permission (route middleware + this defensive
     * check), audited on every call, and shown exactly once via flash.
     */
    public function reveal(Request $request, CommunicationMailbox $mailbox)
    {
        abort_unless($request->user()->hasPermission('reveal_mailbox_credential'), 403);

        // Decrypted by the model cast — read server-side only, never serialised.
        $password = $mailbox->encrypted_password;

        MailboxCredentialReveal::create([
            'agency_id'            => Auth::user()->effectiveAgencyId(),
            'mailbox_id'           => $mailbox->id,
            'revealed_by'          => $request->user()->id,
            'revealed_for_user_id' => $mailbox->user_id,
            'revealed_at'          => now(),
            'ip_address'           => $request->ip(),
        ]);

        return back()
            ->with('revealed_mailbox_id', $mailbox->id)
            ->with('revealed_password', $password);
    }

    /** Mailboxes are agency-scoped; users must be too before we link them. */
    private function assertSameAgency(User $user): void
    {
        abort_unless((int) $user->agency_id === (int) Auth::user()->effectiveAgencyId(), 404);
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
            // AT-395 §2 — outgoing fields, self-service surface.
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

        // AT-395 §2 — outgoing fields. array_key_exists guard on booleans per
        // BUILD_STANDARD.md — an omitted checkbox never silently coerces to
        // false when this form section wasn't submitted at all.
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
