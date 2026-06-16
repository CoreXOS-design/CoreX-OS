<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Services\SellerOutreach\MarketingConsentService;
use App\Services\SellerOutreach\SellerOutreachOptOutService;
use App\Services\SellerOutreach\TransactionStateService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public (unauthenticated) self-service communication-preferences screen — AT-49/AT-50.
 *
 * The per-send opt_out_token IS the credential. The recipient taps the link in
 * their outreach message and lands on a branded, mobile-first TWO-SWITCH page
 * (GET, NO write — so WhatsApp / link-preview crawlers can't change anything):
 *
 *   Switch A — "Marketing & area updates": always toggleable. Off records a
 *     marketing opt-out (agency-wide send gate); On re-grants consent. Both
 *     converge on MarketingConsentService.
 *   Switch B — "Messages about my transaction": if the contact is in a LIVE
 *     sale (TransactionStateService) the switch is LOCKED with a plain-language
 *     explanation that NAMES the sale — transactional comms cannot be silenced
 *     until the sale concludes. If there is NO live sale, turning it off records
 *     a full opt-out (same marketing opt-out write — there is no live business
 *     comms to stop).
 *
 * Mirrors PublicLandingController: strict token regex, 404 on miss, noindex.
 * Every write is idempotent; GET never writes.
 */
final class PublicOptOutController extends Controller
{
    public function __construct(
        private readonly SellerOutreachOptOutService $optOut,
        private readonly MarketingConsentService $consent,
        private readonly TransactionStateService $transactions,
    ) {}

    private const OPT_OUT_REASON     = 'Self-service opt-out link';
    private const OPT_OUT_ALL_REASON = 'Self-service opt-out link (all messages)';
    private const OPT_IN_REASON      = 'Self-service opt-out link — marketing re-enabled';

    private const ACTION_STOP_MARKETING   = 'stop_marketing';
    private const ACTION_RESUME_MARKETING = 'resume_marketing';
    private const ACTION_STOP_ALL         = 'stop_all';

    /** GET /outreach/opt-out/{token} — render the two-switch screen. PREVIEW-SAFE. */
    public function show(Request $request, string $token)
    {
        $send = $this->resolveSend($token);
        $contact = $this->resolveContact($send);

        return $this->render($send, $contact, done: false);
    }

    /**
     * POST /outreach/opt-out/{token} — apply a switch change.
     * `action`: stop_marketing | resume_marketing | stop_all. Idempotent.
     */
    public function confirm(Request $request, string $token)
    {
        $send = $this->resolveSend($token);
        $contact = $this->resolveContact($send);
        $agencyId = (int) $send->agency_id;

        $action = (string) $request->input('action', self::ACTION_STOP_MARKETING);

        switch ($action) {
            case self::ACTION_RESUME_MARKETING:
                // Turn marketing back on — re-grant consent + lift suppression.
                $this->consent->optInContact(
                    contact:     $contact,
                    reason:      self::OPT_IN_REASON,
                    actorUserId: $send->agent_id,
                    send:        $send,
                );
                break;

            case self::ACTION_STOP_ALL:
                // "Stop ALL messages" — only honoured when there is NO live sale.
                // If a live sale exists, transactional comms cannot be silenced:
                // ignore the write and re-render with the lock explanation
                // (No Silent Locks — enforced server-side, not just hidden in UI).
                if (!$this->transactions->isInLiveTransaction($agencyId, $contact)) {
                    $this->recordOptOutOnce($send, $contact, self::OPT_OUT_ALL_REASON);
                }
                break;

            case self::ACTION_STOP_MARKETING:
            default:
                $this->recordOptOutOnce($send, $contact, self::OPT_OUT_REASON);
                break;
        }

        // Re-resolve the contact's freshest state for the rendered screen.
        $contact->refresh();

        return $this->render($send, $contact, done: true);
    }

    /** Idempotent marketing opt-out via the event path (converges on MarketingConsentService). */
    private function recordOptOutOnce(SellerOutreachSend $send, Contact $contact, string $reason): void
    {
        if ($contact->messaging_opt_out_at !== null) {
            return; // already opted out — preserve the original record
        }

        $this->optOut->recordOptOut(
            agencyId: (int) $send->agency_id,
            contact:  $contact,
            reason:   $reason,
            send:     $send,
            source:   OptOutRecorded::SOURCE_SELF_SERVICE_LINK,
        );
    }

    private function resolveSend(string $token): SellerOutreachSend
    {
        // The token alphabet has no special chars — strict regex protects
        // against arbitrary URL probing (mirrors PublicLandingController).
        if (!preg_match('/^[A-Za-z0-9]{48}$/', $token)) {
            abort(404);
        }

        try {
            return SellerOutreachSend::withoutGlobalScopes()
                ->where('opt_out_token', $token)
                ->whereNull('deleted_at')
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function resolveContact(SellerOutreachSend $send): Contact
    {
        try {
            return Contact::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('id', $send->contact_id)
                ->where('agency_id', $send->agency_id)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            // Contact archived after the message went out — nothing to opt out.
            abort(404);
        }
    }

    private function render(SellerOutreachSend $send, Contact $contact, bool $done)
    {
        $agencyId = (int) $send->agency_id;
        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'our agency');

        $inLiveTransaction = $this->transactions->isInLiveTransaction($agencyId, $contact);
        $liveTransactions = $inLiveTransaction
            ? $this->transactions->liveTransactions($agencyId, $contact)
            : [];

        return response()
            ->view('seller-outreach.opt-out', [
                'agencyName'        => $agencyName,
                'token'             => $send->opt_out_token,
                'marketingOptedOut' => $contact->messaging_opt_out_at !== null,
                'inLiveTransaction' => $inLiveTransaction,
                'liveTransactions'  => $liveTransactions,
                'done'              => $done,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
