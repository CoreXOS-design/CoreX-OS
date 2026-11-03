<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4 (.ai/specs/road-to-sold.md 23.13 / 23.17): the history of an agent's Start, Pause and Resume. Append-only: one row per press, never
 * edited or deleted (the agent's CURRENT state is rts_agent_starts; this is how it got there). rts_prospect_events cannot hold these - every row there
 * belongs to a property.
 *
 * Additive only: a new table; nothing existing is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rts_agent_start_events')) {
            return;
        }
        Schema::create('rts_agent_start_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('track', 20)->default('sales');
            $table->unsignedBigInteger('user_id');                 // the agent it is about
            $table->unsignedBigInteger('actor_user_id')->nullable();   // who pressed the button
            $table->string('kind', 12);                            // started | paused | resumed
            $table->dateTime('occurred_at');
            $table->date('first_list_date')->nullable();           // started
            $table->date('paused_from')->nullable();               // paused
            $table->date('paused_until')->nullable();              // paused (resumed: the date it had run to)
            $table->string('reason', 120)->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'user_id', 'occurred_at'], 'rts_agent_start_events_agent_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_agent_start_events');
    }
};
