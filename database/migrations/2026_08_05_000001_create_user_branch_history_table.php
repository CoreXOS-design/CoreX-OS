<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-366 (Q4 enabler) — dated history of agent branch moves.
 *
 * point-in-time branch attribution in the Performance & ROI report needs to know
 * which branch an agent was in AT THE TIME of each activity. `users.branch_id`
 * holds only the CURRENT branch and a move overwrites it with no record — so this
 * log must start accruing NOW (Johan confirmed capturing moves, Q4). A row is
 * written by UserObserver whenever users.branch_id changes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_branch_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('from_branch_id')->nullable();
            $table->unsignedBigInteger('to_branch_id')->nullable();
            $table->timestamp('moved_at');
            $table->unsignedBigInteger('moved_by_user_id')->nullable();

            $table->index(['user_id', 'moved_at']);
            $table->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_history');
    }
};
