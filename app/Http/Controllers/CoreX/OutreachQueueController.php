<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\Outreach\OutreachQueue;
use App\Models\Property;
use App\Services\Outreach\OutreachQueueService;
use App\Services\Outreach\OutreachWindowService;
use App\Services\SellerOutreach\MarketingConsentService;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use App\Services\SellerOutreach\SellerOutreachSenderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // AT-120 — role-based visibility via the canonical permission scope
        // (own/branch/all from outreach_queue.view). Default 'own' so a user never
        // accidentally sees the whole agency. BranchScope adds branch isolation under
        // this automatically. No hardcoded role names.
        $scope = \App\Services\PermissionService::getDataScope($user, 'outreach_queue') ?? 'own';

        // The work-list: prepared-and-ready messages within the user's scope.
        $ready = OutreachQueue::visibleTo($user, $scope)->ready()
            ->with(['contact', 'property', 'agent'])
            ->latest('created_at')
            ->get();

        // Transparency: recently dropped/expired (not actionable) with their reason.
        $inactive = OutreachQueue::visibleTo($user, $scope)
            ->whereIn('status', [OutreachQueue::STATUS_DROPPED, OutreachQueue::STATUS_EXPIRED])
            ->with(['contact', 'agent'])
            ->latest('updated_at')
            ->limit(15)
            ->get();

        $sendAllowed   = $agency ? $window->isSendAllowed($agency) : true;
        $windowMessage = ($agency && !$sendAllowed) ? $window->blockedMessage($agency) : '';
        // Show whose-message-it-is when the viewer sees beyond their own.
        $showAgent     = $scope !== 'own';
        $currentUserId = $user->id;

        return view('corex.outreach-queue.index', compact(
            'ready', 'inactive', 'sendAllowed', 'windowMessage', 'showAgent', 'currentUserId'
        ));
    }

    /**
     * AT-117 §7 — canonical client enqueue for surfaces that compose-and-fire in
     * the browser (MIC Core-Matches share, etc.). They persist no message text, so
     * the prepared body is captured here as body_snapshot. Consent + window are
     * enforced by the shared OutreachQueueService. Tenancy via AgencyScope.
     */
    public function enqueue(Request $request, OutreachQueueService $queueService)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId();

        $data = $request->validate([
            'contact_id'  => 'required|integer',
            'channel'     => 'required|in:whatsapp,email',
            'source'      => 'required|in:mic,map,contact',
            'body'        => 'required|string',
            'property_id' => 'nullable|integer',
        ]);

        // AgencyScope isolates Contact/Property to the acting agency — a cross-agency
        // id resolves to null and is rejected (never leaks).
        $contact = Contact::find($data['contact_id']);
        if (!$contact) {
            return response()->json(['ok' => false, 'message' => 'Contact not found.'], 404);
        }
        $property = !empty($data['property_id']) ? Property::find($data['property_id']) : null;

        $res = $queueService->enqueue(
            Agency::find($agencyId), $contact, $user, $data['channel'], $data['source'], $data['body'], $property
        );

        $payload = ['ok' => $res['ok'], 'message' => $res['message']];
        if ($res['ok']) {
            $payload['queue_id'] = $res['row']->id;
        }
        return response()->json($payload, $res['status']);
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
        // AT-120 — capability + act-own. Dispatch opens the AGENT's OWN WhatsApp, so
        // even a manager/admin who can VIEW a branch/agency row may only send their
        // own (server-enforced — not just hidden). No hardcoded role names.
        if (!$request->user()->hasPermission('outreach_queue.dispatch')) {
            return response()->json(['message' => 'You do not have permission to send from the outreach queue.'], 403);
        }
        if ((int) $outreachQueue->agent_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'You can only send your own queued messages — it opens your WhatsApp.'], 403);
        }

        if ($outreachQueue->status !== OutreachQueue::STATUS_READY) {
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

        // Transaction-safe: the send-record creation and the queue-row flip commit
        // together, so a mid-dispatch failure can't leave a send with the row still
        // surfaced (which would let it re-dispatch).
        $send = DB::transaction(function () use ($sender, $context, $outreachQueue) {
            $s = $sender->send($context);
            $outreachQueue->forceFill([
                'status'                  => OutreachQueue::STATUS_SENT,
                'sent_at'                 => now(),
                'seller_outreach_send_id' => $s->id,
            ])->save();
            return $s;
        });

        $clientUrl = $outreachQueue->channel === 'whatsapp' ? $sender->whatsappUrl($send) : null;

        return response()->json([
            'ok'         => true,
            'client_url' => $clientUrl,
            'message'    => $outreachQueue->channel === 'whatsapp'
                ? 'Recorded — opening WhatsApp. Tap Send in WhatsApp to deliver it.'
                : 'Sent — branded email dispatched.',
        ]);
    }

    /** Remove a ready (prepared, unsent) row — soft delete, never a hard delete. */
    public function cancel(Request $request, OutreachQueue $outreachQueue)
    {
        // AT-120 — capability + act-own (a manager views the team's queues but removes
        // only their own by default; server-enforced, no hardcoded role names).
        if (!$request->user()->hasPermission('outreach_queue.cancel')) {
            return response()->json(['message' => 'You do not have permission to remove outreach-queue items.'], 403);
        }
        if ((int) $outreachQueue->agent_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'You can only remove your own queued messages.'], 403);
        }
        if ($outreachQueue->status !== OutreachQueue::STATUS_READY) {
            return response()->json(['message' => 'Only a ready (unsent) item can be removed.'], 422);
        }
        $outreachQueue->forceFill(['status' => OutreachQueue::STATUS_CANCELLED])->save();
        $outreachQueue->delete(); // soft delete (archive) — recoverable

        return response()->json(['ok' => true, 'message' => 'Removed from the queue.']);
    }
}
