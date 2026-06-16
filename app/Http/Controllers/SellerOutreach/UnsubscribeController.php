<?php

declare(strict_types=1);

namespace App\Http\Controllers\SellerOutreach;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MarketingSuppression;
use App\Services\Communications\ContactIdentifierResolver;
use App\Services\SellerOutreach\MarketingConsentService;
use App\Services\SellerOutreach\TransactionStateService;
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
        private readonly ContactIdentifierResolver $resolver,
        private readonly TransactionStateService $transactions,
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

        // Marketing is ALWAYS suppressed (matched contact fully opted out;
        // unmatched identifier still records a suppression row for future imports).
        $this->consent->optOutByIdentifier(
            rawIdentifier: $identifier,
            agencyId:      $agency,
            reason:        self::UNSUBSCRIBE_REASON,
            source:        MarketingSuppression::SOURCE_UNSUBSCRIBE_PAGE,
        );

        // AT-50 — same transaction gate as the per-send screen: if the resolved
        // contact is in a LIVE sale, transactional comms continue (the page says
        // so). Non-transaction / unmatched identifiers get the full-suppression
        // message. We never name the sale here (this page is identifier-only, not
        // token-authenticated).
        $contact = $this->resolver->resolve($identifier, $agency);
        $inLiveTransaction = $contact instanceof Contact
            && $this->transactions->isInLiveTransaction($agency, $contact);

        return $this->render($agency, $agencyName, done: true, invalid: false, inLiveTransaction: $inLiveTransaction);
    }

    private function resolveAgencyName(int $agency): string
    {
        $name = DB::table('agencies')->where('id', $agency)->value('name');
        if ($name === null) {
            abort(404);
        }

        return (string) $name;
    }

    private function render(int $agency, string $agencyName, bool $done, bool $invalid, bool $inLiveTransaction = false)
    {
        return response()
            ->view('seller-outreach.unsubscribe', [
                'agencyId'          => $agency,
                'agencyName'        => $agencyName,
                'done'              => $done,
                'invalid'           => $invalid,
                'inLiveTransaction' => $inLiveTransaction,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
