<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-portal-access.md §22 / leases.md §18.4 — the four sentences the portal FAQ uses for "early cancellation is allowed /
 * is not allowed" (tenant and owner wording). Agency settings; null reads as the model's default wording.
 */
return new class extends Migration
{
    private const COLUMNS = ['faq_tenant_cancel_yes', 'faq_tenant_cancel_no', 'faq_landlord_cancel_yes', 'faq_landlord_cancel_no'];

    public function up(): void
    {
        if (! Schema::hasTable('rental_portal_settings')) {
            return;
        }

        Schema::table('rental_portal_settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $col) {
                if (! Schema::hasColumn('rental_portal_settings', $col)) {
                    $table->text($col)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rental_portal_settings')) {
            return;
        }

        Schema::table('rental_portal_settings', function (Blueprint $table) {
            $table->dropColumn(array_values(array_filter(self::COLUMNS, fn ($c) => Schema::hasColumn('rental_portal_settings', $c))));
        });
    }
};
