<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Events\SellerOutreach\OptOutRecorded;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Services\SellerOutreach\SellerOutreachOptOutService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public (unauthenticated) self-service marketing opt-out — AT-49.
 *
 * The per-send opt_out_token IS the credential. The recipient taps the link in
 * their outreach message, lands on a branded confirm page (GET, NO write — so
 * WhatsApp / link-preview crawlers can't opt anyone out), and only an explicit
 * POST records the opt-out. Setting messaging_opt_out_at blocks EVERY agent in
 * the agency from sending further marketing (the existing send gate); it does
 * NOT touch any operational/transactional comms.
 *
 * Mirrors PublicLandingController: strict token regex, 404 on miss, noindex.
 */
final class PublicOptOutController extends Controller
{
    public function __construct(
        private readonly SellerOutreachOptOutService $optOut,
    ) {}

    private const OPT_OUT_REASON = 'Self-service opt-out link';

    /**
     * GET /outreach/opt-out/{token}
     *
     * Renders the confirm page. PREVIEW-SAFE: performs no write.
     */
    public function show(Request $request, string $token)
    {
        $send = $this->resolveSend($token);
        $contact = $this->resolveContact($send);

        return $this->render($send, alreadyOptedOut: $contact->messaging_opt_out_at !== null, done: false);
    }

    /**
     * POST /outreach/opt-out/{token}
     *
     * Records the opt-out for the resolved contact (marketing-wide, agency-wide).
     * Idempotent: an already-opted-out contact still gets the success page and
     * the original opt-out record (timestamp / reason / source) is left intact.
     */
    public function confirm(Request $request, string $token)
    {
        $send = $this->resolveSend($token);
        $contact = $this->resolveContact($send);

        if ($contact->messaging_opt_out_at === null) {
            $this->optOut->recordOptOut(
                agencyId: (int) $send->agency_id,
                contact:  $contact,
                reason:   self::OPT_OUT_REASON,
                send:     $send,
                source:   OptOutRecorded::SOURCE_SELF_SERVICE_LINK,
            );
        }

        return $this->render($send, alreadyOptedOut: true, done: true);
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

    private function render(SellerOutreachSend $send, bool $alreadyOptedOut, bool $done)
    {
        $agencyName = (string) (DB::table('agencies')->where('id', $send->agency_id)->value('name') ?: 'our agency');

        return response()
            ->view('seller-outreach.opt-out', [
                'agencyName'     => $agencyName,
                'token'          => $send->opt_out_token,
                'alreadyOptedOut'=> $alreadyOptedOut,
                'done'           => $done,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
