<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 — Road to Sold (.ai/specs/road-to-sold.md §3.1).
 *
 * rts_settings: one row per (agency, track) holding every agency-configurable
 * rule for Road to Sold (and, later, Road to Rented — `track`). A missing row
 * means "defaults"; SettingsService never writes on read.
 *
 * rts_settings_audit: append-only before/after record of each save
 * (same shape as core_match_buyer_state_audit). Never edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('track', 20)->default('sales');

            $table->boolean('lock_agents_to_areas')->default(false);
            $table->boolean('half_day_leave_halves_target')->default(false);
            $table->unsignedSmallInteger('area_refresh_warn_days')->default(7);
            $table->string('missed_items_mode', 20)->default('show_missed');
            $table->json('selections')->nullable();
            $table->unsignedSmallInteger('new_on_market_days')->default(14);
            $table->unsignedSmallInteger('long_on_market_days')->default(90);
            $table->unsignedSmallInteger('owner_known_quantity')->default(3);
            $table->unsignedSmallInteger('stuck_stock_min_days')->default(60);
            $table->unsignedInteger('stuck_stock_max_views')->default(30);
            $table->unsignedInteger('stuck_stock_max_leads')->default(2);
            $table->unsignedSmallInteger('no_answer_drop_after')->default(3);
            $table->unsignedSmallInteger('hold_days')->default(30);
            $table->json('summary_steps')->nullable();
            $table->unsignedSmallInteger('summary_green_from_pct')->default(90);
            $table->unsignedSmallInteger('summary_red_below_pct')->default(70);
            $table->json('default_weekday_targets')->nullable();

            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['agency_id', 'track'], 'rts_settings_agency_track_unique');
        });

        Schema::create('rts_settings_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('track', 20)->default('sales');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->index(['agency_id', 'track', 'id'], 'rts_settings_audit_agency_track_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_settings_audit');
        Schema::dropIfExists('rts_settings');
    }
};
