<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_template_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('branch_id');

            $table->foreign('template_id')->references('id')->on('docuperfect_templates')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->unique(['template_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_template_branches');
    }
};
