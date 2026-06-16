<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\MarketingSuppression;
use App\Services\SellerOutreach\MarketingConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Generic, unauthenticated marketing UNSUBSCRIBE page — AT-49.
 *
 * The agency-id path segment is the only context the email-signature footer
 * carries (it is rendered per-agency, so the id is known at send time). The
 * recipient types the email address or phone number they were contacted on;
 * MarketingConsentService resolves a contact when one exists and ALWAYS records
 * an identifier-level suppression row — even on no match — so a future import of
 * that identifier stays blocked ("one opt-out, suppressed everywhere").
 *
 * Privacy: the result page is intentionally identical whether or not a contact
 * matched, so the page never confirms whether an address/number is on file.
 * GET is preview-safe (no write); only the POST acts. Mirrors PublicOptOutController.
 */
final class UnsubscribeController extends Controller
{
    public function __construct(
        private readonly MarketingConsentService $consent,
    ) {}

    private const UNSUBSCRIBE_REASON = 'Self-service unsubscribe page';

    /** GET /unsubscribe/{agency} — entry form, PREVIEW-SAFE (no write). */
    public function show(Request $request, int $agency)
    {
        $agencyName = $this->resolveAgencyName($agency);

        return $this->render($agency, $agencyName, done: false, invalid: false);
    }

    /** POST /unsubscribe/{agency} — suppress the entered identifier. */
    public function submit(Request $request, int $agency)
    {
        $agencyName = $this->resolveAgencyName($agency);

        $identifier = trim((string) $request->input('identifier', ''));
        if ($identifier === '') {
            return $this->render($agency, $agencyName, done: false, invalid: true);
        }

        $this->consent->optOutByIdentifier(
            rawIdentifier: $identifier,
            agencyId:      $agency,
            reason:        self::UNSUBSCRIBE_REASON,
            source:        MarketingSuppression::SOURCE_UNSUBSCRIBE_PAGE,
        );

        // Identical success page regardless of match — never reveal whether the
        // identifier was on file. An unparseable identifier still lands here, but
        // optOutByIdentifier records nothing for it; that is acceptable — the user
        // is told their request is processed without leaking record existence.
        return $this->render($agency, $agencyName, done: true, invalid: false);
    }

    private function resolveAgencyName(int $agency): string
    {
        $name = DB::table('agencies')->where('id', $agency)->value('name');
        if ($name === null) {
            abort(404);
        }

        return (string) $name;
    }

    private function render(int $agency, string $agencyName, bool $done, bool $invalid)
    {
        return response()
            ->view('seller-outreach.unsubscribe', [
                'agencyId'   => $agency,
                'agencyName' => $agencyName,
                'done'       => $done,
                'invalid'    => $invalid,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
