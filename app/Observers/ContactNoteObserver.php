<?php

namespace App\Observers;

use App\Models\ContactNote;

/**
 * AT-Core-Matches, Johan's ruling 2 — a note added resets the buyer's
 * working clock, same as a message sent, a live link shared, or Last
 * Contacted being pressed. Applies to notes generally (not scoped to notes
 * added specifically from a future Core Matches screen) — Contact::
 * last_contacted_at is already a general-purpose signal read elsewhere in
 * the app; narrower scoping would need new context-tagging plumbing that's
 * out of scope for this build.
 */
class ContactNoteObserver
{
    public function created(ContactNote $note): void
    {
        $note->contact?->touchLastContacted($note->created_at);
    }
}
