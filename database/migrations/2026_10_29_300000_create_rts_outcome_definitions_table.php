<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 (Road to Sold, lane 3) — the agency's own list of call outcomes.
 *
 * One row per outcome an agent can pick in the "Contacted client" popup. Agency-defined:
 * renamed, added, switched off, re-timed, reordered, archived (soft) — never hard-deleted.
 * `key` is the stable machine name (the seeded eight keep theirs even when renamed); an
 * agency-added outcome gets a generated key. Unique (agency_id, track, key) ignores soft
 * deletes, so create-after-archive RESTORES the trashed row (BUILD_STANDARD §5a).
 *
 * kind:        attempt | conversation   (counted separately everywhere)
 * next_action: return_after_days | return_on_picked_date | return_on_expiry_else_picked
 *              | move_to_list | never_again | calendar_appointment
 * Seed rows are written by RoadToSold\OutcomeDefinitionService::ensureDefaults() (lazy per
 * agency+track) and backfilled below for existing agencies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_outcome_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('track', 20)->default('sales');

            $table->string('key', 60);
            $table->string('label', 120);
            $table->string('kind', 12)->default('attempt');
            $table->string('next_action', 40)->default('return_after_days');
            $table->unsignedSmallInteger('delay_days')->nullable();
            // Label of the tagged list a "move to list" outcome sends the property to.
            $table->string('list_tag', 60)->nullable();
            // Counts toward "N no-answers in a row" (agency setting). A flag, not a key check,
            // because an agency may add its own "phone off" style outcome.
            $table->boolean('counts_as_no_answer')->default(false);
            // Records a call-channel opt-out on the owner contact and marks the property
            // "never suggest" for every agent.
            $table->boolean('records_do_not_contact')->default(false);

            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // seeded by CoreX (still editable)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'track', 'key'], 'rts_outcome_def_unique');
            $table->index(['agency_id', 'track', 'is_active', 'sort_order'], 'rts_outcome_def_list');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_outcome_definitions');
    }
};
