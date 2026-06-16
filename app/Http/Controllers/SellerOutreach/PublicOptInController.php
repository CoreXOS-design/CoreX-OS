<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public (unauthenticated) self-service marketing OPT-IN / re-consent — AT-49.
 *
 * Reuses the same per-send token as the opt-out link (it resolves the same
 * send/contact; only the action differs). GET is preview-safe (no write); POST
 * runs the full reverse through MarketingConsentService::optInContact — re-grant
 * consent, clear the opt-out triplet, clear the channel booleans, lift the
 * identifier suppression. Mirrors PublicOptOutController.
 */
final class PublicOptInController extends Controller
{
    public function __construct(
        private readonly MarketingConsentService $consent,
    ) {}

    private const OPT_IN_REASON = 'Self-service opt-in link';

    /** GET /outreach/opt-in/{token} — confirm page, PREVIEW-SAFE (no write). */
    public function show(Request $request, string $token)
    {
        $send = $this->resolveSend($token);
        $contact = $this->resolveContact($send);

        return $this->render($send, alreadyOptedIn: $contact->messaging_opted_in_at !== null && $contact->messaging_opt_out_at === null, done: false);
    }

    /** POST /outreach/opt-in/{token} — re-consent. Idempotent. */
    public function confirm(Request $request, string $token)
    {
        $send = $this->resolveSend($token);
        $contact = $this->resolveContact($send);

        $this->consent->optInContact(
            contact:     $contact,
            reason:      self::OPT_IN_REASON,
            actorUserId: $send->agent_id,
            send:        $send,
        );

        return $this->render($send, alreadyOptedIn: true, done: true);
    }

    private function resolveSend(string $token): SellerOutreachSend
    {
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
            abort(404);
        }
    }

    private function render(SellerOutreachSend $send, bool $alreadyOptedIn, bool $done)
    {
        $agencyName = (string) (DB::table('agencies')->where('id', $send->agency_id)->value('name') ?: 'our agency');

        return response()
            ->view('seller-outreach.opt-in', [
                'agencyName'    => $agencyName,
                'token'         => $send->opt_out_token,
                'alreadyOptedIn'=> $alreadyOptedIn,
                'done'          => $done,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
