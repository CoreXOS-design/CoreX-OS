<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v3 - the agent's engine (.ai/specs/road-to-sold.md section 22, 27 Oct 2026).
 *
 *   rts_prospects        one row per agent-property: its stage, next action, due date, parked reason. The unique key IS the
 *                        allocation: a property belongs to one agent at a time (22.6).
 *   rts_prospect_events  append-only history of every placement, stage move, outcome, park and release; every stage count reads it.
 *   rts_park_reasons     the agency's list of why stock is set aside (22.4).
 *   rts_outcome_definitions  + stage, try limit and park rule per outcome (22.7).
 *   rts_list_items       + the prospect a row belongs to, + the structured facts behind the reason line (22.5).
 *   rts_settings         + expired_competitor_lookback_days (30), prospect_stall_days (14).
 *
 * Additive only: nothing existing is altered or dropped, no row is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rts_prospects')) {
            Schema::create('rts_prospects', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('track', 20)->default('sales');
                $table->unsignedBigInteger('user_id');                       // the list owner (an assistant works the agent's)
                $table->unsignedBigInteger('tracked_property_id');
                $table->unsignedBigInteger('prospecting_listing_id')->nullable();
                $table->unsignedBigInteger('property_id')->nullable();

                $table->string('stage', 30)->default('found');
                $table->timestamp('stage_entered_at')->nullable();
                $table->string('selection', 40)->nullable();                 // the source that first offered it
                $table->string('reason', 255)->nullable();
                $table->json('reason_facts')->nullable();

                $table->string('next_action_key', 40)->nullable();
                $table->string('next_action_label', 120)->nullable();
                $table->date('next_action_at')->nullable();
                $table->unsignedTinyInteger('tries')->default(0);           // consecutive tries of the current action
                $table->unsignedBigInteger('last_outcome_id')->nullable();

                $table->timestamp('parked_at')->nullable();
                $table->date('parked_until')->nullable();
                $table->unsignedBigInteger('park_reason_id')->nullable();
                $table->string('park_reason_label', 120)->nullable();       // kept even if the reason is archived later
                $table->string('park_note', 255)->nullable();
                $table->string('parked_from_stage', 30)->nullable();

                $table->timestamp('closed_at')->nullable();
                $table->string('closed_reason', 40)->nullable();
                $table->timestamp('released_at')->nullable();
                $table->unsignedBigInteger('released_by_user_id')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'tracked_property_id'], 'rts_prospects_property_unique');
                $table->index(['agency_id', 'track', 'user_id', 'stage'], 'rts_prospects_stage_idx');
                $table->index(['user_id', 'next_action_at'], 'rts_prospects_due_idx');
                $table->index(['agency_id', 'track', 'parked_until'], 'rts_prospects_parked_idx');
                $table->index('prospecting_listing_id', 'rts_prospects_listing_idx');
            });
        }

        if (! Schema::hasTable('rts_prospect_events')) {
            Schema::create('rts_prospect_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->string('track', 20)->default('sales');
                $table->unsignedBigInteger('prospect_id');
                $table->unsignedBigInteger('user_id');                       // the agent the prospect belongs to
                $table->unsignedBigInteger('actor_user_id')->nullable();     // who caused it (null = the system)
                $table->timestamp('occurred_at');
                $table->string('kind', 20);                                  // placed stage outcome next_action park unpark release close credit
                $table->string('from_stage', 30)->nullable();
                $table->string('to_stage', 30)->nullable();
                $table->unsignedBigInteger('outcome_id')->nullable();
                $table->string('event_class', 190)->nullable();
                $table->string('subject_type', 120)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('note', 255)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['agency_id', 'track', 'to_stage', 'occurred_at'], 'rts_prospect_events_stage_idx');
                $table->index(['prospect_id', 'occurred_at'], 'rts_prospect_events_prospect_idx');
                // one stage entry per prospect, stage and subject: a retried event writes nothing twice
                $table->unique(['prospect_id', 'kind', 'to_stage', 'subject_type', 'subject_id'], 'rts_prospect_events_once');
            });
        }

        if (! Schema::hasTable('rts_park_reasons')) {
            Schema::create('rts_park_reasons', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->string('track', 20)->default('sales');
                $table->string('key', 40);
                $table->string('label', 120);
                $table->unsignedSmallInteger('default_revisit_days')->default(90);
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'key'], 'rts_park_reasons_key_unique');
            });
        }

        Schema::table('rts_outcome_definitions', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_outcome_definitions', 'stage_after')) {
                $table->string('stage_after', 30)->nullable()->after('list_tag');
            }
            if (! Schema::hasColumn('rts_outcome_definitions', 'max_tries')) {
                $table->unsignedTinyInteger('max_tries')->nullable()->after('stage_after');
                $table->string('after_max_tries', 20)->nullable()->after('max_tries');   // park | manager_report
                $table->string('park_reason_key', 40)->nullable()->after('after_max_tries');
                $table->json('choice_months')->nullable()->after('park_reason_key');
                $table->unsignedTinyInteger('default_months')->nullable()->after('choice_months');
                $table->unsignedSmallInteger('fallback_days')->nullable()->after('default_months');
            }
        });

        Schema::table('rts_list_items', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_list_items', 'prospect_id')) {
                $table->unsignedBigInteger('prospect_id')->nullable()->after('property_id');
                $table->json('reason_facts')->nullable()->after('reason');
                $table->index('prospect_id', 'rts_list_items_prospect_idx');
            }
        });

        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'expired_competitor_lookback_days')) {
                $table->unsignedSmallInteger('expired_competitor_lookback_days')->default(30);
            }
            if (! Schema::hasColumn('rts_settings', 'prospect_stall_days')) {
                $table->unsignedSmallInteger('prospect_stall_days')->default(14);
            }
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            foreach (['expired_competitor_lookback_days', 'prospect_stall_days'] as $c) {
                if (Schema::hasColumn('rts_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('rts_list_items', function (Blueprint $table) {
            if (Schema::hasColumn('rts_list_items', 'prospect_id')) {
                $table->dropIndex('rts_list_items_prospect_idx');
                $table->dropColumn(['prospect_id', 'reason_facts']);
            }
        });
        Schema::table('rts_outcome_definitions', function (Blueprint $table) {
            foreach (['stage_after', 'max_tries', 'after_max_tries', 'park_reason_key', 'choice_months', 'default_months', 'fallback_days'] as $c) {
                if (Schema::hasColumn('rts_outcome_definitions', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::dropIfExists('rts_park_reasons');
        Schema::dropIfExists('rts_prospect_events');
        Schema::dropIfExists('rts_prospects');
    }
};
