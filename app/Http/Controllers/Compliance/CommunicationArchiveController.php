<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Services\Communications\CommsAccessGrantService;
use Illuminate\Http\Request;

/**
 * Communication Archive viewer (AT-33). Slots into the Compliance area beside
 * the existing Communications Log; agency-scoped via BelongsToAgency. Read-only
 * — the archive is immutable.
 *
 * ENTRY is gated by the access_communication_archive permission (route middleware
 * — who may reach the archive area at all). AT-132 Wave 1 Step 3: WHICH rows /
 * threads / messages are returned INSIDE it is now gated by the SAME per-thread
 * gate as the contact tab — CommsAccessGrantService::applyArchiveVisibility
 * (owner OR communications.view scope OR AT-127 participant OR a live per-thread /
 * legacy whole-contact grant). This closes the body-surface bypass where the
 * thread body opened behind only the agency-wide entry permission, ignoring the
 * per-thread grant the list enforced. One gated path — no duplicated logic.
 */
class CommunicationArchiveController extends Controller
{
    public function __construct(protected CommsAccessGrantService $grants) {}

    public function index(Request $request)
    {
        $query = Communication::query()->notPurged()->with('links');
        $this->grants->applyArchiveVisibility($query, $request->user());

        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }
        if ($direction = $request->query('direction')) {
            $query->where('direction', $direction);
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('from_identifier', 'like', "%{$search}%")
                  ->orWhere('body_preview', 'like', "%{$search}%");
            });
        }

        // Optional per-contact filter via the intelligence links.
        $contact = null;
        if ($contactId = $request->query('contact')) {
            $contact = Contact::find($contactId);
            $commIds = CommunicationLink::where('linkable_type', Contact::class)
                ->where('linkable_id', $contactId)
                ->pluck('communication_id');
            $query->whereIn('id', $commIds);
        }

        $communications = $query->orderByDesc('occurred_at')->paginate(25)->withQueryString();

        return view('compliance.communication-archive.index', [
            'communications' => $communications,
            'channel'        => $channel,
            'direction'      => $direction,
            'search'         => $search,
            'contact'        => $contact,
        ]);
    }

    /**
     * Conversation thread (grouped by thread_key), chronological. Gated: only
     * messages the user may see are returned; a user with no entitlement to the
     * thread gets an empty set → 404 (no body, no URL-guessing bypass).
     */
    public function thread(string $threadKey, Request $request)
    {
        $query = Communication::query()
            ->notPurged()
            ->where('thread_key', $threadKey)
            ->with('attachments');
        $this->grants->applyArchiveVisibility($query, $request->user());

        $messages = $query->orderBy('occurred_at')->get();

        abort_if($messages->isEmpty(), 404);

        return view('compliance.communication-archive.thread', [
            'threadKey' => $threadKey,
            'messages'  => $messages,
        ]);
    }

    public function show(Communication $communication, Request $request)
    {
        // Route-model binding already AgencyScopes the comm; now enforce the
        // per-thread gate — a non-entitled user hitting the message URL directly
        // is refused (404, not the body).
        $visible = $this->grants->applyArchiveVisibility(
            Communication::query()->whereKey($communication->id),
            $request->user()
        )->exists();
        abort_unless($visible, 404);

        $communication->load('attachments', 'links');

        return view('compliance.communication-archive.show', [
            'communication' => $communication,
        ]);
    }
}
