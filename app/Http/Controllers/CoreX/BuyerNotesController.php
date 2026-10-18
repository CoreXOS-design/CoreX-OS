<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Scopes\ContactScope;
use App\Services\Buyers\BuyerNotesAccess;
use Illuminate\Http\Request;

/**
 * Read-only notes fragment for a buyer — the ONE endpoint behind the "Notes" popups on Core Matches and on the
 * property Intelligence tab's Buyer Interest Signals (Johan, 2026-10-07). Who may read is the Role Manager
 * data scope `buyer_notes.view` (own / branch / agency) via BuyerNotesAccess — not the contact's own scope.
 * Direct-URL access is blocked here, not just hidden: a buyer outside the viewer's scope (or another agency's)
 * is a 404. View only: nothing can be added, edited or deleted from this fragment. Not part of the seller link.
 */
class BuyerNotesController extends Controller
{
    public function show(Request $request, int $contactId, BuyerNotesAccess $access)
    {
        $user = $request->user();
        abort_unless($access->canView($user, $contactId), 404);

        $contact = Contact::query()->withoutGlobalScope(ContactScope::class)->findOrFail($contactId);
        $notes = $contact->contactNotes()->with('user')->orderByDesc('created_at')->orderByDesc('id')->get();

        // The "Open full contact record" link only when the viewer may actually open the contact.
        $canOpenContact = $user->hasPermission('access_contacts')
            && Contact::query()->whereKey($contactId)->exists();

        return view('corex.contacts._notes-quick-view', compact('contact', 'notes', 'canOpenContact'));
    }
}
