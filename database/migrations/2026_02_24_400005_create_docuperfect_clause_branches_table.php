<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_clause_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('clause_id');
            $table->unsignedBigInteger('branch_id');

            $table->foreign('clause_id')->references('id')->on('docuperfect_clauses')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
            $table->unique(['clause_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_clause_branches');
    }
};
