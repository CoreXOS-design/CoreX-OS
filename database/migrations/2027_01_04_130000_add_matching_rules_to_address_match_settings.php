<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-520 step 4.4 (mic-work-tab.md section 4, property-identity.md 18.1/18.1a): the agency settings behind the P24-group, same-agency-twin, complex
 * and map-point rules. Every threshold is a setting with the spec default (Settings page only). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_match_settings', function (Blueprint $t) {
            $add = function (string $col, callable $def) use ($t) {
                if (! Schema::hasColumn('address_match_settings', $col)) {
                    $def($t);
                }
            };
            $add('portal_group_link_enabled', fn ($t) => $t->boolean('portal_group_link_enabled')->default(true));
            $add('portal_group_price_spread', fn ($t) => $t->decimal('portal_group_price_spread', 3, 1)->default(1.5));
            $add('portal_group_auto_link_min_confidence', fn ($t) => $t->string('portal_group_auto_link_min_confidence', 10)->default('high'));
            $add('twin_match_enabled', fn ($t) => $t->boolean('twin_match_enabled')->default(true));
            $add('twin_price_tolerance_pct', fn ($t) => $t->unsignedTinyInteger('twin_price_tolerance_pct')->default(1));
            $add('twin_min_corroboration', fn ($t) => $t->unsignedTinyInteger('twin_min_corroboration')->default(2));
            $add('twin_auto_link', fn ($t) => $t->boolean('twin_auto_link')->default(false));
            $add('complex_group_enabled', fn ($t) => $t->boolean('complex_group_enabled')->default(true));
            $add('complex_min_land_m2', fn ($t) => $t->unsignedInteger('complex_min_land_m2')->default(1000));
            $add('map_point_check_enabled', fn ($t) => $t->boolean('map_point_check_enabled')->default(true));
            $add('map_point_distance_m', fn ($t) => $t->unsignedSmallInteger('map_point_distance_m')->default(150));
        });

        if (! Schema::hasTable('property_complexes')) {
            Schema::create('property_complexes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('suburb_id')->nullable();
                $t->string('suburb_name', 120)->nullable();
                $t->string('property_type', 50)->nullable();
                $t->decimal('land_size_m2', 12, 2);
                $t->string('name', 200)->nullable();
                $t->unsignedInteger('units_count')->default(0);
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['agency_id', 'suburb_id', 'property_type', 'land_size_m2'], 'uq_property_complexes_key');
            });
        }
        if (! Schema::hasColumn('tracked_properties', 'complex_id')) {
            Schema::table('tracked_properties', function (Blueprint $t) {
                $t->unsignedBigInteger('complex_id')->nullable();
                $t->index('complex_id', 'idx_tp_complex');
            });
        }
        if (! Schema::hasColumn('prospecting_listings', 'map_point_check')) {
            Schema::table('prospecting_listings', function (Blueprint $t) {
                $t->string('map_point_check', 12)->nullable();
                $t->unsignedInteger('map_point_distance_m')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['map_point_distance_m', 'map_point_check'] as $c) {
            if (Schema::hasColumn('prospecting_listings', $c)) {
                Schema::table('prospecting_listings', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        if (Schema::hasColumn('tracked_properties', 'complex_id')) {
            Schema::table('tracked_properties', function (Blueprint $t) {
                $t->dropIndex('idx_tp_complex');
                $t->dropColumn('complex_id');
            });
        }
        Schema::dropIfExists('property_complexes');
        foreach (['portal_group_link_enabled', 'portal_group_price_spread', 'portal_group_auto_link_min_confidence', 'twin_match_enabled', 'twin_price_tolerance_pct', 'twin_min_corroboration', 'twin_auto_link', 'complex_group_enabled', 'complex_min_land_m2', 'map_point_check_enabled', 'map_point_distance_m'] as $c) {
            if (Schema::hasColumn('address_match_settings', $c)) {
                Schema::table('address_match_settings', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
