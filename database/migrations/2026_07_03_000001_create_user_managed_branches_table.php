<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Multi-Branch Manager — spec .ai/specs/admin-multi-branch-manager.md §4.1
 *
 * An admin can manage (act as branch manager of) several branches. This pivot
 * records which branches a user manages and which one is their login default.
 * It is ADDITIVE to users.branch_id (the home branch) and distinct from the
 * 1:1 `branch_assignments` table. It changes identity/representation only —
 * never data scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_managed_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            // Denormalised for scoping/validation (a user may only manage
            // branches in their own effective agency). Nullable so legacy /
            // agency-less branches don't block insertion.
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            // Exactly one row per user is the login default — enforced in app logic.
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_managed_branches');
    }
};
