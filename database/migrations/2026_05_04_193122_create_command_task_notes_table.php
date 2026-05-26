<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_task_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('command_task_id')->constrained('command_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->text('body');
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['command_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_task_notes');
    }
};
