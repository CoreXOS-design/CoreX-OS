<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 (Road to Sold, lane 3) — the call log. One row per "Contacted client" outcome.
 *
 * Keyed on the CANONICAL property: tracked_properties.id (CLAUDE.md rule 10). property_id and
 * prospecting_listing_id ride alongside for display and links only. The outcome's key / label /
 * kind are copied onto the row so history survives an agency renaming or archiving an outcome.
 *
 * is_current: the newest outcome for a (tracked property, track) is current; recording a new one
 * clears it on the previous row. Follow-ups due = current rows with next_action_at <= the day.
 * no_answer_streak: consecutive no-answer outcomes by this agent on this property, including this
 * one. dropped: this outcome reached the agency's N-in-a-row limit and took the property off the
 * agent's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_contact_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('track', 20)->default('sales');

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();           // the agent
            $table->foreignId('tracked_property_id')->constrained('tracked_properties')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('prospecting_listing_id')->nullable()->constrained('prospecting_listings')->nullOnDelete();
            // rts_list_items.id (lane 2 owns that table — no FK on purpose).
            $table->unsignedBigInteger('list_item_id')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete(); // the owner, when known

            $table->foreignId('outcome_definition_id')->nullable()->constrained('rts_outcome_definitions')->nullOnDelete();
            $table->string('outcome_key', 60);
            $table->string('outcome_label', 120);
            $table->string('kind', 12);                       // attempt | conversation
            $table->text('note')->nullable();

            $table->boolean('valuation_booked')->default(false);
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();

            $table->string('next_action', 40)->default('none');
            $table->date('next_action_at')->nullable();
            $table->string('list_tag', 60)->nullable();

            $table->unsignedSmallInteger('no_answer_streak')->default(0);
            $table->boolean('dropped')->default(false);
            $table->boolean('is_current')->default(true);
            $table->dateTime('occurred_at');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'track', 'user_id', 'occurred_at'], 'rts_outcome_by_agent');
            $table->index(['agency_id', 'track', 'tracked_property_id', 'is_current'], 'rts_outcome_by_property');
            $table->index(['agency_id', 'track', 'user_id', 'is_current', 'next_action_at'], 'rts_outcome_followups');
            $table->index('contact_id', 'rts_outcome_by_contact');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_contact_outcomes');
    }
};
