<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\SellerOutreach\AgentCardImageService;
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
        private readonly AgentCardImageService $cards,
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
                    // Stop EVERYTHING (transactional too). blockAll upgrades a
                    // prior marketing-only opt-out.
                    $this->recordOptOutOnce($send, $contact, self::OPT_OUT_ALL_REASON, blockAll: true);
                }
                break;

            case self::ACTION_STOP_MARKETING:
            default:
                // Marketing-only: transactional channels stay open.
                $this->recordOptOutOnce($send, $contact, self::OPT_OUT_REASON, blockAll: false);
                break;
        }

        // Re-resolve the contact's freshest state for the rendered screen.
        $contact->refresh();

        return $this->render($send, $contact, done: true);
    }

    /**
     * Opt-out via the event path (converges on MarketingConsentService). Fires
     * on the first opt-out, and again ONLY to upgrade a marketing-only opt-out to
     * a full stop — otherwise idempotent (no event spam on repeat POSTs).
     */
    private function recordOptOutOnce(SellerOutreachSend $send, Contact $contact, string $reason, bool $blockAll): void
    {
        $alreadyOut = $contact->messaging_opt_out_at !== null;
        $isUpgrade  = $blockAll && !$contact->messaging_all_blocked;
        if ($alreadyOut && !$isUpgrade) {
            return; // already at (or above) this depth — preserve the original record
        }

        $this->optOut->recordOptOut(
            agencyId: (int) $send->agency_id,
            contact:  $contact,
            reason:   $reason,
            send:     $send,
            source:   OptOutRecorded::SOURCE_SELF_SERVICE_LINK,
            blockAll: $blockAll,
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
        // BUG A — brand the page as the SENDING AGENCY (logo + theme), not CoreX.
        $branding = \App\Models\Agency::publicBrandingFor($agencyId);

        $inLiveTransaction = $this->transactions->isInLiveTransaction($agencyId, $contact);
        $liveTransactions = $inLiveTransaction
            ? $this->transactions->liveTransactions($agencyId, $contact)
            : [];

        return response()
            ->view('seller-outreach.opt-out', [
                'agencyName'        => $branding['name'],
                'agencyLogoUrl'     => $branding['logoUrl'],
                'brand'             => $branding['colors'],
                'token'             => $send->opt_out_token,
                'marketingOptedOut' => $contact->messaging_opt_out_at !== null,
                'commStatus'        => $contact->communicationStatus(),
                'inLiveTransaction' => $inLiveTransaction,
                'liveTransactions'  => $liveTransactions,
                'done'              => $done,
                'og'                => $this->ogCard($send, $branding),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * AT-83 — Open-Graph link-preview for the WhatsApp card. This preference page
     * is the SINGLE link in the outreach body (the opt-in/opt-out split was
     * reverted), so its og:image is the only preview WhatsApp can render — the
     * sending agent's composite business-card. Resolved off the send's agent;
     * degrades to the agency logo + generic title if the agent is gone, so the
     * preview is never broken.
     *
     * @param  array{name:string,logoUrl:?string,colors:array}  $branding
     * @return array{title:string,description:string,image:?string,url:string}
     */
    private function ogCard(SellerOutreachSend $send, array $branding): array
    {
        $agencyName = (string) $branding['name'];
        $url = route('seller-outreach.public.opt-out.show', $send->opt_out_token);

        $agent = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->find($send->agent_id);

        // Default to the agency logo card (pre-AT-83 behaviour) when there is no
        // resolvable agent. $isCard tracks whether $image is the 1200×630 agent
        // card (so the blade only claims those dims for the real card).
        $title = "{$agencyName} — Communication preferences";
        $image = $branding['logoUrl'] ?? null;
        $isCard = false;

        if ($agent) {
            $designation = trim((string) ($agent->designation ?? '')) ?: 'Property Practitioner';
            $title = trim((string) $agent->name) !== ''
                ? "{$agent->name} — {$designation} at {$agencyName}"
                : $title;

            // Pre-warm the cache so the crawler's og:image fetch hits a ready file;
            // the hash in the URL cache-busts WhatsApp when the card changes.
            try {
                $this->cards->resolve($agent);
                $image = route('seller-outreach.public.agent-card', $agent->id)
                    . '?v=' . $this->cards->cacheKey($agent);
                $isCard = true;
            } catch (\Throwable) {
                // keep the agency-logo fallback — never break the page over a card render
            }
        }

        return [
            'title'       => $title,
            'description' => "Live buyer demand, recent sales and property values from {$agencyName}. Manage your area-update preferences — opt in or out anytime.",
            'image'       => $image,
            'card'        => $isCard,
            'url'         => $url,
        ];
    }
}
