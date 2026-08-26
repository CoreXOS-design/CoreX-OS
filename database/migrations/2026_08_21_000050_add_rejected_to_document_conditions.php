<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-373 reject flow (Johan 2026-08-12) — the agent may REJECT a recipient-added Other Condition at
 * amendment review (distinct from accept-and-initial). A rejected condition is flagged here so the
 * recipient sees which specific ones were rejected and can Remove (soft-delete) them before re-signing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_conditions', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('superseded_by_condition_id');
            $table->unsignedBigInteger('rejected_by_user_id')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_conditions', function (Blueprint $table) {
            $table->dropColumn(['rejected_at', 'rejected_by_user_id']);
        });
    }
};
