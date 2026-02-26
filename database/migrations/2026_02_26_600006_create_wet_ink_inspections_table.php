<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wet_ink_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('inspector_user_id');
            $table->json('checklist_json');
            $table->enum('result', ['approved', 'rejected']);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('inspector_user_id')->references('id')->on('users');

            $table->index(['signature_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wet_ink_inspections');
    }
};
