<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 Part D — Post Scheduler (.ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §D.2).
 *
 * property_marketing_posts.status was a MySQL ENUM('draft','published','failed'). It becomes
 * ENUM('draft','published','failed','scheduled','publishing','cancelled') — a strict superset, so
 * every existing row keeps its value untouched (a MODIFY that only ADDS members never rewrites rows).
 *
 *  - scheduled_for   when CoreX should publish. Stored as a plain DATETIME holding UTC (NOT a
 *                    TIMESTAMP, which MySQL would shift by the session time zone). The model reads
 *                    and writes it through App\Casts\UtcDateTime, so the app-timezone default of
 *                    Eloquent never skews it. Indexed alone and with status for the per-minute
 *                    "what is due?" query.
 *  - failure_reason  plain-language sentence shown to the agent when a scheduled post fails.
 *
 * Idempotent (hasColumn guards) so a re-run on a partly migrated database is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `property_marketing_posts` MODIFY `status` "
            . "ENUM('draft','published','failed','scheduled','publishing','cancelled') "
            . "COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft'"
        );

        Schema::table('property_marketing_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('property_marketing_posts', 'scheduled_for')) {
                $table->dateTime('scheduled_for')->nullable()->after('published_at');
                $table->index('scheduled_for', 'pmp_scheduled_for_idx');
                $table->index(['status', 'scheduled_for'], 'pmp_status_scheduled_for_idx');
            }
            if (! Schema::hasColumn('property_marketing_posts', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('scheduled_for');
            }
        });
    }

    /**
     * Rollback keeps every row valid for the old three-value ENUM: a post that was still waiting
     * (scheduled / cancelled) goes back to a draft, and one that was mid-publish is marked failed.
     */
    public function down(): void
    {
        DB::table('property_marketing_posts')->whereIn('status', ['scheduled', 'cancelled'])->update(['status' => 'draft']);
        DB::table('property_marketing_posts')->where('status', 'publishing')->update(['status' => 'failed']);

        Schema::table('property_marketing_posts', function (Blueprint $table) {
            if (Schema::hasColumn('property_marketing_posts', 'scheduled_for')) {
                $table->dropIndex('pmp_status_scheduled_for_idx');
                $table->dropIndex('pmp_scheduled_for_idx');
                $table->dropColumn('scheduled_for');
            }
            if (Schema::hasColumn('property_marketing_posts', 'failure_reason')) {
                $table->dropColumn('failure_reason');
            }
        });

        DB::statement(
            "ALTER TABLE `property_marketing_posts` MODIFY `status` "
            . "ENUM('draft','published','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft'"
        );
    }
};
