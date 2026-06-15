<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Communications\CommunicationMailbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mailbox config for the email adapter (AT-33). Agency-held IMAP credentials;
 * the password is encrypted at rest via the model's 'encrypted' cast and never
 * rendered back. Gated by manage_compliance.
 */
class CommunicationMailboxController extends Controller
{
    public function index()
    {
        $mailboxes = CommunicationMailbox::orderBy('email_address')->get();

        return view('compliance.communication-archive.mailboxes.index', compact('mailboxes'));
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
        return view('compliance.communication-archive.mailboxes.form', compact('mailbox'));
    }

    public function update(Request $request, CommunicationMailbox $mailbox)
    {
        $data = $this->validateMailbox($request, false);
        $this->fill($mailbox, $data);
        $mailbox->save();

        return redirect()->route('compliance.comm-mailboxes.index')
            ->with('success', "Mailbox {$mailbox->email_address} updated.");
    }

    public function destroy(CommunicationMailbox $mailbox)
    {
        $mailbox->delete(); // soft

        return redirect()->route('compliance.comm-mailboxes.index')
            ->with('success', 'Mailbox archived.');
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
    }
}
