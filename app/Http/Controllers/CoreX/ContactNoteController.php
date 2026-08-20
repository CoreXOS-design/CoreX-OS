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
        //
        // Buyer pipeline notes (Johan, 2026-08-20) — "dropdown quick picks
        // and free text", neither mandatory-with-the-other: a bare
        // quick-pick with no body must save ("Contacted", one click), and
        // free text with no type must also save. The only thing NOT valid
        // is both absent — required_without on each covers that without
        // forcing either one specifically. type is validated against the
        // exact allowed list — anything else is rejected, never silently
        // stored. redirect_to gained 'buyer-notes' so a note written from
        // the buyer pipeline detail page returns there, not to the contact
        // page.
        $request->validate([
            'type'           => ['nullable', 'required_without:body', 'string', \Illuminate\Validation\Rule::in(ContactNote::QUICK_PICK_TYPES)],
            'body'           => 'nullable|required_without:type|string|max:5000',
            'mark_contacted' => 'nullable|boolean',
            'redirect_to'    => 'nullable|in:info,notes,buyer-notes',
        ]);

        $markContacted = $request->boolean('mark_contacted');

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $contact, $markContacted) {
            $contact->contactNotes()->create([
                'user_id' => auth()->id(),
                'type'    => $request->input('type'),
                // 2026-08-20 (Johan, live-hit on staging, real error) — was
                // $request->input('body', ''). That default only fires when
                // the key is ABSENT; Laravel's stock ConvertEmptyStringsToNull
                // middleware turns an empty <textarea name="body"> into
                // body=null BEFORE this runs, so the key is PRESENT (as
                // null), input()'s default never applies, and null hit the
                // NOT NULL column directly: SQLSTATE[23000] "Column 'body'
                // cannot be null". ?? '' catches both shapes — absent, and
                // present-but-null — the earlier direct-invocation test only
                // ever exercised "absent" (an array literal that omitted the
                // key), not a real browser's always-present empty field.
                'body'    => $request->input('body') ?? '',
            ]);

            // Same first-class contacted signal the tile buttons use — one path, no
            // parallel systems; the Last Contacted tile reflects it on next load.
            if ($markContacted) {
                $contact->markContacted();
            }
        });

        $successMessage = $markContacted ? 'Note saved and contact marked as contacted.' : 'Note added.';

        if ($request->input('redirect_to') === 'buyer-notes') {
            return redirect()->route('command-center.buyers.show', ['contact' => $contact, 'tab' => 'notes'])
                ->with('success', $successMessage);
        }

        $tab = $request->input('redirect_to') === 'info' ? 'info' : 'notes';

        return redirect()->route('corex.contacts.show', ['contact' => $contact, 'tab' => $tab])
            ->with('success', $successMessage);
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
