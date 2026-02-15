<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_history_entries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('type'); // CALC | CMA
            $table->string('ref')->unique();

            $table->timestamp('occurred_at');

            $table->string('property');
            $table->decimal('value', 14, 2);

            $table->string('agent_name');

            $table->text('payload'); // JSON

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('branch_id')->references('id')->on('branches')->onDelete('set null');

            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_history_entries');
    }
};
