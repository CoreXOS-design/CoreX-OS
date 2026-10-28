<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold - the task-to-step mapping an agency can see, change and put back (road-to-sold.md 22.18 / 22.24).
 *
 *  rts_tasks.mapping_default  the step / event the task started on (JSON {step_id, event_key, event_filter, needs_step, note}); null = none recorded.
 *  rts_tasks.needs_step       true = nobody has chosen a step for this task yet ("not mapped - choose a step"); it sits in its old step meanwhile.
 *  rts_task_mapping_audit     append-only: who changed which task's step / event, from what, to what (or restored its default).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rts_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_tasks', 'mapping_default')) {
                $table->json('mapping_default')->nullable()->after('legacy_activity_definition_id');
            }
            if (! Schema::hasColumn('rts_tasks', 'needs_step')) {
                $table->boolean('needs_step')->default(false)->after('mapping_default');
            }
        });

        if (! Schema::hasTable('rts_task_mapping_audit')) {
            Schema::create('rts_task_mapping_audit', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->string('track', 20)->default('sales');
                $table->unsignedBigInteger('task_id')->index();
                $table->string('task_label', 160);
                $table->string('action', 20);   // changed | restored | default_set
                $table->unsignedBigInteger('changed_by_user_id')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamp('changed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_task_mapping_audit');
        Schema::table('rts_tasks', function (Blueprint $table) {
            foreach (['needs_step', 'mapping_default'] as $c) {
                if (Schema::hasColumn('rts_tasks', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
