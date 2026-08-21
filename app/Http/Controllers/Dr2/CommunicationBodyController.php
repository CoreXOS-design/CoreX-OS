<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Services\Communications\CommunicationStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CX-112 (Johan, 2026-08-21) — "an agent must be able to read an email before filing/
 * confirming it." Shared by both DR2 screens that show an email row (Unfiled Emails, and
 * the filed-emails section on a deal) so agents learn ONE viewer, not two.
 *
 * Reuses compliance.communication-archive._thread-bubble.blade.php as-is for content
 * rendering (escaped plain-text body, quote-strip toggle, attachments, transcript — all
 * already built and already safe, see CX-112 investigation) — this controller only supplies
 * the DR2-appropriate authorization for that reused partial's attachment links, gated on
 * view_deals rather than the stricter access_communication_archive permission the partial's
 * original caller (Comms Suspense/Archive) uses.
 *
 * On-demand only: body/attachment content is never eager-loaded for a list — this controller
 * exists specifically because both list screens fetch it per-row, only when a row is expanded.
 */
class CommunicationBodyController extends Controller
{
    /** GET /deals-dr2/communications/{communication}/body — the rendered bubble for ONE email. */
    public function show(Request $request, Communication $communication)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        abort_unless((int) $communication->agency_id === (int) $agencyId, 404);

        $communication->loadMissing('attachments');

        return view('compliance.communication-archive._thread-bubble', [
            'm' => $communication,
            'attachmentRoute' => 'deals-dr2.comms-body.attachment',
            'attachmentRetryRoute' => 'deals-dr2.comms-body.attachment', // DR2 is email-only (no
            // WhatsApp voice notes — CX-109 excludes WhatsApp from the deal register entirely),
            // so the retry affordance is unreachable in practice; pointed at a route that
            // exists rather than left dangling.
            'transcribeRoute' => 'deals-dr2.comms-body.attachment',
        ])->render();
    }

    /** GET /deals-dr2/communications/attachments/{attachment} — same streaming as the
     * compliance archive's attachment(), DR2-scoped authorization instead. */
    public function attachment(Request $request, CommunicationAttachment $attachment, CommunicationStorageService $storage)
    {
        $agencyId = $request->user()->effectiveAgencyId();
        $communication = Communication::query()->withoutGlobalScopes()->find($attachment->communication_id);
        abort_unless($communication && (int) $communication->agency_id === (int) $agencyId, 404);

        abort_unless($attachment->isPlayable(), 404);

        $disk = Storage::disk($storage->disk());
        abort_unless($disk->exists($attachment->storage_path), 404);

        $mime = $attachment->mime ?: 'application/octet-stream';
        $downloadName = $attachment->filename ?: ('attachment-' . $attachment->id);

        return response()->file($disk->path($attachment->storage_path), [
            'Content-Type'           => $mime,
            'Content-Disposition'    => 'inline; filename="' . $downloadName . '"',
            'Cache-Control'          => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
