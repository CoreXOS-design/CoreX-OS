<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The map's fallback view is now neutral (the area of an agency's OWN properties, else South Africa) instead of the KZN South Coast box that
 * used to be the install-wide default (Cape walk, 14 Oct 2026). Home Finders Coastal's map must open exactly where it always has, so the
 * old default is written into its own settings row once - only for the columns still blank.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('agency_map_settings')) {
            return;
        }
        $agencyId = DB::table('agencies')->where('slug', 'hfc-coastal')->value('id');
        if (! $agencyId) {
            return;
        }

        $row = DB::table('agency_map_settings')->where('agency_id', $agencyId)->first();
        $old = [
            'center_lat' => -30.70, 'center_lng' => 30.45, 'default_zoom' => 11,
            'bounds_north' => -30.40, 'bounds_south' => -31.00, 'bounds_east' => 30.90, 'bounds_west' => 30.00,
        ];

        if (! $row) {
            DB::table('agency_map_settings')->insert(['agency_id' => $agencyId, 'created_at' => now(), 'updated_at' => now()] + $old);

            return;
        }

        $fill = [];
        foreach ($old as $col => $value) {
            if ($row->{$col} === null) {
                $fill[$col] = $value;
            }
        }
        if ($fill !== []) {
            DB::table('agency_map_settings')->where('id', $row->id)->update($fill + ['updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // data - not reversed
    }
};
