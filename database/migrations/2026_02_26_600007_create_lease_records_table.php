<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('signature_template_id');
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('property_address');
            $table->string('tenant_name');
            $table->string('tenant_email');
            $table->string('landlord_name');
            $table->string('landlord_email');
            $table->decimal('rental_amount', 12, 2);
            $table->date('lease_start_date');
            $table->date('lease_end_date');
            $table->enum('status', [
                'active', 'expiring_soon', 'expired', 'renewed', 'terminated',
            ])->default('active');
            $table->unsignedBigInteger('previous_lease_id')->nullable();
            $table->unsignedBigInteger('renewed_lease_id')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('docuperfect_documents')->cascadeOnDelete();
            $table->foreign('signature_template_id')->references('id')->on('signature_templates')->cascadeOnDelete();
            $table->foreign('previous_lease_id')->references('id')->on('lease_records')->nullOnDelete();
            $table->foreign('renewed_lease_id')->references('id')->on('lease_records')->nullOnDelete();

            $table->index(['status', 'lease_end_date']);
            $table->index(['document_id']);
            $table->index(['signature_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_records');
    }
};
