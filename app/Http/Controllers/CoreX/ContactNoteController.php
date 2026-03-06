<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactNote;
use Illuminate\Http\Request;

class ContactNoteController extends Controller
{
    public function store(Request $request, Contact $contact)
    {
        $request->validate(['body' => 'required|string|max:5000']);

        $contact->contactNotes()->create([
            'user_id' => auth()->id(),
            'body'    => $request->body,
        ]);

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Note added.')
            ->withFragment('tab-notes');
    }

    public function destroy(Contact $contact, ContactNote $note)
    {
        abort_unless($note->contact_id === $contact->id, 404);

        $note->delete();

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Note deleted.')
            ->withFragment('tab-notes');
    }
}
