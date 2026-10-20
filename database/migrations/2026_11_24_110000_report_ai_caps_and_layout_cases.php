<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report recogniser — AI cost protection. Spec: .ai/specs/mic-complete-spec.md §8.9
 * ("AI cost protection: caps and layout cases").
 *
 *  - market_report_ai_usage: counters. One row per agency per MONTH (period_key 'YYYY-MM')
 *    and one platform row per DAY (agency_id 0, period_key 'YYYY-MM-DD'). Counters only —
 *    nothing here is agency content, so a reset zeroes the count and stamps last_reset_at.
 *  - market_report_layout_cases: one row per unknown layout that could not be AI-read
 *    (cap reached / AI failed / AI unsure). Later uploads in the same layout, from any
 *    agency, queue under the case instead of calling the AI again. GLOBAL on purpose
 *    (anchors are fixed headings, never figures). Archive only (soft delete).
 *  - market_reports.layout_case_id: which case a queued report belongs to.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('market_report_ai_usage')) {
            Schema::create('market_report_ai_usage', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');          // 0 = the platform-wide daily row
                $table->string('period_key', 10);                 // 'YYYY-MM' (agency) | 'YYYY-MM-DD' (platform)
                $table->unsignedInteger('reads_count')->default(0);
                $table->timestamp('cap_alert_sent_at')->nullable(); // platform row: the once-a-day "AI read cap hit" alert
                $table->timestamp('last_reset_at')->nullable();     // agency row: a CoreX layout approval reset the counter
                $table->timestamps();
                $table->unique(['agency_id', 'period_key'], 'uq_mrau_agency_period');
            });
        }

        if (!Schema::hasTable('market_report_layout_cases')) {
            Schema::create('market_report_layout_cases', function (Blueprint $table) {
                $table->id();
                $table->char('fingerprint', 64);
                $table->json('anchors');
                $table->string('status', 20)->default('open');     // open | learned | resolved
                $table->unsignedBigInteger('first_report_id')->nullable();
                $table->unsignedBigInteger('first_agency_id')->nullable(); // audit only
                $table->unsignedBigInteger('learned_layout_id')->nullable();
                $table->unsignedInteger('ai_attempts')->default(0);
                $table->string('last_code', 30)->nullable();
                $table->text('last_note')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status', 'fingerprint'], 'idx_mrlc_status_fp');
            });
        }

        Schema::table('market_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('market_reports', 'layout_case_id')) {
                $table->unsignedBigInteger('layout_case_id')->nullable()->after('learned_layout_id');
                $table->index('layout_case_id', 'idx_mr_layout_case');
            }
        });
    }

    public function down(): void
    {
        Schema::table('market_reports', function (Blueprint $table) {
            if (Schema::hasColumn('market_reports', 'layout_case_id')) {
                $table->dropIndex('idx_mr_layout_case');
                $table->dropColumn('layout_case_id');
            }
        });
        Schema::dropIfExists('market_report_layout_cases');
        Schema::dropIfExists('market_report_ai_usage');
    }
};
