<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-19 B1 / B3 / B5 - the outgoing side of a tenancy, per Johan's ruling: the money stays in the agency's accounting system;
 * CoreX only RECORDS the deductions, with their amounts.
 *
 *  - rental_inspection_item_findings.amount        - what a `charge_tenant` finding charges (nullable: only that disposition carries one; a row from
 *                                                    before this migration simply has none and is shown as "no amount recorded").
 *  - rental_inspections.deductions_reviewed_*      - the agent has reviewed the deposit-deductions summary of this Out (clears the lease hub's next step).
 *  - rental_inventories.move_out_completed_*       - the move-out inventory check is complete (every line has a finding); locks further findings.
 *  - rental_inventory_move_out_sign_offs           - who signed off the move-out inventory check: one live row per party, append-only (a correction
 *                                                    archives the old row and adds a new one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_item_findings', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('note');
        });

        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->timestamp('deductions_reviewed_at')->nullable();
            $table->unsignedBigInteger('deductions_reviewed_by_user_id')->nullable();
        });

        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->timestamp('move_out_completed_at')->nullable();
            $table->unsignedBigInteger('move_out_completed_by_user_id')->nullable();
        });

        Schema::create('rental_inventory_move_out_sign_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inventory_id')->constrained('rental_inventories')->cascadeOnDelete();
            $table->string('party_role', 20);              // tenant | landlord | agent
            $table->unsignedBigInteger('party_contact_id')->nullable();
            $table->string('disposition', 12);             // signed | refused
            $table->string('signed_name', 191);
            $table->string('refusal_reason', 500)->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 500)->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rental_inventory_id', 'party_role'], 'ri_moveout_signoffs_inv_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_move_out_sign_offs');
        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->dropColumn(['move_out_completed_at', 'move_out_completed_by_user_id']);
        });
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropColumn(['deductions_reviewed_at', 'deductions_reviewed_by_user_id']);
        });
        Schema::table('rental_inspection_item_findings', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
