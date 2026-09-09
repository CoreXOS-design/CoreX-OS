<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboundMailGuardCapture;
use Illuminate\Http\Response;

/**
 * AT-URGENT-2026-09-09 — "captured, visible, and countable — at minimum we
 * must be able to say '142 messages were held while this was on, here they
 * are'." View + count + per-message .eml export only, deliberately no
 * release mechanism (Johan, confirmed: a raw-MIME replay would bypass
 * whatever state machine the original message type normally updates).
 *
 * owner_only — same boundary as the toggle itself.
 */
class OutboundMailGuardCaptureController extends Controller
{
    public function index()
    {
        $captures = OutboundMailGuardCapture::query()
            ->orderByDesc('captured_at')
            ->paginate(25);

        return view('admin.outbound-mail-captures.index', [
            'captures' => $captures,
            'totalCount' => OutboundMailGuardCapture::count(),
        ]);
    }

    public function download(OutboundMailGuardCapture $capture): Response
    {
        $filename = 'capture-' . $capture->id . '-' . $capture->captured_at->format('Ymd-His') . '.eml';

        return response($capture->raw_mime, 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
