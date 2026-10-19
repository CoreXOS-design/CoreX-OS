<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hard DB-layer backstop for #1826/#1827: even with the idempotency token and
 * the race-safe AtomicSequenceService allocator in place, this is the last
 * line — no two deals in the same agency may ever carry the same deal_no,
 * full stop, enforced by MySQL itself rather than trusted to application
 * code alone (includes soft-deleted rows: a deal_no is never reused, by
 * design — see the sequence_counters backfill migration).
 *
 * Self-checking, never silently destructive: if duplicates already exist
 * (this is EXPECTED to be a live-only risk — QA1 was checked clean during
 * the investigation, but this migration runs on whatever environment
 * actually applies it, live included, and must not assume QA1's own
 * cleanliness) the migration reports them (log + console line) and SKIPS
 * adding the index without touching any data - it no longer throws (audit
 * fix 2026-10-18), because a throw halted the entire deploy. Deduplicating
 * real (agency-owned, not QA fixture) rows is Johan's call, not something
 * a migration does silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('deals')
            ->select('agency_id', 'deal_no', DB::raw('COUNT(*) as c'))
            ->whereNotNull('deal_no')
            ->groupBy('agency_id', 'deal_no')
            ->having('c', '>', 1)
            ->get();

        if ($duplicates->isNotEmpty()) {
            // Audit fix 2026-10-18: this used to THROW here, which stopped the whole `migrate` run at
            // step 4 of ~225 on any database with duplicate deal numbers (the real live server is the
            // one that has them - #1826/#1827). That left a half-migrated schema under release code.
            // The index is dropped again by 2026_09_29_203059_drop_duplicate_deal_fix_objects anyway,
            // so skipping it is lossless: report loudly, change nothing, carry on.
            $summary = $duplicates->map(fn ($d) => "agency_id={$d->agency_id} deal_no={$d->deal_no} count={$d->c}")->implode('; ');
            $message = 'add_unique_index_to_deals_deal_no SKIPPED - duplicate (agency_id, deal_no) rows already exist, '
                . 'so the unique index was NOT added. No data changed. Duplicates found: ' . $summary;
            Log::warning($message);
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                echo '  ' . $message . PHP_EOL;
            }

            return;
        }

        // Already present (e.g. partially-applied database): nothing to do.
        if (DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'deals')
            ->where('index_name', 'deals_agency_id_deal_no_unique')
            ->exists()) {
            return;
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->unique(['agency_id', 'deal_no']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropUnique(['agency_id', 'deal_no']);
        });
    }
};
