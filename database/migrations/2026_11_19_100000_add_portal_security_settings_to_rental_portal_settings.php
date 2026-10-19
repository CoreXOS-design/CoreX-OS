<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental portal security fixes (audit 2026-10-19, items D4 / D8 / D10 / D12 / D14) - the five timings and limits those fixes introduce,
 * as agency settings on the existing per-agency `rental_portal_settings` row (read-time default pattern: a null column resolves to the
 * model's DEFAULT_* constant, nothing is written until the agency changes a value).
 *
 *   portal_remember_days        how long a browser remembers a portal sign-in (days)                    default 7
 *   portal_app_token_days       how long the phone app stays signed in, renewed on use (days)           default 30
 *   portal_api_per_minute       requests per minute one signed-in portal person can make                default 120
 *   portal_photo_link_minutes   how long a picture link shown in the portal keeps working (minutes)     default 120
 *   portal_link_days            how long a personal link in a portal email stays recognised (days)      default 60
 *
 * Idempotent (hasColumn guards).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'portal_remember_days' => 'unsignedSmallInteger',
        'portal_app_token_days' => 'unsignedSmallInteger',
        'portal_api_per_minute' => 'unsignedSmallInteger',
        'portal_photo_link_minutes' => 'unsignedSmallInteger',
        'portal_link_days' => 'unsignedSmallInteger',
    ];

    public function up(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            foreach (self::COLUMNS as $name => $type) {
                if (! Schema::hasColumn('rental_portal_settings', $name)) {
                    $table->{$type}($name)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_portal_settings', function (Blueprint $table) {
            foreach (array_keys(self::COLUMNS) as $name) {
                if (Schema::hasColumn('rental_portal_settings', $name)) {
                    $table->dropColumn($name);
                }
            }
        });
    }
};
