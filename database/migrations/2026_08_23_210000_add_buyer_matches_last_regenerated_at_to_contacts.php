<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RegenerateBuyerMatchesJob chunking (2026-08-23) — the per-contact "last
 * ATTEMPTED this rotation" cursor for the agency-wide (contactId=null)
 * regenerate path. Same idiom as Property24SyndicationService's
 * p24_activation_last_checked_at, itself modelled on P24StatsService's
 * p24_stats_synced_at (AT-200): stamped for every contact processed in a
 * chunk, success or failure, so a chronically-failing contact still rotates
 * away instead of blocking the rest of the agency from ever completing.
 *
 * Raw ALTER with an explicit ALGORITHM clause rather than the Schema
 * Builder's default (2026-08-23, ahead of the live deploy) — see the
 * companion p24_activation_last_checked_at migration's comment for the full
 * story: ALGORITHM=INPLACE, LOCK=NONE measured 26-38s real execution time on
 * staging's contacts-sized proxy despite "should be instant"; ALGORITHM=
 * INSTANT measured ~300-400ms on both properties and contacts directly.
 * INSTANT cannot be combined with an explicit LOCK= clause — confirmed by
 * testing (MySQL errors), not assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE contacts '
            . 'ADD COLUMN buyer_matches_last_regenerated_at TIMESTAMP NULL DEFAULT NULL, '
            . 'ALGORITHM=INSTANT'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contacts DROP COLUMN buyer_matches_last_regenerated_at, ALGORITHM=INSTANT');
    }
};
