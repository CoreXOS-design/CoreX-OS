<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('template_id');
            $table->json('fields_json')->nullable();
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('template_id')->references('id')->on('docuperfect_templates')->onDelete('cascade');
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_documents');
    }
};
