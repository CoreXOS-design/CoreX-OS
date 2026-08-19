<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-307 — close the property-status vocabulary.
 *
 * One row (and any like it on other environments) carries status 'sales_listing'
 * — a property_status settings item that was DE-ACTIVATED, leaving a value the new
 * membership guard (PropertyObserver / AllowedPropertyStatus) would reject. Remap
 * it to 'active' (a valid, active on-market status; statusBadge() derives the
 * For Sale / To Let display from listing_type, so this reads correctly for both).
 *
 * Raw update on purpose — no observer, no syndication/audit side-effects, and
 * idempotent (a re-run matches nothing).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('properties')
            ->whereRaw("LOWER(TRIM(status)) = 'sales_listing'")
            ->update(['status' => 'active']);
    }

    public function down(): void
    {
        // Data normalisation — we do not restore an out-of-vocabulary value.
    }
};
