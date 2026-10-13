<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 - .ai/specs/rentals-faults-work-orders.md section 22.7 / 22.12a ("Stop this" / "Query this", stage 3).
 *  - `rental_owner_holds`: the owner pressed "Stop this" - the job is PAUSED BY OWNER until a fresh authority resumes it. Append-only (a hold is closed by
 *    `resumed_at`, never deleted); an ACTIVE hold is one with `resumed_at` null.
 *  - `rental_owner_queries`: the owner's "Query this" question and the agent's answer. Append-only. Questions asked before this build (fault history lines of
 *    type `owner_query`) are carried in once, as unanswered questions.
 *  - the notification event `rental_fault_report.owner_hold` (in-app + email, default on).
 * Idempotent: every step checks before it writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_owner_holds')) {
            Schema::create('rental_owner_holds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('rental_fault_report_id')->nullable()->index();
                $table->unsignedBigInteger('rental_work_order_id')->nullable()->index();
                $table->unsignedBigInteger('rental_authorisation_id')->nullable();      // the standing record the owner stopped
                $table->unsignedBigInteger('stopped_by_contact_id');                    // which owner pressed it
                $table->string('stopped_via', 12);                                      // link (their email link) | portal
                $table->text('reason');
                $table->string('stage_when_stopped', 40)->nullable();
                $table->timestamp('stopped_at');
                $table->timestamp('contractor_told_at')->nullable();                    // the "On hold - do not attend" email went
                $table->string('contractor_told_note', 255)->nullable();                // who was told / why nobody could be emailed
                $table->timestamp('resumed_at')->nullable();
                $table->string('resume_basis', 20)->nullable();                         // owner_link | owner_instruction
                $table->unsignedBigInteger('resumed_by_contact_id')->nullable();
                $table->unsignedBigInteger('resumed_by_user_id')->nullable();
                $table->unsignedBigInteger('resume_authorisation_id')->nullable();
                $table->timestamps();
                $table->index(['rental_fault_report_id', 'resumed_at'], 'roh_fault_active_idx');
                $table->index(['rental_work_order_id', 'resumed_at'], 'roh_wo_active_idx');
            });
        }

        if (! Schema::hasTable('rental_owner_queries')) {
            Schema::create('rental_owner_queries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('rental_fault_report_id')->nullable()->index();
                $table->unsignedBigInteger('rental_work_order_id')->nullable()->index();
                $table->unsignedBigInteger('asked_by_contact_id')->nullable();
                $table->string('asked_via', 12)->default('portal');                     // link | portal | earlier (carried in from the fault history)
                $table->text('question');
                $table->timestamp('asked_at');
                $table->unsignedBigInteger('answered_by_user_id')->nullable();
                $table->text('answer')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->timestamp('answer_sent_at')->nullable();                        // the owner was emailed the answer
                $table->unsignedBigInteger('legacy_update_id')->nullable()->unique();   // the fault history line this question was carried in from
                $table->timestamps();
            });
        }

        // carry the questions owners asked before this build in, once, as unanswered questions
        if (Schema::hasTable('rental_fault_report_updates')) {
            $rows = DB::table('rental_fault_report_updates')->where('update_type', 'owner_query')->orderBy('id')->get();
            foreach ($rows as $u) {
                if (DB::table('rental_owner_queries')->where('legacy_update_id', $u->id)->exists()) {
                    continue;
                }
                $note = (string) $u->note;
                $pos = strpos($note, ' (owner) asked: ');
                $question = $pos !== false ? substr($note, $pos + strlen(' (owner) asked: ')) : $note;
                DB::table('rental_owner_queries')->insert([
                    'agency_id' => $u->agency_id, 'rental_fault_report_id' => $u->rental_fault_report_id, 'asked_via' => 'earlier',
                    'question' => $question, 'asked_at' => $u->created_at ?? now(), 'legacy_update_id' => $u->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('notification_event_types') && ! DB::table('notification_event_types')->where('key', 'rental_fault_report.owner_hold')->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => 'rental_fault_report.owner_hold', 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Owner stopped or resumed a repair',
                'description' => 'The owner of a property pressed "Stop this" on a repair their agent arranged (the job is paused), or said "Go ahead after all".',
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_owner_queries');
        Schema::dropIfExists('rental_owner_holds');
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', 'rental_fault_report.owner_hold')->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }
};
