<?php

declare(strict_types=1);

namespace App\Support\Sales;

/**
 * The one place the customer-facing words for a deal's real-world stage
 * live. 2026-08-25 fix — SuburbReportDataService::achievedSalesFromDr2()
 * called every deals_v2 row "achieved" regardless of stage, so a deal that
 * was merely under offer was presented to a seller as sold.
 *
 * Johan's ruling, verbatim: "pending = under offer, granted and
 * registered = sold." SOLD = status 'granted' (every suspensive condition
 * met — the offer is unconditional) OR registered/transferred (DR1
 * deals.registration_date / DR2 deals_v2.actual_registration populated).
 * UNDER OFFER = an offer exists and neither of the above applies yet.
 * FLAGGED, not resolved here: a granted deal can still be cancelled before
 * registration (DealPipelineService's own pipeline allows it) — Johan's
 * call to make with that risk in view. Johan is deciding the exact
 * customer-facing wording; renaming it is a one-line change here, not a
 * re-test of every query that reports a sale.
 */
final class SaleStageLabel
{
    /** Granted (unconditional) or registered/transferred. Never applies to a merely-offered deal. */
    public const SOLD = 'sold';

    /** An offer exists; not yet granted or registered. Never "sold" or "achieved". */
    public const UNDER_OFFER = 'under offer';
}
