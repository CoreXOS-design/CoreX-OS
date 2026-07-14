<?php

use App\Services\Communications\ManualSendOwnerBackfiller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AT-246 — backfill `communications.owner_user_id` for historic manual agent
 * sends whose owner is recorded only in `source_ref` ('manual:user:NN').
 *
 * Root cause was fixed at the write path (OutboundProvisionalLogger::log now
 * stamps owner_user_id); this heals the rows written before that. Reversible and
 * guarded — see App\Services\Communications\ManualSendOwnerBackfiller. Every
 * healed id is recorded in the marker ledger so down() re-NULLs exactly those
 * (and only those), which matters because post-fix rows share the same shape.
 *
 * No hard deletes; no NOT NULL constraint added — agency-level mailbox/device
 * ingest legitimately writes a NULL owner (AT-122 contract), so the invariant
 * "a manual agent send has an owner" is enforced at the writer, not the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(ManualSendOwnerBackfiller::MARKER_TABLE)) {
            Schema::create(ManualSendOwnerBackfiller::MARKER_TABLE, function (Blueprint $table) {
                // Heal ledger: the communication ids this backfill set an owner on,
                // so the reversal is exact. Not tenant-owned (ops audit) → no agency_id.
                $table->unsignedBigInteger('communication_id')->primary();
                $table->timestamp('created_at')->nullable();
            });
        }

        // heal() returns ['updated', 'skipped_no_user', 'total_null_manual'] — the
        // before/after report the ticket asks for (total_null_manual = before;
        // updated = healed; skipped_no_user = still-null, un-healable users gone).
        $result = app(ManualSendOwnerBackfiller::class)->heal();

        Log::info('AT-246 backfill communications.owner_user_id', $result);
    }

    public function down(): void
    {
        if (Schema::hasTable(ManualSendOwnerBackfiller::MARKER_TABLE)) {
            $reverted = app(ManualSendOwnerBackfiller::class)->revert();
            Log::info('AT-246 backfill reversed communications.owner_user_id', ['reverted' => $reverted]);
            Schema::dropIfExists(ManualSendOwnerBackfiller::MARKER_TABLE);
        }
    }
};
