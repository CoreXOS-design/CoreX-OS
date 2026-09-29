<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * cleanliness) the migration reports them and ABORTS without touching any
 * data or adding the index. Deduplicating real (agency-owned, not QA
 * fixture) rows is Johan's call, not something a migration does silently.
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
            $summary = $duplicates->map(fn ($d) => "agency_id={$d->agency_id} deal_no={$d->deal_no} count={$d->c}")->implode('; ');
            throw new \RuntimeException(
                'add_unique_index_to_deals_deal_no ABORTED — duplicate (agency_id, deal_no) rows already exist, '
                . 'so a unique index cannot be added without first resolving them (see .ai/specs/deals.md '
                . '"Duplicate-deal fix" section for the required resolution — this is NOT automatic and NOT '
                . 'part of this migration). No data changed, no index added. Duplicates found: ' . $summary
            );
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
