<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLink;
use App\Models\Contact;
use App\Services\Communications\CommsAccessGrantService;
use App\Services\Communications\CommunicationStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
    public function __construct(
        protected CommsAccessGrantService $grants,
        protected CommunicationStorageService $storage,
    ) {}

    public function index(Request $request)
    {
        $query = Communication::query()->notPurged()->with(['links', 'owner:id,name']);
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
            ->with(['attachments', 'owner:id,name']);
        $this->grants->applyArchiveVisibility($query, $request->user());

        $messages = $query->orderBy('occurred_at')->get();

        abort_if($messages->isEmpty(), 404);

        return view('compliance.communication-archive.thread', [
            'threadKey'   => $threadKey,
            'messages'    => $messages,
            'backContact' => $this->backContext($request), // AT-137 context-aware back
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

        $communication->load('attachments', 'links', 'owner:id,name');

        return view('compliance.communication-archive.show', [
            'communication' => $communication,
            'backContact'   => $this->backContext($request), // AT-137 context-aware back
        ]);
    }

    /**
     * AT-148 — serve a stored WhatsApp media attachment (voice note) for inline
     * playback. AUTHENTICATED + gated: the route sits behind the archive-entry
     * permission, and here the SAME per-thread gate as the thread/message views
     * (applyArchiveVisibility on the parent communication) decides whether the
     * caller may hear it — a non-entitled user hitting the URL directly is 404'd
     * (no URL-guessing bypass). Bytes are streamed from the MOUNTED VOLUME through
     * Laravel — never a public docroot path. Range requests are honoured (audio
     * seeking) by response()->file().
     */
    public function attachment(CommunicationAttachment $attachment, Request $request)
    {
        // BelongsToAgency global scope on the model binding already restricts to
        // the caller's agency. Enforce the per-thread visibility of the PARENT
        // communication (not purged, and visible under the caller's grants/scope).
        $visible = $this->grants->applyArchiveVisibility(
            Communication::query()->notPurged()->whereKey($attachment->communication_id),
            $request->user()
        )->exists();
        abort_unless($visible, 404);

        // Only a fully-stored attachment is served; a media-pending row has no file.
        abort_unless($attachment->isPlayable(), 404);

        $disk = Storage::disk($this->storage->disk());
        abort_unless($disk->exists($attachment->storage_path), 404);

        $mime = $attachment->mime ?: 'application/octet-stream';
        // Strip any ;codecs=… parameter for the outbound header base type, but keep
        // the full mimetype — browsers accept "audio/ogg; codecs=opus" fine.
        $downloadName = $this->downloadName($attachment);

        return response()->file($disk->path($attachment->storage_path), [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . $downloadName . '"',
            'Cache-Control'       => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A human filename for the inline player, inferring an audio extension. */
    private function downloadName(CommunicationAttachment $attachment): string
    {
        if (is_string($attachment->filename) && trim($attachment->filename) !== '') {
            return preg_replace('/[^A-Za-z0-9._-]/', '_', $attachment->filename);
        }
        $ext = 'bin';
        $mime = strtolower((string) $attachment->mime);
        if (str_contains($mime, 'ogg') || str_contains($mime, 'opus')) {
            $ext = 'ogg';
        } elseif (str_contains($mime, 'mpeg') || str_contains($mime, 'mp3')) {
            $ext = 'mp3';
        } elseif (str_contains($mime, 'audio/')) {
            $ext = 'audio';
        }

        return 'voice-note-' . $attachment->id . '.' . $ext;
    }

    /**
     * AT-137 — resolve the originating contact for context-aware Back navigation.
     * When the user opened a thread/message FROM a contact record (the contact
     * Communications tab passes ?from=contact&contact=<id>), Back returns there
     * instead of always dumping into the compliance archive. Returns null when the
     * user came from the archive itself.
     */
    private function backContext(Request $request): ?Contact
    {
        if ($request->query('from') === 'contact' && ($contactId = $request->query('contact'))) {
            return Contact::find($contactId);
        }

        return null;
    }
}
