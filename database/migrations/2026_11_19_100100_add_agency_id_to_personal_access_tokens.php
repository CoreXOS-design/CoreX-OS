<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental portal security fix D5 (audit 2026-10-19) - the agency a portal person chose now belongs to the TOKEN (the phone, the browser tab),
 * not to the login. `client_users.current_agency_id` was one value per person, so a phone and a browser signed in as the same person flipped
 * each other's agency mid-session. NULL = the token never chose: it falls back to the login's own value, exactly as before.
 * Plain nullable column, no foreign key (the token table is the framework's own and must never block an agency delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'agency_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('abilities');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_access_tokens', 'agency_id')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('agency_id');
            });
        }
    }
};
