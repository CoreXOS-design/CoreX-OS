<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 family (.ai/specs/rentals-faults-work-orders.md section 21.2) - "Who looks at faults first": up to three ordered suppliers per rental property
 * or, as a default, per owner (contact as landlord), and the append-only change log of both. Soft deletes only. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_first_responders')) {
            Schema::create('rental_first_responders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('property_id')->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('agency_service_provider_id');
                $table->unsignedTinyInteger('position')->default(1);
                $table->string('fault_scope', 10)->default('all');
                $table->json('fault_type_ids')->nullable();
                $table->string('tell_mode', 10)->default('inspect');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['agency_id', 'property_id'], 'rfr_property_idx');
                $table->index(['agency_id', 'contact_id'], 'rfr_contact_idx');
                $table->index('agency_service_provider_id', 'rfr_supplier_idx');
            });
        }

        if (! Schema::hasTable('rental_first_responder_changes')) {
            Schema::create('rental_first_responder_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->index();
                $table->unsignedBigInteger('property_id')->nullable();
                $table->unsignedBigInteger('contact_id')->nullable();
                $table->unsignedBigInteger('changed_by_user_id')->nullable();
                $table->timestamp('changed_at')->nullable();
                $table->text('old_summary')->nullable();
                $table->text('new_summary')->nullable();
                $table->string('agreed_with', 255)->nullable();
                $table->timestamps();
                $table->index(['agency_id', 'property_id'], 'rfc_property_idx');
                $table->index(['agency_id', 'contact_id'], 'rfc_contact_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_first_responder_changes');
        Schema::dropIfExists('rental_first_responders');
    }
};
