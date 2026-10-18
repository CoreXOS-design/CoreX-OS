<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.6.1 / §17.6.2 (foundation F4) — the owner's
 * work terms per rental property. The no-approval limit already lives on
 * `properties.rental_no_approval_spend_threshold` (reused, not duplicated);
 * this adds the variation tolerance %, who/when stamps, and an append-only
 * change history. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // null = inherit the agency default (rental_work_order_settings.variation_tolerance_percent)
            if (! Schema::hasColumn('properties', 'rental_variation_tolerance_percent')) {
                $table->decimal('rental_variation_tolerance_percent', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('properties', 'rental_work_terms_updated_at')) {
                $table->timestamp('rental_work_terms_updated_at')->nullable();
            }
            if (! Schema::hasColumn('properties', 'rental_work_terms_updated_by_user_id')) {
                $table->foreignId('rental_work_terms_updated_by_user_id')->nullable()
                    ->constrained('users', indexName: 'properties_work_terms_user_fk')->nullOnDelete();
            }
        });

        if (! Schema::hasTable('rental_property_work_term_changes')) {
            Schema::create('rental_property_work_term_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->foreignId('property_id')->constrained()->cascadeOnDelete();
                // no_approval_limit | variation_tolerance
                $table->string('field', 30);
                $table->decimal('old_value', 10, 2)->nullable();
                // null = "inherit the agency default"
                $table->decimal('new_value', 10, 2)->nullable();
                $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('changed_at');
                // e.g. "owner, by email, 3 Oct"
                $table->string('agreed_with', 255)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['property_id', 'changed_at'], 'rpwtc_property_changed_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_property_work_term_changes');

        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'rental_work_terms_updated_by_user_id')) {
                $table->dropForeign('properties_work_terms_user_fk');
                $table->dropColumn('rental_work_terms_updated_by_user_id');
            }
            foreach (['rental_work_terms_updated_at', 'rental_variation_tolerance_percent'] as $col) {
                if (Schema::hasColumn('properties', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
