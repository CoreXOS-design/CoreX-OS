<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-120 — branch tier for Outreach Queue role-visibility. Adds branch_id so the
 * queue reuses the canonical BranchScope/BelongsToBranch layer (auto-stamped from
 * the preparing agent's effective branch at enqueue) and `scopeVisibleTo` can do
 * the branch tier exactly like CalendarEvent. Nullable + nullOnDelete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outreach_queue', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('agency_id')->constrained('branches')->nullOnDelete();
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('outreach_queue', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
