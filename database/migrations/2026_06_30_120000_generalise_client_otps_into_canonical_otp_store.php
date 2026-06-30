<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-130 — Generalise client_otps into the canonical, destination-agnostic OTP
 * store backing App\Services\Otp\OtpService.
 *
 * Additive only (NO live-table rename, NO data move): adds a generic
 * polymorphic subject, a generic `destination`, and `channel`, then backfills
 * `destination` from the legacy `email` so every existing client-login row
 * stays verifiable on the new engine. Legacy `client_user_id` / `email`
 * columns are retained untouched.
 *
 * Audit: .ai/audits/2026-06-30-at130-otp-engine-sweep.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_otps', function (Blueprint $table) {
            $table->string('subject_type')->nullable()->after('client_user_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->string('destination')->nullable()->after('email');
            $table->string('channel')->default('email')->after('purpose');

            $table->index(['subject_type', 'subject_id']);
            $table->index(['destination', 'purpose', 'used_at']);
        });

        // Backfill the generic destination from the legacy email for every
        // existing row so in-flight client-login codes resolve on the new
        // verify-by-destination path with zero gap.
        DB::statement('UPDATE client_otps SET destination = email WHERE destination IS NULL');

        // The legacy `email` column is now optional: `destination` is the
        // canonical delivery target, and generic consumers (comms-gate
        // break-glass, future SMS) never set `email`. Relax NOT NULL.
        DB::statement('ALTER TABLE client_otps MODIFY email VARCHAR(255) NULL');
    }

    public function down(): void
    {
        // Restore the legacy NOT NULL on email (no null-email rows exist at the
        // point a rollback is sane: only generic-consumer rows leave it null).
        DB::statement('ALTER TABLE client_otps MODIFY email VARCHAR(255) NOT NULL');

        Schema::table('client_otps', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropIndex(['destination', 'purpose', 'used_at']);
            $table->dropColumn(['subject_type', 'subject_id', 'destination', 'channel']);
        });
    }
};
