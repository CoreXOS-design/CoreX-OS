<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.6–§17.9 (foundation F5 + F8) — the approval
 * model:
 *  - rental_work_orders: approved_amount, approval_basis, emergency_approval_id,
 *    the per-work-order external-quote fee override;
 *  - rental_approval_decisions (append-only — every decision the system makes
 *    cites the term it relied on);
 *  - rental_emergency_approvals (the owner's agreement, no cost attached);
 *  - rental_approvals.rental_work_order_variation_id;
 *  - rental_work_order_quotes: the R6 term snapshot and the external-quote fee.
 *
 * IN-FLIGHT ROWS ARE GRANDFATHERED (§17.6.5): every work order that is already
 * ordered / in progress, or whose job card is scheduled / in progress, gets
 * approval_basis='legacy_grandfathered' so the new "work needs an authorisation"
 * guard can never suddenly block a job that is already under way.
 *
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_work_orders', 'approved_amount')) {
                $table->decimal('approved_amount', 10, 2)->nullable();
            }
            // no_approval_limit | owner_decision | variation_tolerance | emergency_owner_agreed | legacy_grandfathered
            if (! Schema::hasColumn('rental_work_orders', 'approval_basis')) {
                $table->string('approval_basis', 30)->nullable();
            }
            // deliberately NO foreign key: rental_emergency_approvals points back at the work order
            if (! Schema::hasColumn('rental_work_orders', 'emergency_approval_id')) {
                $table->unsignedBigInteger('emergency_approval_id')->nullable();
            }
            // percent | amount — per-work-order override of the agency's external-quote fee; null = inherit
            if (! Schema::hasColumn('rental_work_orders', 'external_markup_type')) {
                $table->string('external_markup_type', 10)->nullable();
            }
            if (! Schema::hasColumn('rental_work_orders', 'external_markup_value')) {
                $table->decimal('external_markup_value', 10, 2)->nullable();
            }
        });

        if (! Schema::hasTable('rental_approval_decisions')) {
            Schema::create('rental_approval_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_work_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_work_order_variation_id')->nullable()
                    ->constrained('rental_work_order_variations', indexName: 'rad_variation_fk')->nullOnDelete();
                $table->foreignId('rental_work_order_quote_id')->nullable()
                    ->constrained('rental_work_order_quotes', indexName: 'rad_quote_fk')->nullOnDelete();

                // system | user | owner | emergency
                $table->string('decided_by', 20);
                $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('decided_by_contact_id')->nullable();
                // auto_approved | needs_owner | approved | declined | blocked | emergency_covered
                $table->string('decision', 20);
                // no_approval_limit | variation_tolerance | owner_decision | emergency_owner_agreed | legacy_grandfathered
                $table->string('basis', 30);
                // no_approval_limit | variation_tolerance | owner_decision | emergency
                $table->string('term_key', 30);
                $table->decimal('term_value', 10, 2)->nullable();
                // property | agency_default | constant | owner | emergency
                $table->string('term_source', 20)->nullable();
                $table->decimal('amount_tested', 10, 2)->nullable();
                $table->decimal('baseline_amount', 10, 2)->nullable();
                $table->decimal('limit_amount', 10, 2)->nullable();
                // the decision in words, frozen at the time — a later change of a term never rewrites history
                $table->text('note');

                // append-only: created_at is the only timestamp
                $table->timestamp('created_at')->useCurrent();

                $table->index(['rental_work_order_id', 'created_at'], 'rad_wo_created_idx');
                $table->index(['agency_id', 'decision'], 'rad_agency_decision_idx');
            });
        }

        if (! Schema::hasTable('rental_emergency_approvals')) {
            Schema::create('rental_emergency_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('rental_work_order_id')->constrained()->cascadeOnDelete();

                $table->string('approved_by_name', 191);
                $table->unsignedBigInteger('owner_contact_id')->nullable();
                // phone | whatsapp | email | in_person | other
                $table->string('approved_via', 20);
                $table->timestamp('approved_at');
                $table->text('reason');
                $table->string('reported_by_crew_name', 191)->nullable();
                $table->text('notes')->nullable();
                // private disk; image/pdf
                $table->string('attachment_path', 500)->nullable();
                // NO amount column — by ruling nothing about cost is attached to an emergency approval.

                $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->timestamp('voided_at')->nullable();
                $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('void_reason')->nullable();

                $table->index(['rental_work_order_id', 'voided_at'], 'rea_wo_voided_idx');
            });
        }

        // rental_approvals: "exactly one of fault report / work order" becomes "... / variation".
        if (Schema::hasTable('rental_approvals') && ! Schema::hasColumn('rental_approvals', 'rental_work_order_variation_id')) {
            Schema::table('rental_approvals', function (Blueprint $table) {
                $table->foreignId('rental_work_order_variation_id')->nullable()
                    ->constrained('rental_work_order_variations', indexName: 'rental_approvals_variation_fk')->nullOnDelete();
            });
        }

        Schema::table('rental_work_order_quotes', function (Blueprint $table) {
            // the R6 estimate-term wording in force when the quote was sent (§17.11)
            if (! Schema::hasColumn('rental_work_order_quotes', 'term_text')) {
                $table->text('term_text')->nullable();
            }
            // external-quote fee (§17.9.1a): amount stays the contractor's own quote
            if (! Schema::hasColumn('rental_work_order_quotes', 'fee_type')) {
                $table->string('fee_type', 10)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_quotes', 'fee_value')) {
                $table->decimal('fee_value', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_quotes', 'fee_amount')) {
                $table->decimal('fee_amount', 10, 2)->default(0);
            }
            // null = same as `amount` (no fee)
            if (! Schema::hasColumn('rental_work_order_quotes', 'selling_amount')) {
                $table->decimal('selling_amount', 10, 2)->nullable();
            }
        });

        // Grandfather the jobs already under way (§17.6.5).
        DB::table('rental_work_orders')
            ->whereNull('approval_basis')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereIn('status', ['ordered', 'in_progress'])
                    ->orWhereIn('id', function ($sub) {
                        $sub->select('rental_work_order_id')->from('rental_job_cards')
                            ->whereNotNull('rental_work_order_id')
                            ->whereIn('status', ['scheduled', 'in_progress']);
                    });
            })
            ->update(['approval_basis' => 'legacy_grandfathered']);
    }

    public function down(): void
    {
        Schema::table('rental_work_order_quotes', function (Blueprint $table) {
            foreach (['term_text', 'fee_type', 'fee_value', 'fee_amount', 'selling_amount'] as $col) {
                if (Schema::hasColumn('rental_work_order_quotes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasTable('rental_approvals') && Schema::hasColumn('rental_approvals', 'rental_work_order_variation_id')) {
            Schema::table('rental_approvals', function (Blueprint $table) {
                $table->dropForeign('rental_approvals_variation_fk');
                $table->dropColumn('rental_work_order_variation_id');
            });
        }

        Schema::dropIfExists('rental_emergency_approvals');
        Schema::dropIfExists('rental_approval_decisions');

        Schema::table('rental_work_orders', function (Blueprint $table) {
            foreach (['approved_amount', 'approval_basis', 'emergency_approval_id', 'external_markup_type', 'external_markup_value'] as $col) {
                if (Schema::hasColumn('rental_work_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
