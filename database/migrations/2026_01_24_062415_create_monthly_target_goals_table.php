<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_target_goals', function (Blueprint $table) {
            $table->id();

            // Scope of the goal (pattern like activity_point_goals)
            // - company/global: user_id NULL, branch_id NULL
            // - branch: user_id NULL, branch_id set
            // - user: user_id set (branch_id optional)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('period', 7); // YYYY-MM

            // The goals
            $table->integer('listings_target')->default(0);
            $table->integer('deals_target')->default(0);
            $table->decimal('value_target', 14, 2)->default(0);

            $table->text('notes')->nullable();

            // Audit (matches style used elsewhere)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Helpful indexes
            $table->index(['period']);
            $table->index(['user_id']);
            $table->index(['branch_id']);
            $table->index(['period', 'branch_id']);
            $table->index(['period', 'user_id']);

            // One record per exact scope combo (note: NULLs mean SQLite may allow multiple "global" rows;
            // we will enforce company/global uniqueness at app level when wiring the UI.)
            $table->unique(['period', 'user_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_target_goals');
    }
};
