<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use Illuminate\Http\Request;

/**
 * Communication Archive viewer (AT-33). Slots into the Compliance area beside
 * the existing Communications Log; agency-scoped via BelongsToAgency. Read-only
 * — the archive is immutable. Gated by manage_compliance (the full cross-staff
 * archive is a sensitive POPIA surface; not exposed to every agent).
 */
class CommunicationArchiveController extends Controller
{
    public function index(Request $request)
    {
        $query = Communication::query()->notPurged()->with('links');

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
     * Conversation thread (grouped by thread_key), chronological.
     */
    public function thread(string $threadKey)
    {
        $messages = Communication::query()
            ->notPurged()
            ->where('thread_key', $threadKey)
            ->with('attachments')
            ->orderBy('occurred_at')
            ->get();

        abort_if($messages->isEmpty(), 404);

        return view('compliance.communication-archive.thread', [
            'threadKey' => $threadKey,
            'messages'  => $messages,
        ]);
    }

    public function show(Communication $communication)
    {
        $communication->load('attachments', 'links');

        return view('compliance.communication-archive.show', [
            'communication' => $communication,
        ]);
    }
}
