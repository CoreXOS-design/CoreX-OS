<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the Admin → Soft Deletes Register.
 *
 * Every restore performed from the register writes one row here so there is a
 * permanent record of who brought an archived record back, when, and which
 * agency it belonged to. Spec: .ai/specs/soft-deletes-admin.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soft_delete_restorations', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('model_label')->nullable();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('restored_by_user_id');
            $table->timestamp('restored_at');
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index('agency_id');
            $table->index('restored_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soft_delete_restorations');
    }
};
