<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\Communications\CommunicationTriageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Staff pending-triage screen (AT-36, addendum §6). Lists THIS agent's pending
 * items within the grace window; "Add contact" archives + flags real_estate,
 * "Not real estate" discards per-agent. The agent is never blocked.
 */
class CommunicationTriageController extends Controller
{
    public function __construct(private CommunicationTriageService $triage)
    {
    }

    public function index()
    {
        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 403, 'No agency context.');

        $items = $this->triage->pendingForAgent($agencyId, $user->id);

        return view('communications.triage.index', ['items' => $items]);
    }

    /**
     * Positive triage: create the contact (reuses the standard contact-create
     * path), retroactively attach the identifier's pending messages to the
     * archive, and record a real_estate flag (+ agent_vs_agent contradiction).
     */
    public function addContact(Request $request)
    {
        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 403);

        $validated = $request->validate([
            'identifier'          => 'required|string|max:255',
            'first_name'          => 'required|string|max:120',
            'last_name'           => 'nullable|string|max:120',
            'phone'               => 'nullable|string|max:50',
            'email'               => 'nullable|email|max:255',
            'message_external_id' => 'nullable|string|max:255',
        ]);

        if (empty($validated['phone']) && empty($validated['email'])) {
            throw ValidationException::withMessages(['phone' => 'A phone or email is required to add the contact.']);
        }

        // Reuse the standard contact-creation path (agency_id auto-filled by
        // BelongsToAgency from the acting user).
        $contact = Contact::create([
            'agency_id'  => $agencyId,
            'first_name' => $validated['first_name'],
            'last_name'  => $validated['last_name'] ?? '',
            'phone'      => $validated['phone'] ?? '',
            'email'      => $validated['email'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        $result = $this->triage->flagRealEstateAndAttach(
            $agencyId,
            $user,
            $contact,
            $validated['identifier'],
            trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? '')) ?: null,
            $validated['message_external_id'] ?? null,
        );

        $msg = "Contact added. {$result['attached']} message(s) archived.";
        if ($result['alerts'] > 0) {
            $msg .= " Note: this contradicted {$result['alerts']} earlier 'not real estate' flag — a review alert was raised.";
        }

        return back()->with('success', $msg);
    }

    /**
     * Negative triage: discard for THIS agent only (per-agent flag). The agent's
     * call stands; nothing is blocked.
     */
    public function notRealEstate(Request $request)
    {
        $user = Auth::user();
        $agencyId = $user->effectiveAgencyId();
        abort_unless($agencyId, 403);

        $validated = $request->validate([
            'identifier'          => 'required|string|max:255',
            'identifier_name'     => 'nullable|string|max:255',
            'message_external_id' => 'nullable|string|max:255',
        ]);

        $this->triage->flagNotRealEstate(
            $agencyId,
            $user,
            $validated['identifier'],
            $validated['identifier_name'] ?? null,
            $validated['message_external_id'] ?? null,
        );

        return back()->with('success', 'Flagged as not real-estate related — removed from your triage list.');
    }
}
