<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-236 — Refer-to-CO state.
 *
 * Adds the `referred_to_co` status (state-driven, no side flags) plus the
 * referral provenance columns (who referred, when, and the MANDATORY reason).
 * Follows the enum-ALTER pattern of 2026_04_22_110002 (which added `cancelled`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE fica_submissions MODIFY status ENUM(
            'draft','submitted','under_review','agent_approved','corrections_requested',
            'approved','rejected','cancelled','referred_to_co'
        ) NOT NULL DEFAULT 'draft'");

        Schema::table('fica_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('fica_submissions', 'referred_by')) {
                $table->unsignedBigInteger('referred_by')->nullable()->after('co_notes');
                $table->timestamp('referred_at')->nullable()->after('referred_by');
                $table->text('referral_note')->nullable()->after('referred_at');
                $table->foreign('referred_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Move any referred rows back to a legal prior state before shrinking the
        // enum, so the MODIFY never truncates data.
        DB::table('fica_submissions')->where('status', 'referred_to_co')->update(['status' => 'agent_approved']);

        Schema::table('fica_submissions', function (Blueprint $table) {
            if (Schema::hasColumn('fica_submissions', 'referred_by')) {
                $table->dropForeign(['referred_by']);
                $table->dropColumn(['referred_by', 'referred_at', 'referral_note']);
            }
        });

        DB::statement("ALTER TABLE fica_submissions MODIFY status ENUM(
            'draft','submitted','under_review','agent_approved','corrections_requested',
            'approved','rejected','cancelled'
        ) NOT NULL DEFAULT 'draft'");
    }
};
