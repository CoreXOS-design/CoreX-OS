<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presentations V2 Phase 1 — pillar foreign keys.
 *
 * Adds explicit links to the four CoreX pillars so the one-button auto-
 * presentation flow can carry forward Property, TrackedProperty, Contact
 * (seller), and Deal data without re-typing.
 *
 * The legacy `listing_id` column (1/21 use rate per audit) is left intact
 * for backward compatibility; new code should populate `property_id` instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            if (!Schema::hasColumn('presentations', 'property_id')) {
                $table->unsignedBigInteger('property_id')->nullable()->after('listing_id');
                $table->index('property_id');
            }
            if (!Schema::hasColumn('presentations', 'tracked_property_id')) {
                $table->unsignedBigInteger('tracked_property_id')->nullable()->after('property_id');
                $table->index('tracked_property_id');
            }
            if (!Schema::hasColumn('presentations', 'seller_contact_id')) {
                $table->unsignedBigInteger('seller_contact_id')->nullable()->after('tracked_property_id');
                $table->index('seller_contact_id');
            }
            if (!Schema::hasColumn('presentations', 'deal_id')) {
                $table->unsignedBigInteger('deal_id')->nullable()->after('seller_contact_id');
                $table->index('deal_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            foreach (['property_id', 'tracked_property_id', 'seller_contact_id', 'deal_id'] as $col) {
                if (Schema::hasColumn('presentations', $col)) {
                    $table->dropIndex([$col]);
                    $table->dropColumn($col);
                }
            }
        });
    }
};
