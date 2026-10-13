<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 (Road to Sold) piece 3 — the agent's daily list. One row per property on one agent's list
 * for one day. Lane 2 owns this table; the page (lane 3) reads it only through
 * RtsListItem::scopeVisibleTo().
 *
 * tracked_property_id is the canonical property key (conductor ruling 13 Oct) and is filled whenever
 * one can be resolved — including for the agent's own CoreX stock. prospecting_listing_id and
 * property_id ride along for display and links. No foreign keys: the project convention for
 * cross-module ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_list_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->date('list_date');
            $table->string('track', 20)->default('sales');

            $table->unsignedBigInteger('tracked_property_id')->nullable();
            $table->unsignedBigInteger('prospecting_listing_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();

            $table->string('selection', 40);                    // a selection key, or 'follow_up'
            $table->string('origin', 12)->default('auto');      // auto | follow_up | added | rollover
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 12)->default('open');      // open | worked | missed | rolled
            $table->string('reason', 255)->nullable();          // plain-English "why suggested"
            $table->unsignedBigInteger('rolled_from_item_id')->nullable();
            $table->timestamp('status_changed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // A property is on a day's list once. NULL tracked ids (rare: no tracked property could be
            // resolved) are de-duplicated in code by listing / property id.
            $table->unique(['agency_id', 'track', 'user_id', 'list_date', 'tracked_property_id'], 'rts_list_items_day_property_unique');
            $table->index(['agency_id', 'track', 'user_id', 'list_date', 'position'], 'rts_list_items_day_idx');
            $table->index(['agency_id', 'track', 'status', 'list_date'], 'rts_list_items_status_idx');
            $table->index('tracked_property_id', 'rts_list_items_tracked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_list_items');
    }
};
