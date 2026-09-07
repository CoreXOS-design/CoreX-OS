<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationMailbox;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mailbox config for the email adapter (AT-33; outgoing SMTP added AT-395
 * Phase A). Agency-held IMAP credentials; the password is encrypted at rest
 * via the model's 'encrypted' cast and never rendered back. Gated by
 * manage_communication_mailboxes.
 *
 * AT-395 §7.2/§7.3 — list search/sort/filter/pagination and OWN/BRANCH/AGENCY
 * scoping via CommunicationMailbox::scopeVisibleTo(), spec-exact.
 */
class CommunicationMailboxController extends Controller
{
    private const SORT_COLUMNS = ['email_address', 'last_polled_at', 'last_sent_at', 'consecutive_failures', 'consecutive_send_failures'];
    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $query = CommunicationMailbox::query()->visibleTo(Auth::user())->with('user');

        // Search — email_address or linked user's name (spec §7.2).
        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('email_address', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        // Filters (spec §7.2).
        if ($request->filled('status')) {
            $query->where('active', $request->query('status') === 'active');
        }
        if ($request->filled('outgoing')) {
            $query->where('outgoing_enabled', $request->query('outgoing') === 'yes');
        }
        $pollHealthFilter = $request->query('poll_health');
        $sendHealthFilter = $request->query('send_health');

        // Sort — default email_address ascending, per spec §7.2 (unchanged default).
        $sort = in_array($request->query('sort'), self::SORT_COLUMNS, true) ? $request->query('sort') : 'email_address';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sort, $direction);

        $mailboxes = $query->paginate(self::PER_PAGE)->withQueryString();

        // Health-state filters are derived (not stored columns), applied after
        // pagination would break counts — so filter the in-memory page only
        // when requested. Rare filter combo; acceptable at this row scale
        // (mirrors the existing 20-row list, never an unbounded set).
        if ($pollHealthFilter) {
            $mailboxes->setCollection($mailboxes->getCollection()->filter(fn ($m) => $m->pollHealth() === $pollHealthFilter)->values());
        }
        if ($sendHealthFilter) {
            $mailboxes->setCollection($mailboxes->getCollection()->filter(fn ($m) => $m->sendHealth() === $sendHealthFilter)->values());
        }

        return view('compliance.communication-archive.mailboxes.index', [
            'mailboxes' => $mailboxes,
            'filters' => $request->only(['q', 'status', 'outgoing', 'poll_health', 'send_health', 'sort', 'direction']),
        ]);
    }

    public function create()
    {
        return view('compliance.communication-archive.mailboxes.form', ['mailbox' => new CommunicationMailbox()]);
    }

    public function store(Request $request)
    {
        $data = $this->validateMailbox($request, true);

        $mailbox = new CommunicationMailbox();
        $mailbox->agency_id = Auth::user()->effectiveAgencyId();
        $this->fill($mailbox, $data);
        $mailbox->save();

        return redirect()->route('compliance.comm-mailboxes.index')
            ->with('success', "Mailbox {$mailbox->email_address} added.");
    }

    public function edit(CommunicationMailbox $mailbox)
    {
        abort_unless(
            CommunicationMailbox::query()->visibleTo(Auth::user())->whereKey($mailbox->id)->exists(),
            404
        );

        return view('compliance.communication-archive.mailboxes.form', compact('mailbox'));
    }

    public function update(Request $request, CommunicationMailbox $mailbox)
    {
        abort_unless(
            CommunicationMailbox::query()->visibleTo(Auth::user())->whereKey($mailbox->id)->exists(),
            404
        );

        $data = $this->validateMailbox($request, false);
        $this->fill($mailbox, $data);
        $mailbox->save();

        return redirect()->route('compliance.comm-mailboxes.index')
            ->with('success', "Mailbox {$mailbox->email_address} updated.");
    }

    public function destroy(CommunicationMailbox $mailbox)
    {
        abort_unless(
            CommunicationMailbox::query()->visibleTo(Auth::user())->whereKey($mailbox->id)->exists(),
            404
        );

        $mailbox->delete(); // soft

        return redirect()->route('compliance.comm-mailboxes.index')
            ->with('success', 'Mailbox archived.');
    }

    /** AT-395 §7.1 — restore from archive. Never a hard delete anywhere in CoreX. */
    public function restore(int $mailbox)
    {
        $model = CommunicationMailbox::onlyTrashed()
            ->visibleTo(Auth::user())
            ->findOrFail($mailbox);

        $model->restore();

        return redirect()->route('compliance.comm-mailboxes.index')
            ->with('success', "Mailbox {$model->email_address} restored.");
    }

    /**
     * AT-395 §6 — Test Connection, both legs independently reported. Never
     * touches a real recipient outside the mailbox's own address.
     */
    public function testConnection(
        Request $request,
        CommunicationMailbox $mailbox,
        PerMailboxMailTransportBuilder $transportBuilder,
        ImapSentFolderAppender $appender
    ) {
        abort_unless(
            CommunicationMailbox::query()->visibleTo(Auth::user())->whereKey($mailbox->id)->exists(),
            404
        );

        $result = ['smtp' => ['ok' => false, 'message' => null], 'imap_append' => ['ok' => false, 'message' => null]];

        // Leg 1 — SMTP send, to the mailbox's own address only.
        $rawMime = null;
        try {
            $mailable = (new \Illuminate\Mail\Mailable())
                ->from($mailbox->email_address, $mailbox->smtp_from_name ?: null)
                ->to($mailbox->email_address)
                ->subject('CoreX mailbox test — ' . now()->toDateTimeString())
                ->html('<p>This is a connection test from CoreX. It confirms this mailbox can send outgoing mail. Safe to ignore or delete.</p>');
            $rawMime = $transportBuilder->send($mailbox, $mailable);
            $result['smtp'] = ['ok' => true, 'message' => "Connected — test email sent to {$mailbox->email_address}. Check that inbox to confirm it arrived."];
            // AT-395 fix — Test Connection previously never touched the health
            // fields at all, so the list's persistent health badge could show a
            // failure forever after one, even once sending was fixed and every
            // later attempt succeeded. Mirror dispatchSigningMail()'s bookkeeping.
            $mailbox->forceFill([
                'last_sent_at' => now(),
                'last_send_error' => null,
                'last_send_error_at' => null,
                'consecutive_send_failures' => 0,
            ])->save();
        } catch (\App\Exceptions\Communications\OutgoingMailboxSendFailedException $e) {
            $result['smtp'] = ['ok' => false, 'message' => $e->getMessage()];
            $mailbox->forceFill([
                'last_send_error' => $e->sanitisedReason,
                'last_send_error_at' => now(),
                'consecutive_send_failures' => (int) $mailbox->consecutive_send_failures + 1,
            ])->save();
        }

        // Leg 2 — IMAP Sent-folder append, a distinct synthetic test message
        // (independent of whether leg 1 succeeded, per spec §6).
        $testMime = "Subject: CoreX Sent-folder test\r\nFrom: {$mailbox->email_address}\r\nTo: {$mailbox->email_address}\r\nDate: " . now()->toRfc2822String() . "\r\n\r\nThis is a Sent-folder write test from CoreX.";
        $append = $appender->append($mailbox, $rawMime ?? $testMime);
        $result['imap_append'] = $append['ok']
            ? ['ok' => true, 'message' => 'Sent folder found and writable.']
            : ['ok' => false, 'message' => match ($append['reason']) {
                'no_sent_folder' => 'Connected, but no Sent folder could be found.',
                'append_failed' => 'Connected to the Sent folder, but writing to it was refused.',
                'auth_failed' => 'Login failed — check the username and password.',
                'incomplete_credentials' => 'Mailbox is missing an outgoing host, username or password.',
                // AT-395 fix — 'connect_failed' previously fell through to the
                // same wording as "nothing worked at all", masking a working
                // SMTP send behind a confusing IMAP-leg-specific connect issue.
                'connect_failed' => 'Could not connect to the mail server to check the Sent folder (the email itself may still have sent — see the SMTP result above).',
                default => 'Could not connect to the mail server.',
            }];
        $mailbox->forceFill($append['ok']
            ? ['last_sent_folder_append_at' => now(), 'last_sent_folder_append_error' => null]
            : ['last_sent_folder_append_error' => $append['reason']]
        )->save();

        return back()->with('test_connection_result', $result);
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
            // AT-395 §2 — outgoing fields.
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
        $mailbox->email_address         = $data['email_address'];
        $mailbox->imap_host             = $data['imap_host'];
        $mailbox->imap_port             = $data['imap_port'];
        $mailbox->username              = $data['username'];
        $mailbox->poll_inbox            = (bool) ($data['poll_inbox'] ?? false);
        $mailbox->poll_sent             = (bool) ($data['poll_sent'] ?? false);
        $mailbox->poll_interval_minutes = $data['poll_interval_minutes'];
        $mailbox->active                = (bool) ($data['active'] ?? false);

        // Only overwrite the stored password when a new one is supplied.
        if (! empty($data['password'])) {
            $mailbox->encrypted_password = $data['password'];
        }

        // AT-395 §2 — outgoing fields. Guarded with $request->has()-equivalent
        // (array_key_exists) per BUILD_STANDARD.md's absent-checkbox rule —
        // an omitted boolean must never silently coerce to false when this
        // form section wasn't rendered/submitted at all.
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
