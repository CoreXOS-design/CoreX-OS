<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 (.ai/specs/road-to-sold.md 23.13, 23.17): activation and pause.
 *
 *   rts_agent_starts            one row per agent (agency, track, user): when they pressed "Start my Road to Sold", the first working day their list
 *                               loads, and an optional pause (from / until / reason / who set it). Soft deletes; never a hard delete.
 *   rts_settings.agent_start_required   the agency switch (default ON): lists load only for agents who have started.
 *
 * Additive only: nothing existing is altered or dropped, no row is rewritten. Agents who already work Road to Sold (any list item, prospect or
 * outcome) count as started without a row (ActivationService), so switching this on changes nothing for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rts_agent_starts')) {
            Schema::create('rts_agent_starts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('track', 20)->default('sales');
                $table->unsignedBigInteger('user_id');
                $table->dateTime('started_at')->nullable();
                $table->unsignedBigInteger('started_by_user_id')->nullable();
                $table->date('first_list_date')->nullable();
                $table->date('paused_from')->nullable();
                $table->date('paused_until')->nullable();
                $table->string('pause_reason', 120)->nullable();
                $table->unsignedBigInteger('paused_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'user_id'], 'rts_agent_starts_agent_unique');
                $table->index(['agency_id', 'paused_until'], 'rts_agent_starts_paused_idx');
            });
        }

        if (! Schema::hasColumn('rts_settings', 'agent_start_required')) {
            Schema::table('rts_settings', function (Blueprint $table) {
                $table->boolean('agent_start_required')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rts_settings', 'agent_start_required')) {
            Schema::table('rts_settings', function (Blueprint $table) {
                $table->dropColumn('agent_start_required');
            });
        }
        Schema::dropIfExists('rts_agent_starts');
    }
};
