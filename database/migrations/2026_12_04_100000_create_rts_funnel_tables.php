<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Road to Sold v2 phase 5: the funnel, training flags and suggested targets (road-to-sold.md sections 9, 13.1).
 *
 *   rts_settings            flag_min_sample, flag_below_pct, flag_window_days (training flags, 9.3) and suggest_window_weeks,
 *                           suggest_growth_pct (suggested targets, 9.4) - every threshold an agency setting with a neutral default
 *   rts_training_pointers   which training an agency points at for each funnel ratio (a course, a help document, a library document or a
 *                           guided tour). One row per (agency, track, ratio); soft deleted, a re-save restores it.
 *   rts_manager_actions     APPEND-ONLY record of what a manager did about an agent: assigned training, left a coaching note, acknowledged a
 *                           flag, accepted / edited / ignored a suggested target, set or cleared a personal target. A flag is stored nowhere -
 *                           it is derived on every view; this table is the audit of what was DONE about it (9.3).
 *   notification_event_types   rts.training_assigned (to the agent)
 *
 * Nothing is seeded and no existing row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'flag_min_sample')) {
                $table->unsignedSmallInteger('flag_min_sample')->default(20);
            }
            if (! Schema::hasColumn('rts_settings', 'flag_below_pct')) {
                $table->unsignedTinyInteger('flag_below_pct')->default(60);
            }
            if (! Schema::hasColumn('rts_settings', 'flag_window_days')) {
                $table->unsignedSmallInteger('flag_window_days')->default(30);
            }
            if (! Schema::hasColumn('rts_settings', 'suggest_window_weeks')) {
                $table->unsignedTinyInteger('suggest_window_weeks')->default(8);
            }
            if (! Schema::hasColumn('rts_settings', 'suggest_growth_pct')) {
                $table->unsignedTinyInteger('suggest_growth_pct')->default(10);
            }
        });

        if (! Schema::hasTable('rts_training_pointers')) {
            Schema::create('rts_training_pointers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->string('track', 20)->default('sales');
                $table->string('ratio_key', 60);
                $table->string('kind', 10);                      // course | doc | library | tour
                $table->unsignedBigInteger('target_id')->nullable();   // course id / library item id
                $table->string('target_key', 120)->nullable();         // help document slug / tour key
                $table->string('label', 200);                    // the title when it was chosen
                $table->unsignedBigInteger('set_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'ratio_key'], 'rts_training_pointers_unique');
            });
        }

        if (! Schema::hasTable('rts_manager_actions')) {
            Schema::create('rts_manager_actions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('track', 20)->default('sales');
                $table->foreignId('user_id')->constrained('users');          // the agent it is about
                $table->unsignedBigInteger('by_user_id');                    // the manager
                $table->string('kind', 30);                                  // training_assigned | coaching_note | flag_acknowledged | target_set | target_cleared | suggestion_ignored
                $table->string('subject_key', 80);                           // the ratio key, or the task id for a target
                $table->text('note')->nullable();
                $table->integer('value_from')->nullable();                   // targets: before
                $table->integer('value_to')->nullable();                     // targets: after / the suggestion that was ignored
                $table->unsignedBigInteger('command_task_id')->nullable();   // the task created for the agent, when training was assigned
                $table->dateTime('acted_at');
                $table->timestamps();

                $table->index(['agency_id', 'track', 'user_id', 'kind', 'subject_key'], 'rts_manager_actions_lookup_idx');
            });
        }

        if (Schema::hasTable('notification_event_types') && ! DB::table('notification_event_types')->where('key', 'rts.training_assigned')->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => 'rts.training_assigned', 'pillar' => 'agent', 'group_label' => 'Road to Sold', 'label' => 'Training assigned to you',
                'description' => 'Your manager saw a gap in your Road to Sold funnel and assigned you training; a task was added to your list.',
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 0, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', 'rts.training_assigned')->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        Schema::dropIfExists('rts_manager_actions');
        Schema::dropIfExists('rts_training_pointers');
        Schema::table('rts_settings', function (Blueprint $table) {
            foreach (['flag_min_sample', 'flag_below_pct', 'flag_window_days', 'suggest_window_weeks', 'suggest_growth_pct'] as $column) {
                if (Schema::hasColumn('rts_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
