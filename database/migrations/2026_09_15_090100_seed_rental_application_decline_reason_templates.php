<?php

use App\Models\RentalApplicationDeclineReasonTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decline reason templates, 2026-09-15 — one-time seed for every EXISTING
 * agency, same lesson RentalApplicationHighlighter's own backfill migration
 * already learned about real QA1 data: "every existing agency" means every
 * existing, non-archived one — the agencies table also carries soft-deleted
 * isolated-test-agency fixtures from other lanes' own verification work,
 * and an archived agency needs no live decline settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $agencyIds = DB::table('agencies')->whereNull('deleted_at')->pluck('id');

        foreach ($agencyIds as $agencyId) {
            RentalApplicationDeclineReasonTemplate::seedDefaultsFor((int) $agencyId);
        }
    }

    public function down(): void
    {
        DB::table('rental_application_decline_reason_templates')->truncate();
    }
};
