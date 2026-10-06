<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.4.2 / §17.5.2 (foundation F1) — cost vs
 * selling on job card lines, the card-level markups, and the catalogue's
 * default cost.
 *
 * `unit_price` / `line_total` keep their meaning and are now explicitly
 * SELLING; cost is new. EXISTING rows: selling_basis='manual', office_status=
 * 'accepted', origin='office', cost null — no cost is ever back-filled or
 * invented. Idempotent (each column guarded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_job_card_lines', 'unit_cost')) {
                $table->decimal('unit_cost', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'cost_total')) {
                $table->decimal('cost_total', 10, 2)->nullable();
            }
            // percent | amount
            if (! Schema::hasColumn('rental_job_card_lines', 'markup_type')) {
                $table->string('markup_type', 10)->nullable();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'markup_value')) {
                $table->decimal('markup_value', 10, 2)->nullable();
            }
            // manual | line_markup | job_markup | catalogue_price | agency_default
            if (! Schema::hasColumn('rental_job_card_lines', 'selling_basis')) {
                $table->string('selling_basis', 20)->default('manual');
            }
            // office | crew_pricing | crew_extra
            if (! Schema::hasColumn('rental_job_card_lines', 'origin')) {
                $table->string('origin', 20)->default('office');
            }
            // crew_draft | awaiting_office | accepted | rejected | declined_by_owner
            if (! Schema::hasColumn('rental_job_card_lines', 'office_status')) {
                $table->string('office_status', 20)->default('accepted');
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'crew_note')) {
                $table->text('crew_note')->nullable();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'crew_added_by_label')) {
                $table->string('crew_added_by_label', 191)->nullable();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'crew_added_at')) {
                $table->timestamp('crew_added_at')->nullable();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'office_decided_by_user_id')) {
                $table->foreignId('office_decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'office_decided_at')) {
                $table->timestamp('office_decided_at')->nullable();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'reject_reason')) {
                $table->text('reject_reason')->nullable();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'rental_job_card_price_request_id')) {
                $table->foreignId('rental_job_card_price_request_id')->nullable()
                    ->constrained('rental_job_card_price_requests', indexName: 'rjcl_price_request_fk')->nullOnDelete();
            }
            if (! Schema::hasColumn('rental_job_card_lines', 'rental_work_order_variation_id')) {
                $table->foreignId('rental_work_order_variation_id')->nullable()
                    ->constrained('rental_work_order_variations', indexName: 'rjcl_variation_fk')->nullOnDelete();
            }
        });

        // A reader that filters on office_status (every reader, §17.4.6) wants this.
        if (! $this->hasIndex('rental_job_card_lines', 'rjcl_card_office_status_idx')) {
            Schema::table('rental_job_card_lines', function (Blueprint $table) {
                $table->index(['rental_job_card_id', 'office_status'], 'rjcl_card_office_status_idx');
            });
        }

        Schema::table('rental_job_cards', function (Blueprint $table) {
            foreach (['markup_all_percent', 'markup_parts_percent', 'markup_labour_percent'] as $col) {
                if (! Schema::hasColumn('rental_job_cards', $col)) {
                    $table->decimal($col, 6, 2)->nullable();
                }
            }
            if (! Schema::hasColumn('rental_job_cards', 'total_cost')) {
                $table->decimal('total_cost', 10, 2)->nullable();
            }
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_catalogue_items', 'default_cost')) {
                $table->decimal('default_cost', 10, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            if (Schema::hasColumn('rental_catalogue_items', 'default_cost')) {
                $table->dropColumn('default_cost');
            }
        });

        Schema::table('rental_job_cards', function (Blueprint $table) {
            foreach (['markup_all_percent', 'markup_parts_percent', 'markup_labour_percent', 'total_cost'] as $col) {
                if (Schema::hasColumn('rental_job_cards', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if ($this->hasIndex('rental_job_card_lines', 'rjcl_card_office_status_idx')) {
            Schema::table('rental_job_card_lines', function (Blueprint $table) {
                $table->dropIndex('rjcl_card_office_status_idx');
            });
        }

        Schema::table('rental_job_card_lines', function (Blueprint $table) {
            if (Schema::hasColumn('rental_job_card_lines', 'rental_job_card_price_request_id')) {
                $table->dropForeign('rjcl_price_request_fk');
                $table->dropColumn('rental_job_card_price_request_id');
            }
            if (Schema::hasColumn('rental_job_card_lines', 'rental_work_order_variation_id')) {
                $table->dropForeign('rjcl_variation_fk');
                $table->dropColumn('rental_work_order_variation_id');
            }
            if (Schema::hasColumn('rental_job_card_lines', 'office_decided_by_user_id')) {
                $table->dropConstrainedForeignId('office_decided_by_user_id');
            }
            foreach ([
                'unit_cost', 'cost_total', 'markup_type', 'markup_value', 'selling_basis', 'origin', 'office_status',
                'crew_note', 'crew_added_by_label', 'crew_added_at', 'office_decided_at', 'reject_reason',
            ] as $col) {
                if (Schema::hasColumn('rental_job_card_lines', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = \Illuminate\Support\Facades\DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($rows) > 0;
    }
};
