<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.27.3 / §14.28 — Build 1's crew-link
 * agency settings. Read-time default pattern (RentalPortalSetting): every
 * column is nullable and a null resolves to the model's DEFAULT_* constant,
 * so nothing is written until an agency changes a value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_portal_settings', 'crew_links_enabled')) {
                $table->boolean('crew_links_enabled')->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'crew_job_link_expiry_days')) {
                $table->unsignedSmallInteger('crew_job_link_expiry_days')->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'crew_link_show_prices')) {
                $table->boolean('crew_link_show_prices')->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'crew_link_show_tenant_contact')) {
                $table->boolean('crew_link_show_tenant_contact')->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'notify_landlord_on_crew_completion')) {
                $table->boolean('notify_landlord_on_crew_completion')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            $table->dropColumn([
                'crew_links_enabled', 'crew_job_link_expiry_days', 'crew_link_show_prices',
                'crew_link_show_tenant_contact', 'notify_landlord_on_crew_completion',
            ]);
        });
    }
};
