<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-19 B21 - inspection photos moved to private storage; the screens now show them through signed, expiring links. How long such
 * a link keeps working is an agency setting (null = the read-time default, 480 minutes: long enough that a recording session left open
 * through a working day keeps its pictures, short enough that a copied picture link is dead by the next morning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('photo_link_minutes')->nullable()->after('public_link_expiry_days');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('photo_link_minutes');
        });
    }
};
