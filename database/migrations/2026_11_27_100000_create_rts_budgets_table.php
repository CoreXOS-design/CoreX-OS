<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Tracker re-home step 4 (agency-tracker-rehome.md 4.3, 5.7) - the Road to Sold home of budgets.
 *
 * One table, three levels, one row per (agency, track, who, month):
 *   agent  - an agent's personal and business budget for the month (what the Worksheet held), plus the
 *            planning figures the "what the budget needs" calculation works from. A planning figure left
 *            empty means "not set"; nothing is assumed (no agency's numbers are baked in).
 *   branch - what the branch needs for the month (what Monthly Goals / Branch Performance held as branch_budget).
 *   agency - what the whole agency needs for the month (new).
 *
 * scope_key makes "who" a single column the unique index can use (user:12, branch:3, agency), because a
 * unique index over nullable user_id / branch_id would allow duplicates in MySQL.
 *
 * Soft delete only. The unique index ignores soft deletes, so writing a (who, month) that was ever archived
 * RESTORES the trashed row (BUILD_STANDARD 5a) - done in BudgetService, the only writer.
 *
 * legacy_source / legacy_id record which old row a migrated row came from (worksheets / monthly_target_goals),
 * so the move can be audited row by row. The old tables are kept, read-only, never dropped.
 *
 * Also adds the agency's default monthly targets to rts_settings (NULL = none), alongside the weekday default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('track', 20)->default('sales');
            $table->string('level', 10);
            $table->string('scope_key', 40);
            $table->string('period', 7);

            $table->decimal('personal_budget', 12, 2)->default(0);
            $table->decimal('business_budget', 12, 2)->default(0);
            $table->decimal('requirement', 12, 2)->default(0);

            $table->decimal('avg_sale_price', 12, 2)->nullable();
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->decimal('paye_percent', 5, 2)->nullable();
            $table->decimal('agent_split_percent', 5, 2)->nullable();
            $table->decimal('correctly_priced_percent', 5, 2)->nullable();

            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('legacy_source', 30)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'track', 'scope_key', 'period'], 'rts_budgets_unique');
            $table->index(['agency_id', 'track', 'period'], 'rts_budgets_period_idx');
        });

        Schema::table('rts_settings', function (Blueprint $table) {
            $table->json('default_monthly_targets')->nullable()->after('default_weekday_targets');
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->dropColumn('default_monthly_targets');
        });
        Schema::dropIfExists('rts_budgets');
    }
};
