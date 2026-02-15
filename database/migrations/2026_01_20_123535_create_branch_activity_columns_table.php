<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_activity_columns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('key'); // references activity_columns.key
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->nullable(); // optional override
            $table->timestamps();

            $table->unique(['branch_id', 'key']);
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_activity_columns');
    }
};
