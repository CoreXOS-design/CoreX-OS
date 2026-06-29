<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Outreach\OutreachQueue;
use App\Services\Outreach\OutreachWindowService;
use App\Services\SellerOutreach\MarketingConsentService;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Services\SellerOutreach\SellerOutreachSenderService;
use Illuminate\Http\Request;

/**
 * AT-117 §6 — the Outreach Queue work-the-list screen.
 *
 * The agent works their SURFACED rows top-to-bottom: each "Open WhatsApp" builds
 * the per-agency deep-link via the EXISTING SellerOutreachSenderService and opens
 * the pre-filled chat — the agent taps Send by hand (no programmatic send, ever).
 *
 * Dispatch reuses the canonical send pipeline: at the dispatch action we rebuild
 * the OutreachContext from the queue row and call send(), which creates the
 * seller_outreach_sends record (in-window dispatch timestamp = §4a evidence,
 * opt-out/tracking tokens resolved FRESH here per §4b). The deep-link with
 * resolved tokens REQUIRES that record, so dispatch creates it (mirrors the
 * composer's send button); the queue row is then flipped to sent and linked.
 *
 * Window + consent are re-checked SERVER-SIDE at the dispatch moment (defense in
 * depth — someone may have opted out, or the window may have closed, since the
 * sweep surfaced the row). All reuse OutreachWindowService / canMarketTo — no
 * parallel logic. Tenancy via AgencyScope; cancel is a soft delete (no hard delete).
 */
class OutreachQueueController extends Controller
{
    public function index(Request $request, OutreachWindowService $window)
    {
        $user = $request->user();
        $agency = Agency::find($user->effectiveAgencyId());

        $surfaced = OutreachQueue::forAgent($user->id)->surfaced()
            ->with(['contact', 'property'])
            ->orderBy('surfaced_at')
            ->get();

        $scheduled = OutreachQueue::forAgent($user->id)
            ->where('status', OutreachQueue::STATUS_PENDING)
            ->with(['contact', 'property'])
            ->orderBy('due_at')
            ->get();

        // Transparency: recently dropped/expired (not actionable) with their reason.
        $inactive = OutreachQueue::forAgent($user->id)
            ->whereIn('status', [OutreachQueue::STATUS_DROPPED, OutreachQueue::STATUS_EXPIRED])
            ->with('contact')
            ->latest('updated_at')
            ->limit(15)
            ->get();

        $sendAllowed   = $agency ? $window->isSendAllowed($agency) : true;
        $windowMessage = ($agency && !$sendAllowed) ? $window->blockedMessage($agency) : '';

        return view('corex.outreach-queue.index', compact(
            'surfaced', 'scheduled', 'inactive', 'sendAllowed', 'windowMessage'
        ));
    }

    /**
     * The dispatch action ("Open WhatsApp"). Re-checks window + consent, creates
     * the canonical send-record, flips the row to sent, returns the deep-link.
     */
    public function open(
        Request $request,
        OutreachQueue $outreachQueue,
        OutreachWindowService $window,
        MarketingConsentService $consent,
        SellerOutreachComposerService $composer,
        SellerOutreachSenderService $sender
    ) {
        if ($outreachQueue->status !== OutreachQueue::STATUS_SURFACED) {
            return response()->json(['message' => 'This item is no longer in your active queue.'], 422);
        }

        $contact = $outreachQueue->contact;
        if (!$contact) {
            $outreachQueue->forceFill([
                'status' => OutreachQueue::STATUS_DROPPED, 'dropped_reason' => 'contact_unavailable',
            ])->save();
            return response()->json(['message' => 'Contact is no longer available — removed from the queue.', 'dropped' => true], 422);
        }

        $agency = Agency::find($outreachQueue->agency_id);

        // Defense in depth #1 — the send-window may have closed since surfacing.
        if ($agency && !$window->isSendAllowed($agency)) {
            return response()->json([
                'message' => $window->blockedMessage($agency), 'send_window_blocked' => true,
            ], 422);
        }

        // Defense in depth #2 — consent may have changed since surfacing (§4b).
        if (!$consent->canMarketTo($contact, $outreachQueue->channel)) {
            $reason = $consent->marketingBlockReason($contact, $outreachQueue->channel) ?? 'not_marketable';
            $outreachQueue->forceFill([
                'status' => OutreachQueue::STATUS_DROPPED, 'dropped_reason' => $reason,
            ])->save();
            return response()->json([
                'message' => 'This contact is no longer marketable (' . $reason . ') — removed from the queue.',
                'dropped' => true,
            ], 422);
        }

        // Reuse the canonical send pipeline. body_snapshot carries the prepared
        // body with literal opt-out/tracking tokens; send() resolves them fresh.
        $context = $composer->composeContext(
            agencyId:        (int) $outreachQueue->agency_id,
            contact:         $contact,
            property:        $outreachQueue->property,
            channel:         $outreachQueue->channel,
            templateId:      $outreachQueue->template_id,
            agent:           $request->user(),
            bodyOverride:    $outreachQueue->body_snapshot,
            subjectOverride: null,
        );

        if (!empty($context->validationIssues)) {
            return response()->json(['message' => 'Cannot dispatch: ' . implode(' ', $context->validationIssues)], 422);
        }

        $send = $sender->send($context);

        $outreachQueue->forceFill([
            'status'                  => OutreachQueue::STATUS_SENT,
            'sent_at'                 => now(),
            'seller_outreach_send_id' => $send->id,
        ])->save();

        $clientUrl = $outreachQueue->channel === 'whatsapp' ? $sender->whatsappUrl($send) : null;

        return response()->json([
            'ok'         => true,
            'client_url' => $clientUrl,
            'message'    => $outreachQueue->channel === 'whatsapp'
                ? 'Recorded — opening WhatsApp. Tap Send in WhatsApp to deliver it.'
                : 'Sent — branded email dispatched.',
        ]);
    }

    /** Cancel a not-yet-surfaced (pending) row — soft delete, never a hard delete. */
    public function cancel(Request $request, OutreachQueue $outreachQueue)
    {
        if ($outreachQueue->status !== OutreachQueue::STATUS_PENDING) {
            return response()->json(['message' => 'Only scheduled (not-yet-surfaced) items can be cancelled.'], 422);
        }
        $outreachQueue->forceFill(['status' => OutreachQueue::STATUS_CANCELLED])->save();
        $outreachQueue->delete(); // soft delete (archive) — recoverable

        return response()->json(['ok' => true, 'message' => 'Removed from the schedule.']);
    }
}
