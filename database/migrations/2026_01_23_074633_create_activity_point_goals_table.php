<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_point_goals', function (Blueprint $table) {
            $table->id();

            // Scope of the goal
            $table->unsignedBigInteger('user_id')->nullable();    // if set = individual agent
            $table->unsignedBigInteger('branch_id')->nullable();  // if set = branch target
            $table->string('period', 7); // YYYY-MM

            // The goal
            $table->decimal('points_target', 12, 2)->default(0);

            $table->timestamps();

            // Helpful indexes
            $table->index(['period']);
            $table->index(['user_id']);
            $table->index(['branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_point_goals');
    }
};
