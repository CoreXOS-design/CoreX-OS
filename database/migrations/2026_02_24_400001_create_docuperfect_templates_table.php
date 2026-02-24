<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('template_type')->default('sales');
            $table->integer('page_count')->default(0);
            $table->json('fields_json')->nullable();
            $table->boolean('is_global')->default(false);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_templates');
    }
};
