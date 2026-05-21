<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Phase A3 follow-up — relax `agent_activity_events.user_id` to nullable.
 *
 * The Phase A1 migration declared user_id NOT NULL (via foreignId()), but
 * legitimate system-triggered events (AINarrativeFailedFallback,
 * ClaimAutoReleased, ClaimFlaggedAsStale, MarketDataPointSuperseded,
 * MarketReportSpotCheckFlagged, TrackedPropertyMerged when system-merged)
 * have no actor — actorUserId() returns null. The LogAgentActivity listener
 * needs to write those rows without inventing a fake user_id.
 *
 * Drop the FK first (so we can change the column), relax to nullable,
 * re-add the FK with nullOnDelete (matches the semantic: a deleted user
 * shouldn't take the audit log with them).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_activity_events', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('agent_activity_events', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('agent_activity_events', function (Blueprint $table) {
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Reverse only if there are no NULL user_id rows — otherwise the
        // NOT NULL restore would fail. Refuse to roll back if data exists
        // that the restore can't represent.
        $nullCount = \DB::table('agent_activity_events')->whereNull('user_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$nullCount} agent_activity_events row(s) have NULL user_id. "
                . 'Delete or backfill them before re-attempting this rollback.'
            );
        }

        Schema::table('agent_activity_events', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('agent_activity_events', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('agent_activity_events', function (Blueprint $table) {
            $table->foreign('user_id')
                  ->references('id')->on('users')
                  ->cascadeOnDelete();
        });
    }
};
