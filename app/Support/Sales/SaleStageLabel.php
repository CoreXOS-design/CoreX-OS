<?php

declare(strict_types=1);

namespace App\Support\Sales;

/**
 * The one place the customer-facing words for a deal's real-world stage
 * live. 2026-08-25 fix — SuburbReportDataService::achievedSalesFromDr2()
 * called every deals_v2 row "achieved" regardless of stage, so a deal that
 * was merely under offer was presented to a seller as sold. Registered =
 * DR1 deals.registration_date OR DR2 deals_v2.actual_registration
 * populated — the exact signal DealsRegisteredProvider and
 * InternalDealsAdapter already use correctly elsewhere in this codebase.
 * Johan is deciding the exact customer-facing wording; renaming it is a
 * one-line change here, not a re-test of every query that reports a sale.
 */
final class SaleStageLabel
{
    /** Registered/transferred — the only stage that may ever be called "sold". */
    public const SOLD = 'sold';

    /** An offer exists; nothing has registered yet. Never "sold" or "achieved". */
    public const UNDER_OFFER = 'under offer';
}
