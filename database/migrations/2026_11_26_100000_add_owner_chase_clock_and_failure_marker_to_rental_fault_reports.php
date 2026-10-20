<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-20 (M2) - two follow-ups to the owner chase ledger added by 2026_11_20_100100 (that migration is not touched: it is already applied on Staging).
 *
 *  - owner_chase_from: when the chase clock for the CURRENT wait starts, if later than `sent_to_owner_at`. Null = the clock is `sent_to_owner_at` (every fault sent to the
 *    owner from now on). It is set (a) here, to the moment of this migration, for every fault that was ALREADY waiting for the owner and had not yet been reminded - so
 *    the first run after deploy does not email the owner about faults that have been waiting for weeks before the feature existed (their clock starts when it ships) -
 *    and (b) by reopening a decision, which also clears the ledger so the re-opened wait is chased afresh.
 *  - owner_chase_failed_at: set when a chase step could not be sent (mail transport error). The step is NOT recorded as done; it is retried on the next run, and the
 *    failure is written to the fault's history once (this marker stops a long outage writing a line every 30 minutes).
 *
 * Additive, nullable, idempotent. The backfill only ever writes the NEW column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_fault_reports')) {
            return;
        }
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_fault_reports', 'owner_chase_from')) {
                $table->timestamp('owner_chase_from')->nullable();
            }
            if (! Schema::hasColumn('rental_fault_reports', 'owner_chase_failed_at')) {
                $table->timestamp('owner_chase_failed_at')->nullable();
            }
        });

        if (Schema::hasColumn('rental_fault_reports', 'owner_reminder_1_at')) {
            // faults already waiting for the owner and never chased: their clock starts now (a chase already under way keeps its own ledger)
            DB::table('rental_fault_reports')
                ->where('status', 'awaiting_approval')
                ->where('owner_approval_status', 'pending')
                ->whereNotNull('sent_to_owner_at')
                ->whereNull('deleted_at')
                ->whereNull('owner_reminder_1_at')
                ->whereNull('owner_chase_from')
                ->update(['owner_chase_from' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_fault_reports')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                foreach (['owner_chase_failed_at', 'owner_chase_from'] as $col) {
                    if (Schema::hasColumn('rental_fault_reports', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
