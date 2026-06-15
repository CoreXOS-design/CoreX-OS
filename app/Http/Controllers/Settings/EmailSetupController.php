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
    }
}
