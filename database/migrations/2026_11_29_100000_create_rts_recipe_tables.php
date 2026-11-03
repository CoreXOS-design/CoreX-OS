<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Road to Sold v2 phase 1: the engine's tables (road-to-sold.md section 5.4, section 14 phase 1).
 *
 * The recipe is DATA per agency and per track: 13 steps, the tasks inside them, the levels an agent works
 * through and which tasks (with what target and points) belong to each level. A completion is one occurrence
 * of a task, with the points SNAPSHOTTED when it is written (section 3.4) so editing a task's points later
 * only changes the future.
 *
 * Built here: rts_steps, rts_tasks, rts_levels, rts_level_tasks, rts_user_task_overrides,
 * rts_task_completions, rts_task_completion_records. NOT built here (their phases): rts_agent_levels and
 * rts_level_signoffs (phase 4), the funnel settings (phase 5), rts_referrals (phase 9).
 *
 * Every table carries agency_id NOT NULL and soft deletes (no hard deletes). The unique indexes ignore soft
 * deletes, so every writer restores a trashed row instead of inserting a duplicate (BUILD_STANDARD 5a).
 *
 * rts_task_completions.idempotency_key is the one-real-event-one-completion rule (section 5.1 / 7.5): a hash
 * of (user, task, period start, subject) - or of the event id when the event names no record - so the same
 * record can never credit the same task twice in a period, and one event delivered twice completes once.
 *
 * Nothing is seeded here: an agency's recipe is created on first use (RecipeService::start), never on read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rts_steps')) {
            Schema::create('rts_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->string('track', 20)->default('sales');
                $table->string('key', 40);
                $table->string('group', 20);              // get_stock | sell_stock
                $table->unsignedSmallInteger('position')->default(0);
                $table->string('label', 120);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'key'], 'rts_steps_unique');
                $table->index(['agency_id', 'track', 'position'], 'rts_steps_order_idx');
            });
        }

        if (! Schema::hasTable('rts_tasks')) {
            Schema::create('rts_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->string('track', 20)->default('sales');
                $table->foreignId('step_id')->constrained('rts_steps');
                $table->string('key', 60);
                $table->string('label', 160);
                $table->string('type', 10);                // core (completed by an event) | custom (captured)
                $table->string('event_key', 190)->nullable();
                $table->json('event_filter')->nullable();
                $table->string('recurrence_unit', 10)->default('day');   // hour | day | week | month
                $table->string('capture_kind', 10)->default('none');     // none | property | contact | both
                $table->string('evidence_required', 20)->default('none'); // none | note | note_or_photo
                $table->boolean('is_system')->default(false);            // seeded neutral default
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unsignedBigInteger('legacy_activity_definition_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'key'], 'rts_tasks_unique');
                $table->index(['agency_id', 'event_key', 'is_active'], 'rts_tasks_event_idx');
                $table->index(['agency_id', 'track', 'step_id', 'sort_order'], 'rts_tasks_order_idx');
            });
        }

        if (! Schema::hasTable('rts_levels')) {
            Schema::create('rts_levels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->string('track', 20)->default('sales');
                $table->unsignedSmallInteger('number');
                $table->string('name', 80);
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'number'], 'rts_levels_unique');
            });
        }

        if (! Schema::hasTable('rts_level_tasks')) {
            Schema::create('rts_level_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->foreignId('level_id')->constrained('rts_levels');
                $table->foreignId('task_id')->constrained('rts_tasks');
                $table->unsignedInteger('target')->default(0);
                $table->decimal('points', 12, 2)->default(0);
                $table->boolean('required_for_signoff')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['level_id', 'task_id'], 'rts_level_tasks_unique');
                $table->index(['agency_id', 'task_id'], 'rts_level_tasks_task_idx');
            });
        }

        if (! Schema::hasTable('rts_user_task_overrides')) {
            Schema::create('rts_user_task_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->foreignId('user_id')->constrained('users');
                $table->foreignId('task_id')->constrained('rts_tasks');
                $table->unsignedBigInteger('level_id')->nullable();
                // level_id is nullable (an override for every level); a unique index over a nullable column would allow
                // duplicates in MySQL, so the "which level" part of the key is stored as a plain number (0 = every level).
                $table->unsignedBigInteger('level_key')->default(0);
                $table->unsignedInteger('target')->nullable();
                $table->decimal('points', 12, 2)->nullable();
                $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['user_id', 'task_id', 'level_key'], 'rts_user_task_overrides_unique');
                $table->index(['agency_id', 'task_id'], 'rts_user_task_overrides_task_idx');
            });
        }

        if (! Schema::hasTable('rts_task_completions')) {
            Schema::create('rts_task_completions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->foreignId('user_id')->constrained('users');                       // the list owner
                $table->foreignId('recorded_by_user_id')->constrained('users');           // who actually did it - always set
                $table->string('recorded_by_kind', 10);                                    // agent | assistant | system
                $table->string('track', 20)->default('sales');
                $table->foreignId('task_id')->constrained('rts_tasks');
                $table->foreignId('step_id')->constrained('rts_steps');
                $table->unsignedBigInteger('level_id')->nullable();
                $table->dateTime('occurred_at');
                $table->string('period_unit', 10);
                $table->dateTime('period_start');
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('points_awarded', 12, 2)->default(0);                      // snapshot
                $table->decimal('points_legacy', 12, 2)->nullable();                       // migration only
                $table->string('state', 12)->default('confirmed');                         // provisional | confirmed | revoked | overridden
                $table->string('source', 12);                                              // auto_event | captured | manual | migrated
                $table->string('subject_type', 190)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('event_id', 40)->nullable();
                $table->string('idempotency_key', 64);
                $table->text('evidence_note')->nullable();
                $table->json('evidence')->nullable();
                $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->string('revoke_reason', 255)->nullable();
                $table->json('override_audit')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique('idempotency_key', 'rts_task_completions_idem_unique');
                $table->index(['agency_id', 'user_id', 'occurred_at'], 'rts_task_completions_user_idx');
                $table->index(['agency_id', 'task_id', 'period_start'], 'rts_task_completions_task_idx');
                $table->index(['subject_type', 'subject_id'], 'rts_task_completions_subject_idx');
            });
        }

        if (! Schema::hasTable('rts_task_completion_records')) {
            Schema::create('rts_task_completion_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->foreignId('completion_id')->constrained('rts_task_completions');
                $table->string('record_type', 190);
                $table->unsignedBigInteger('record_id');
                $table->timestamps();

                $table->unique(['completion_id', 'record_type', 'record_id'], 'rts_task_completion_records_unique');
                $table->index(['record_type', 'record_id'], 'rts_task_completion_records_record_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_task_completion_records');
        Schema::dropIfExists('rts_task_completions');
        Schema::dropIfExists('rts_user_task_overrides');
        Schema::dropIfExists('rts_level_tasks');
        Schema::dropIfExists('rts_levels');
        Schema::dropIfExists('rts_tasks');
        Schema::dropIfExists('rts_steps');
    }
};
