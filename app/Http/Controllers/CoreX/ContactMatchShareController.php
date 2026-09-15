<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactMatchShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-Core-Matches, Johan's dated-link ruling. The mint happens server-side,
 * synchronously, at page-render time (_match-action-bar.blade.php's own
 * @php block — the fresh link is already embedded in the message text
 * before the agent ever sees it, no extra round trip on page load). This
 * controller's ONE job is the actual send click: confirm() is what makes
 * a minted link count as a real share (channel + property snapshot +
 * working-clock reset — see ContactMatchShare::confirmSent()). Same
 * core_matches.view gate as reading the match itself — anyone who can see
 * a match and act on it may share its live link; reassignment is the
 * privileged action, not this.
 */
class ContactMatchShareController extends Controller
{
    public function confirm(Request $request, ContactMatchShare $share): JsonResponse
    {
        abort_unless($share->shared_by_user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'in:' . implode(',', [
                ContactMatchShare::CHANNEL_COPY_LINK,
                ContactMatchShare::CHANNEL_WHATSAPP,
                ContactMatchShare::CHANNEL_EMAIL,
                ContactMatchShare::CHANNEL_OTHER,
            ])],
        ]);

        $share = $share->confirmSent($validated['channel'] ?? null);

        return response()->json(['ok' => true, 'confirmed_at' => $share->confirmed_at->toIso8601String()]);
    }
}
