<?php

namespace App\Http\Middleware;

use App\Models\RentalJobCard;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalOwnerHoldService;
use Closure;
use Illuminate\Http\Request;

/**
 * Audit fix C-C5 (19 Oct 2026) - rentals-faults-work-orders.md section 22.7: while the OWNER has a job on hold, EVERY action on the contractor link, the crew job link
 * and the crew page is refused with the plain hold line - not only "Mark work completed". One guard for the whole class of public rental-link POSTs: it resolves the
 * token's target (work order / job card / the crew page's card), asks RentalOwnerHoldService (the one rule), and refuses before any controller runs. Reading the page
 * is never blocked (the page itself says "On hold - do not attend"). The owner's reason is never shown.
 */
class RentalLinkHoldGuard
{
    public const MESSAGE = 'This job is on hold - please do not attend until you hear from us.';

    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }
        $record = RentalSecureAccessToken::withoutGlobalScopes()->where('token_hash', RentalSecureAccessToken::hashToken((string) $request->route('token')))->first();
        $subject = match ($record?->purpose ?: RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER) {
            RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER => $record?->workOrder,
            RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD => $record?->jobCard,
            // the crew page: the card comes from the URL, and only a card of THIS crew's agency is looked at
            RentalSecureAccessToken::PURPOSE_CREW_STANDING => $request->route('card') && $record
                ? RentalJobCard::withoutGlobalScopes()->where('agency_id', $record->agency_id)->find((int) $request->route('card')) : null,
            default => null,
        };
        if (! $record || ! $subject || app(RentalOwnerHoldService::class)->activeFor($subject) === null) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => self::MESSAGE, 'on_hold' => true], 423);
        }

        return back()->withErrors(['work_order' => self::MESSAGE]);
    }
}
