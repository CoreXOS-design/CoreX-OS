<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 round 3 (.ai/specs/rentals-faults-work-orders.md §20) - "no work without authority".
 *  - the emergency limit (agency default + per-property override) and the agency switch for "agents may record an owner's decision";
 *  - an emergency declared on a work order (with its reason), the "quote first" marks, and the ceiling an owner's recorded approval sets;
 *  - the ledger of decisions an agent records on an owner's instruction (never edited; only superseded).
 * Idempotent: every step checks before it writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_work_order_settings', 'emergency_spend_threshold')) {
            Schema::table('rental_work_order_settings', function (Blueprint $table) {
                $table->decimal('emergency_spend_threshold', 12, 2)->nullable()->after('no_approval_spend_threshold');
                $table->boolean('agents_may_record_owner_decisions')->nullable()->after('emergency_spend_threshold');
            });
        }
        if (! Schema::hasColumn('properties', 'rental_emergency_spend_threshold')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->decimal('rental_emergency_spend_threshold', 10, 2)->nullable()->after('rental_no_approval_spend_threshold');
            });
        }
        if (! Schema::hasColumn('rental_work_orders', 'emergency_declared_at')) {
            Schema::table('rental_work_orders', function (Blueprint $table) {
                $table->timestamp('emergency_declared_at')->nullable();
                $table->unsignedBigInteger('emergency_declared_by_user_id')->nullable();
                $table->text('emergency_declared_reason')->nullable();
                $table->boolean('quote_first')->default(false);
                $table->decimal('owner_approved_up_to', 12, 2)->nullable();
            });
        }
        if (! Schema::hasColumn('rental_fault_reports', 'quote_first_at')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->timestamp('quote_first_at')->nullable();
                $table->decimal('owner_approved_up_to', 12, 2)->nullable();
            });
        }

        if (! Schema::hasTable('rental_recorded_owner_decisions')) {
            Schema::create('rental_recorded_owner_decisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('rental_fault_report_id')->nullable()->index();
                $table->unsignedBigInteger('rental_work_order_id')->nullable()->index();
                $table->string('step', 30);                       // fault_decision | quote_approval
                $table->unsignedBigInteger('owner_contact_id');
                $table->string('decision', 20);                   // approved | get_quote_first | declined
                $table->decimal('approved_up_to', 12, 2)->nullable();
                $table->string('told_via', 20);                   // email | whatsapp | phone_call
                $table->dateTime('told_at');
                $table->text('note');
                $table->string('proof_path', 500)->nullable();
                $table->unsignedBigInteger('recorded_by_user_id');
                $table->timestamp('superseded_at')->nullable();
                $table->string('superseded_by', 40)->nullable(); // recorded:<id> | owner
                $table->timestamp('created_at')->nullable();
            });
        }

        // the agents are told when the owner queries a recorded work-order decision (the fault one is registered already)
        if (Schema::hasTable('notification_event_types') && ! DB::table('notification_event_types')->where('key', 'rental_work_order.owner_query')->exists()) {
            DB::table('notification_event_types')->insert([
                'key' => 'rental_work_order.owner_query', 'pillar' => 'property', 'group_label' => 'Rentals', 'label' => 'Owner queried a work order decision',
                'description' => 'The owner of a property sent your agency a question about a decision an agent recorded for them on a work order, from their portal.',
                'default_enabled' => 1, 'threshold_unit' => 'none', 'default_threshold' => null, 'threshold_min' => null, 'threshold_max' => null,
                'supports_in_app' => 1, 'supports_email' => 1, 'supports_push' => 0, 'is_adapter' => 0, 'adapter_column' => null,
                'sort_order' => (int) DB::table('notification_event_types')->max('sort_order') + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_recorded_owner_decisions');
        if (Schema::hasColumn('rental_fault_reports', 'quote_first_at')) {
            Schema::table('rental_fault_reports', fn (Blueprint $t) => $t->dropColumn(['quote_first_at', 'owner_approved_up_to']));
        }
        if (Schema::hasColumn('rental_work_orders', 'emergency_declared_at')) {
            Schema::table('rental_work_orders', fn (Blueprint $t) => $t->dropColumn(['emergency_declared_at', 'emergency_declared_by_user_id', 'emergency_declared_reason', 'quote_first', 'owner_approved_up_to']));
        }
        if (Schema::hasColumn('properties', 'rental_emergency_spend_threshold')) {
            Schema::table('properties', fn (Blueprint $t) => $t->dropColumn('rental_emergency_spend_threshold'));
        }
        if (Schema::hasColumn('rental_work_order_settings', 'emergency_spend_threshold')) {
            Schema::table('rental_work_order_settings', fn (Blueprint $t) => $t->dropColumn(['emergency_spend_threshold', 'agents_may_record_owner_decisions']));
        }
        if (Schema::hasTable('notification_event_types')) {
            DB::table('notification_event_types')->where('key', 'rental_work_order.owner_query')->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }
};
