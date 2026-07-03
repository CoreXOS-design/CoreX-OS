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
use Illuminate\Support\Str;
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
                  ->orWhere('body_preview', 'like', "%{$search}%")
                  // AT-163 — voice-note transcripts are searchable (inherits the same
                  // pre-applied visibility + consent gate as body_preview).
                  ->orWhere('transcript_text', 'like', "%{$search}%");
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
        $pageSize = $this->threadPageSize();

        // AT-168 Part C — WhatsApp-style: open on the NEWEST page (scrolled to the
        // bottom), lazy-load older on scroll-up. Never render a years-long thread at
        // once. Fetch the newest $pageSize DESC, then flip to ASC for display.
        $total    = $this->threadVisibleQuery($threadKey, $request)->count();
        abort_if($total === 0, 404);

        $newest = $this->threadVisibleQuery($threadKey, $request)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->limit($pageSize)->get();

        $messages = $newest->sortBy([['occurred_at', 'asc'], ['id', 'asc']])->values();
        $oldest   = $messages->first();

        return view('compliance.communication-archive.thread', [
            'threadKey'    => $threadKey,
            'messages'     => $messages,
            'total'        => $total,
            'hasMore'      => $total > $messages->count(),
            'olderCursor'  => $oldest ? $this->cursorFor($oldest) : null,
            'backContact'  => $this->backContext($request), // AT-137 context-aware back
        ]);
    }

    /**
     * AT-168 Part C — the scroll-up loader: return the page of messages OLDER than
     * the given cursor (occurred_at + id), rendered as chat bubbles, plus whether
     * more remain. JSON so the client prepends without a full reload.
     */
    public function threadOlder(string $threadKey, Request $request)
    {
        $pageSize = $this->threadPageSize();
        $beforeAt = (string) $request->query('before_at', '');
        $beforeId = (int) $request->query('before_id', 0);

        $q = $this->threadVisibleQuery($threadKey, $request);
        if ($beforeAt !== '') {
            // Strictly older than the cursor (compound occurred_at,id ordering).
            $q->where(function ($w) use ($beforeAt, $beforeId) {
                $w->where('occurred_at', '<', $beforeAt)
                  ->orWhere(fn ($e) => $e->where('occurred_at', $beforeAt)->where('id', '<', $beforeId));
            });
        }

        $olderDesc = $q->orderByDesc('occurred_at')->orderByDesc('id')->limit($pageSize + 1)->get();
        $hasMore   = $olderDesc->count() > $pageSize;
        $page      = $olderDesc->take($pageSize)->sortBy([['occurred_at', 'asc'], ['id', 'asc']])->values();

        $html = '';
        foreach ($page as $m) {
            $html .= view('compliance.communication-archive._thread-bubble', ['m' => $m])->render();
        }
        $oldest = $page->first();

        return response()->json([
            'html'     => $html,
            'has_more' => $hasMore,
            'cursor'   => $oldest ? $this->cursorFor($oldest) : null,
            'count'    => $page->count(),
        ]);
    }

    /**
     * AT-168 Part C — search WITHIN a thread. Matches on the message body (and,
     * built body-field-first, future voice-note transcripts slot in here with an
     * orWhere). Returns lightweight match metadata; the client jumps + highlights,
     * loading older pages as needed to reach an off-screen match.
     */
    public function threadSearch(string $threadKey, Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '' || mb_strlen($term) < 2) {
            return response()->json(['matches' => [], 'term' => $term]);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
        $matches = $this->threadVisibleQuery($threadKey, $request)
            ->where(function ($w) use ($like) {
                $w->where('body_text', 'like', $like)
                  ->orWhere('subject', 'like', $like)
                  // AT-163 — voice-note transcripts are searchable in-thread too.
                  ->orWhere('transcript_text', 'like', $like);
            })
            ->orderBy('occurred_at')->orderBy('id')
            ->limit(200)
            ->get(['id', 'occurred_at', 'body_text', 'subject', 'transcript_text', 'transcript_status'])
            ->map(fn ($m) => [
                'id'      => $m->id,
                'at'      => $m->occurred_at?->format('d M Y H:i'),
                'preview' => Str::limit(trim((string) ($m->subject ? $m->subject . ' — ' : '')
                    . (string) ($m->body_text ?: ($m->transcript_status === 'done' ? 'Voice note: ' . $m->transcript_text : ''))), 90),
            ]);

        return response()->json(['matches' => $matches->values(), 'term' => $term, 'count' => $matches->count()]);
    }

    private function threadPageSize(): int
    {
        // Configurable, not hardcoded (spec). Clamp only to sane bounds.
        return max(1, min(500, (int) config('communications.thread_page_size', 40)));
    }

    /** The gated base query for a thread's messages (visibility applied). */
    private function threadVisibleQuery(string $threadKey, Request $request)
    {
        $query = Communication::query()
            ->notPurged()
            ->where('thread_key', $threadKey)
            ->with(['attachments', 'owner:id,name']);
        $this->grants->applyArchiveVisibility($query, $request->user());

        return $query;
    }

    /** @return array{before_at:string,before_id:int} cursor for the given message. */
    private function cursorFor(Communication $m): array
    {
        return [
            'before_at' => optional($m->occurred_at)->format('Y-m-d H:i:s') ?? '',
            'before_id' => (int) $m->id,
        ];
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
     * AT-163 — on-demand "Transcribe now" for a voice note. Gated by the SAME
     * per-thread visibility as the thread view (a user who can't see the note
     * can't transcribe it), and consent-gated in the service (a withheld note has
     * no stored audio to transcribe). Always queued (whisper is long-running);
     * CPU-guarded only in the message shown — the worker itself is nice'd and
     * single-flighted (ShouldBeUnique).
     */
    public function transcribeNote(Communication $communication, Request $request)
    {
        $visible = $this->grants->applyArchiveVisibility(
            Communication::query()->notPurged()->whereKey($communication->id),
            $request->user()
        )->exists();
        abort_unless($visible, 404);

        $communication->load('attachments');
        $service = app(\App\Services\Communications\TranscriptionService::class);

        if (! $service->transcribableAttachment($communication)) {
            return back()->with('error', 'This message has no voice note available to transcribe.');
        }

        // Show the pending state immediately; the queued job flips it to done/failed.
        $communication->forceFill(['transcript_status' => 'pending', 'transcript_error' => null])->save();
        \App\Jobs\Communications\TranscribeVoiceNoteJob::dispatch($communication->id);

        $msg = $service->isBoxBusy()
            ? 'Transcription queued — the server is busy, it will run shortly.'
            : 'Transcription started — the transcript will appear here in a moment.';

        return back()->with('success', $msg);
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

    /**
     * AT-148 — manual retry for a pending/failed media attachment (the Retry
     * affordance in the thread). Same per-thread visibility gate as serving; runs
     * the recovery synchronously so the user sees the result immediately.
     */
    public function retryMedia(CommunicationAttachment $attachment, Request $request, \App\Services\Communications\WaMediaRecoveryService $recovery)
    {
        $visible = $this->grants->applyArchiveVisibility(
            Communication::query()->notPurged()->whereKey($attachment->communication_id),
            $request->user()
        )->exists();
        abort_unless($visible, 404);

        if (! $attachment->isPlayable()) {
            $attachment->forceFill(['media_status' => CommunicationAttachment::MEDIA_PENDING])->save();
            $recovery->recover($attachment->refresh());
        }

        return back()->with(
            $attachment->refresh()->isPlayable() ? 'success' : 'error',
            $attachment->isPlayable()
                ? 'Media downloaded — the voice note is now playable.'
                : 'Could not fetch the media from WhatsApp. It may no longer be available on the phone.'
        );
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
