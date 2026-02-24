<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docuperfect_clauses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('text');
            $table->boolean('is_global')->default(false);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();

            $table->foreign('owner_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_clauses');
    }
};
