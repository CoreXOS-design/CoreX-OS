<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->string('document_hash', 64)->nullable();
            $table->enum('status', [
                'draft', 'ready', 'signing', 'awaiting_tenant',
                'awaiting_landlord', 'completed', 'expired', 'declined',
            ])->default('draft');
            $table->json('parties_json')->nullable();
            $table->json('signing_order_json')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('docuperfect_documents')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['document_id', 'status']);
            $table->index(['created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_templates');
    }
};
