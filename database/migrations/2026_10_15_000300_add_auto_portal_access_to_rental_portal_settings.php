<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §16 — "give the tenant and landlord portal access automatically when
 * the lease is signed". Read-time default pattern (RentalPortalSetting): null resolves to the model's
 * DEFAULT constant (ON), so nothing is written until an agency changes the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_portal_settings', 'auto_portal_access_on_signing')) {
                $table->boolean('auto_portal_access_on_signing')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            if (Schema::hasColumn('rental_portal_settings', 'auto_portal_access_on_signing')) {
                $table->dropColumn('auto_portal_access_on_signing');
            }
        });
    }
};
