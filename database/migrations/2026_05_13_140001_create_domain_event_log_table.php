<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_event_log', function (Blueprint $table) {
            $table->bigIncrements('id');

            // UUID v4 per event emission. UNIQUE so the same event can never be
            // double-logged (defense against listener double-fire bugs).
            $table->char('event_id', 36);

            // UUID v4 shared by all events in a user-action cascade. NULL when
            // the event is the root of its own chain.
            $table->char('trace_id', 36)->nullable();

            // Fully-qualified event class name, e.g. App\Events\Property\PropertyCreated.
            $table->string('event_name', 150);

            // Tenancy filter. NULL for system-level events not tied to an agency.
            $table->unsignedBigInteger('agency_id')->nullable();

            // Auth::id() at emit time. NULL for system-emitted events.
            $table->unsignedBigInteger('actor_user_id')->nullable();

            // Polymorphic subject — the primary entity the event is about.
            $table->string('subject_type', 150)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Serialised event payload + free-form context.
            $table->json('payload_snapshot')->nullable();
            $table->json('context')->nullable();

            // Microsecond precision — events from the same request can fire same-second.
            $table->dateTime('occurred_at', 6);

            // When the log row was written (typically equal to occurred_at).
            $table->timestamp('created_at')->useCurrent();

            $table->unique('event_id', 'dom_evt_event_id_unique');
            $table->index('trace_id', 'dom_evt_trace_idx');
            $table->index('event_name', 'dom_evt_name_idx');
            $table->index('agency_id', 'dom_evt_agency_idx');
            $table->index('actor_user_id', 'dom_evt_actor_idx');
            $table->index(['subject_type', 'subject_id'], 'dom_evt_subject_idx');
            $table->index('occurred_at', 'dom_evt_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_event_log');
    }
};
