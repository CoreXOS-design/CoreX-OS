<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fault / work order audit fixes (19 Oct 2026, spec rentals-faults-work-orders.md section 29): the agency settings behind the new chasing and link rules.
 *  - owner_reminder_hours / owner_second_reminder_hours / owner_queue_after_hours: when the owner has not answered a fault (C-A1).
 *  - link_renew_on_use: a contractor / crew link's expiry slides forward each time it is used (C-D3).
 *  - quote_max_amount: the largest quote a contractor may enter on their link (C-C6).
 * All nullable (null = the default in RentalPortalSetting). Additive, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_portal_settings')) {
            return;
        }
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            foreach (['owner_reminder_hours', 'owner_second_reminder_hours', 'owner_queue_after_hours'] as $col) {
                if (! Schema::hasColumn('rental_portal_settings', $col)) {
                    $table->unsignedSmallInteger($col)->nullable();
                }
            }
            if (! Schema::hasColumn('rental_portal_settings', 'link_renew_on_use')) {
                $table->boolean('link_renew_on_use')->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'quote_max_amount')) {
                $table->unsignedInteger('quote_max_amount')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['owner_reminder_hours', 'owner_second_reminder_hours', 'owner_queue_after_hours', 'link_renew_on_use', 'quote_max_amount'] as $col) {
            if (Schema::hasColumn('rental_portal_settings', $col)) {
                Schema::table('rental_portal_settings', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};
