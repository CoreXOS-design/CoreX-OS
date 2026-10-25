<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-508 P5 — an inspection started on a phone with no signal is queued by the app and sent later, possibly twice (a timeout where the first
 * attempt actually landed). The app mints a uuid when the agent taps Start and resends the SAME one on every retry; a repeat returns the
 * inspection already created instead of starting a second. Unique per agency (not globally), so a key can never collide across agencies.
 * Nullable: every inspection started on the web, and every one that exists today, has none. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->char('client_idempotency_key', 36)->nullable()->after('deductions_reviewed_by_user_id');
            $table->unique(['agency_id', 'client_idempotency_key'], 'rental_insp_agency_idem_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropUnique('rental_insp_agency_idem_unique');
            $table->dropColumn('client_idempotency_key');
        });
    }
};
