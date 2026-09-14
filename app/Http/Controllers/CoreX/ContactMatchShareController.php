<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactMatch;
use App\Models\ContactMatchShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-Core-Matches, Johan's ruling 4 — records that a live link was shared
 * (internal-only history) and resets the buyer's working clock (ruling 2).
 * cc3's UI calls this whenever the agent uses "copy link" / "send via
 * WhatsApp" / etc. — the link itself is one permanent, always-current URL
 * (ruling 4); nothing about the link's own content changes here.
 */
class ContactMatchShareController extends Controller
{
    public function record(Request $request, ContactMatch $match): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'in:' . implode(',', [
                ContactMatchShare::CHANNEL_COPY_LINK,
                ContactMatchShare::CHANNEL_WHATSAPP,
                ContactMatchShare::CHANNEL_EMAIL,
                ContactMatchShare::CHANNEL_OTHER,
            ])],
        ]);

        $share = ContactMatchShare::record($match, $request->user()->id, $validated['channel'] ?? null);

        return response()->json(['ok' => true, 'shared_at' => $share->shared_at->toIso8601String()]);
    }
}
