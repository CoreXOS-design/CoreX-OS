<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.27.3 / §14.29 — BUILD 2 (crew page +
 * client visibility). The four agency settings Build 2 owns, on the existing
 * per-agency `rental_portal_settings` row (read-time default pattern: a null
 * column resolves to the model's DEFAULT_* constant, nothing is written
 * until the agency changes a value).
 *
 *   crew_photos_visible_to_clients   which crew photos a tenant / landlord sees
 *                                    ('in_progress_and_completed' default | 'completed_only')
 *   crew_standing_link_expiry_days   crew page link validity; NULL = stands until revoked
 *   crew_page_recent_completed_days  "recently completed" window on the crew page (0 hides it)
 *   crew_page_upcoming_days          "upcoming" window on the crew page
 *
 * Idempotent (hasColumn guards) so it is safe on a database that already has
 * any of the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_portal_settings', 'crew_photos_visible_to_clients')) {
                $table->string('crew_photos_visible_to_clients', 40)->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'crew_standing_link_expiry_days')) {
                $table->unsignedSmallInteger('crew_standing_link_expiry_days')->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'crew_page_recent_completed_days')) {
                $table->unsignedTinyInteger('crew_page_recent_completed_days')->nullable();
            }
            if (! Schema::hasColumn('rental_portal_settings', 'crew_page_upcoming_days')) {
                $table->unsignedTinyInteger('crew_page_upcoming_days')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            foreach (['crew_photos_visible_to_clients', 'crew_standing_link_expiry_days', 'crew_page_recent_completed_days', 'crew_page_upcoming_days'] as $col) {
                if (Schema::hasColumn('rental_portal_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
