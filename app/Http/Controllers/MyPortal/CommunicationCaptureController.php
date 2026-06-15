<?php

namespace App\Http\Controllers\MyPortal;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationMailbox;
use Illuminate\Http\Request;
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
