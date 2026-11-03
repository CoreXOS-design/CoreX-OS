<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property identity A3 (.ai/specs/property-identity.md §5, §6, §4 "Link columns").
 *
 *  - address_match_settings: the two new agency settings (fuzzy threshold, auto-merge minimum confidence).
 *  - A property-file link (tracked_property_id + the confidence and method it was decided with) on the
 *    tables that had none: own stock, email alerts, deals, CMA comparable rows, presentation sold comps.
 *
 * Every column is nullable / defaulted. Nothing existing is changed or dropped.
 */
return new class extends Migration
{
    /** table => the column to place the link after (null = append) */
    private const LINKED = [
        'properties'              => null,
        'p24_listings'            => 'canonical_ref',
        'deals'                   => null,
        'market_report_comp_rows' => null,
        'presentation_sold_comps' => null,
    ];

    public function up(): void
    {
        Schema::table('address_match_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('fuzzy_threshold')->default(85);
            $t->string('auto_merge_min_confidence', 10)->default('high');
        });

        foreach (self::LINKED as $table => $after) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unsignedBigInteger('tracked_property_id')->nullable();
                $t->string('identity_confidence', 10)->nullable();
                $t->string('identity_method', 30)->nullable();
                $t->index('tracked_property_id', 'idx_' . substr($table, 0, 20) . '_tp');
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::LINKED) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex('idx_' . substr($table, 0, 20) . '_tp');
                $t->dropColumn(['tracked_property_id', 'identity_confidence', 'identity_method']);
            });
        }
        Schema::table('address_match_settings', function (Blueprint $t) {
            $t->dropColumn(['fuzzy_threshold', 'auto_merge_min_confidence']);
        });
    }
};
