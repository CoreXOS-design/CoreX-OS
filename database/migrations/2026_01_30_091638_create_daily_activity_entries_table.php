<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_activity_entries', function (Blueprint $table) {
            $table->id();

            $table->date('activity_date');
            $table->string('period', 7); // YYYY-MM

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->foreignId('activity_definition_id')
                ->constrained('activity_definitions')
                ->cascadeOnDelete();

            // captured value
            $table->integer('value')->default(0);

            // audit-ish
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // one row per (definition, user, date)
            $table->unique(['activity_definition_id', 'user_id', 'activity_date'], 'dae_def_user_date_unique');
            $table->index(['period', 'user_id']);
            $table->index(['activity_date', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_activity_entries');
    }
};
