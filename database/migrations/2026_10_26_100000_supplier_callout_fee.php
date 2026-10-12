<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §20 - a supplier's call-out fee. Additive only: three columns on the supplier (every existing
 * supplier becomes "unknown" through the column default - nothing is guessed or back-filled) and an append-only history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agency_service_providers') && ! Schema::hasColumn('agency_service_providers', 'callout_fee_mode')) {
            Schema::table('agency_service_providers', function (Blueprint $table) {
                $table->string('callout_fee_mode', 7)->default('unknown');   // yes | no | unknown
                $table->decimal('callout_fee_amount', 10, 2)->nullable();    // R, incl. VAT - only when mode = yes
                $table->string('callout_fee_note', 255)->nullable();
            });
        }

        if (! Schema::hasTable('agency_service_provider_callout_history')) {
            Schema::create('agency_service_provider_callout_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('service_provider_id')->index('aspch_provider_idx');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source', 12);                                // directory | popup | create
                $table->string('from_mode', 7);
                $table->decimal('from_amount', 10, 2)->nullable();
                $table->string('from_note', 255)->nullable();
                $table->string('to_mode', 7);
                $table->decimal('to_amount', 10, 2)->nullable();
                $table->string('to_note', 255)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_service_provider_callout_history');
        if (Schema::hasTable('agency_service_providers') && Schema::hasColumn('agency_service_providers', 'callout_fee_mode')) {
            Schema::table('agency_service_providers', fn (Blueprint $t) => $t->dropColumn(['callout_fee_mode', 'callout_fee_amount', 'callout_fee_note']));
        }
    }
};
