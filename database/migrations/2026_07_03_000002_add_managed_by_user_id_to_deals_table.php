<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Multi-Branch Manager — spec .ai/specs/admin-multi-branch-manager.md §4.2
 *
 * Captures WHO the acting branch manager was at deal registration. A branch
 * can have several managers, so "the manager for THIS deal" must be stored,
 * not re-derived. Set only when the registrant is explicitly acting as the
 * deal's branch (see DealController). NULL for every existing deal — those
 * keep resolving the manager the role-based way (Deal::branchManager()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('managed_by_user_id')
                  ->nullable()
                  ->after('branch_id')
                  ->constrained('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('managed_by_user_id');
        });
    }
};
