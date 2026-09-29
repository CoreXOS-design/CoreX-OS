<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-23, approved — the PDF carries no photos, only a QR code
 * and a clickable link that opens the inspection online where the photos
 * live. The tenant/landlord receiving it has no CoreX login, so the link
 * must work unauthenticated: signed (a stored, regenerable token — not
 * Laravel's temporarySignedRoute(), which can only be invalidated by a
 * global APP_KEY rotation, not per-record), expiring, revocable.
 *
 * Mirrors rental_applications.token/token_expires_at exactly (same
 * problem: an external party with no account, given a link). One active
 * link per inspection — regenerating overwrites the old token, which
 * immediately invalidates any copy of the old link; revoking clears both
 * columns the same way. No separate "revoked_at" column: null IS
 * revoked/never-issued, there is nothing a third state would answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('cancel_reason');
            $table->timestamp('public_token_expires_at')->nullable()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'public_token_expires_at']);
        });
    }
};
