<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Road to Sold v2 phase 4: levels and sign-off (road-to-sold.md sections 5.4, 8, 13.1, 15 answer 8).
 *
 *   rts_agent_levels      which level an agent works at and where they are in it (active | awaiting_signoff | signed_off). One row per
 *                         (agency, track, agent); created the first time the agent's level is needed for a WRITE, never on read.
 *   rts_level_signoffs    APPEND-ONLY history of every request, approval and return. Nothing updates or deletes a row; a decision is a
 *                         new row. (No soft delete: it is a ledger, like rts_settings_audit.)
 *   rts_settings          levels_signoff_required (on: a manager signs off; off: the agent advances by themselves) and
 *                         level_periods_required (consecutive weeks on target before a sign-off is asked for).
 *   notification_event_types   three keys: sign-off asked (to the managers), signed off, sent back (to the agent).
 *
 * Nothing is seeded and no existing row is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rts_agent_levels')) {
            Schema::create('rts_agent_levels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('track', 20)->default('sales');
                $table->foreignId('user_id')->constrained('users');
                $table->foreignId('level_id')->constrained('rts_levels');
                $table->string('status', 20)->default('active');          // active | awaiting_signoff | signed_off
                $table->dateTime('started_at');
                $table->dateTime('requested_signoff_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['agency_id', 'track', 'user_id'], 'rts_agent_levels_unique');
                $table->index(['agency_id', 'track', 'status', 'requested_signoff_at'], 'rts_agent_levels_queue_idx');
            });
        }

        if (! Schema::hasTable('rts_level_signoffs')) {
            Schema::create('rts_level_signoffs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('track', 20)->default('sales');
                $table->foreignId('user_id')->constrained('users');                 // the agent
                $table->unsignedBigInteger('from_level_id');
                $table->unsignedBigInteger('to_level_id')->nullable();               // set on an approval that moves the agent up
                $table->unsignedBigInteger('decided_by_user_id')->nullable();        // null = the system (a request, or an automatic advance)
                $table->string('decision', 20);                                      // requested | approved | returned
                $table->boolean('automatic')->default(false);                        // approved by the agency's "no sign-off needed" setting
                $table->text('comment')->nullable();
                $table->json('evidence')->nullable();                                // the weeks on target the request was based on
                $table->dateTime('decided_at');
                $table->timestamps();

                $table->index(['agency_id', 'track', 'user_id', 'decided_at'], 'rts_level_signoffs_agent_idx');
            });
        }

        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'levels_signoff_required')) {
                $table->boolean('levels_signoff_required')->default(true);
            }
            if (! Schema::hasColumn('rts_settings', 'level_periods_required')) {
                $table->unsignedTinyInteger('level_periods_required')->default(4);
            }
        });

        if (Schema::hasTable('notification_event_types')) {
            $order = (int) DB::table('notification_event_types')->max('sort_order');
            foreach ([
                ['rts.level_signoff_requested', 'A level is ready for your sign-off', 'An agent in your branch has met their level for the number of weeks your agency asks for and is waiting for you to sign them off.'],
                ['rts.level_signed_off',        'You moved up a level',               'Your manager signed you off and you have moved up to the next level on the Road to Sold recipe.'],
                ['rts.level_returned',          'Your level sign-off was sent back',  'Your manager reviewed your level, left a comment on what to work on and sent it back. The weeks start counting again.'],
            ] as [$key, $label, $description]) {
                if (DB::table('notification_event_types')->where('key', $key)->exists()) {
                    continue;
                }
                DB::table('notification_event_types')->insert([
                    'key' => $key, 'pillar' => 'agent', 'group_label' => 'Road to Sold', 'label' => $label, 'description' => $description,
                    'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                    'supports_in_app' => 1, 'supports_email' => 0, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                    'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->whereIn('key', ['rts.level_signoff_requested', 'rts.level_signed_off', 'rts.level_returned'])
                ->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
        Schema::table('rts_settings', function (Blueprint $table) {
            foreach (['levels_signoff_required', 'level_periods_required'] as $column) {
                if (Schema::hasColumn('rts_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::dropIfExists('rts_level_signoffs');
        Schema::dropIfExists('rts_agent_levels');
    }
};
