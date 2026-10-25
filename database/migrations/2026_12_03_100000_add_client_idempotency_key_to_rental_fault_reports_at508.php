<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-508 P2 (.ai/specs/at-508-mobile-rentals.md section 5.1) - a fault report the mobile app queued while it had no signal is sent again when the
 * signal returns, and it may be sent more than once. The app's own uuid on the report makes the second send return the report already stored
 * instead of a duplicate. Unique per agency (not globally), so a key can never collide across agencies. Nullable: every existing report, and
 * every report made on the office screen / portal, has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->char('client_idempotency_key', 36)->nullable()->after('created_by_user_id');
            $table->unique(['agency_id', 'client_idempotency_key'], 'rfr_agency_client_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropUnique('rfr_agency_client_key_unique');
            $table->dropColumn('client_idempotency_key');
        });
    }
};
