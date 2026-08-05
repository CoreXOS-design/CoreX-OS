<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactNote;
use Illuminate\Http\Request;

class ContactNoteController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesContactAccess;

    public function store(Request $request, Contact $contact)
    {
        // AT-267 — assistants may VIEW a colleague's contact but only EDIT the agent's own.
        $this->authorizeContact($contact);

        // AT-372 — mark_contacted (from the Last Contacted tile "+ Contacted & note" modal
        // OR the Notes "Add note & mark contacted" button) writes the note AND records an
        // explicit contacted signal in ONE step. redirect_to lets the tile return to the
        // info screen (so the updated tile is visible) while Notes stays on the notes tab.
        $request->validate([
            'body'           => 'required|string|max:5000',
            'mark_contacted' => 'nullable|boolean',
            'redirect_to'    => 'nullable|in:info,notes',
        ]);

        $markContacted = $request->boolean('mark_contacted');

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $contact, $markContacted) {
            $contact->contactNotes()->create([
                'user_id' => auth()->id(),
                'body'    => $request->body,
            ]);

            // Same first-class contacted signal the tile buttons use — one path, no
            // parallel systems; the Last Contacted tile reflects it on next load.
            if ($markContacted) {
                $contact->markContacted();
            }
        });

        $tab = $request->input('redirect_to') === 'info' ? 'info' : 'notes';

        return redirect()->route('corex.contacts.show', ['contact' => $contact, 'tab' => $tab])
            ->with('success', $markContacted ? 'Note saved and contact marked as contacted.' : 'Note added.');
    }

    public function destroy(Contact $contact, ContactNote $note)
    {
        // AT-267 — same edit-permission gate as store().
        $this->authorizeContact($contact);
        abort_unless($note->contact_id === $contact->id, 404);

        $note->delete();

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Note deleted.')
            ->withFragment('tab-notes');
    }
}
