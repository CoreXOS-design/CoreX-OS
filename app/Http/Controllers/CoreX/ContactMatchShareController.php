<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchShare;
use App\Services\Matching\CoreMatchShareHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function confirm(Request $request, ContactMatchShare $share, CoreMatchShareHistoryService $history): JsonResponse
    {
        abort_unless($share->shared_by_user_id === $request->user()->id, 403);

        $share = $share->confirmSent($this->validatedChannel($request));

        // AT-503 — the reply carries the fresh unsent count + last-sent time so the
        // page updates in place; the share row is committed before this is read.
        return response()->json([
            'ok'           => true,
            'confirmed_at' => $share->confirmed_at->toIso8601String(),
            'status'       => $history->statusFor($share->contactMatch()->withoutGlobalScopes()->firstOrFail()),
        ]);
    }

    /**
     * AT-503 — the buyer-level Share button. It sends the buyer's ONE client page,
     * which shows every ACTIVE wishlist, so a confirmed send records a confirmed
     * share against each of them (the composer's mint + confirm, per wishlist).
     * One transaction: either every wishlist is recorded or none is, and the
     * caller gets an error it can show and retry.
     */
    public function confirmBuyer(Request $request, int $contactId, CoreMatchShareHistoryService $history): JsonResponse
    {
        $contact = Contact::findOrFail($contactId); // ContactScope: a buyer outside the viewer's scope is a 404
        $channel = $this->validatedChannel($request);
        $userId  = $request->user()->id;

        $matches = ContactMatch::query()
            ->where('contact_id', $contact->id)
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->get();

        DB::transaction(function () use ($matches, $userId, $channel) {
            foreach ($matches as $match) {
                ContactMatchShare::recordConfirmed($match, $userId, $channel);
            }
        });

        return response()->json([
            'ok'       => true,
            'statuses' => $matches->map(fn (ContactMatch $m) => $history->statusFor($m))->values(),
        ]);
    }

    private function validatedChannel(Request $request): ?string
    {
        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'in:' . implode(',', [
                ContactMatchShare::CHANNEL_COPY_LINK,
                ContactMatchShare::CHANNEL_WHATSAPP,
                ContactMatchShare::CHANNEL_EMAIL,
                ContactMatchShare::CHANNEL_OTHER,
            ])],
        ]);

        return $validated['channel'] ?? null;
    }
}
