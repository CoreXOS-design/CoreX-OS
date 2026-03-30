<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $renames = [
            'For Sale • Reduced Price'  => 'Reduced Price',
            'For Sale • Pending'        => 'Pending',
            'For Sale • Back on Market' => 'Back on Market',
            'For Sale • Raised Price'   => 'Raised Price',
        ];

        foreach ($renames as $old => $new) {
            DB::table('property_setting_items')
                ->where('group', 'property_status')
                ->where('name', $old)
                ->update(['name' => $new]);
        }

        // Also update any properties that have the old status values
        $statusRenames = [
            'for_sale_•_reduced_price'  => 'reduced_price',
            'for_sale_•_pending'        => 'pending',
            'for_sale_•_back_on_market' => 'back_on_market',
            'for_sale_•_raised_price'   => 'raised_price',
        ];

        foreach ($statusRenames as $old => $new) {
            DB::table('properties')
                ->where('status', $old)
                ->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        $renames = [
            'Reduced Price'  => 'For Sale • Reduced Price',
            'Pending'        => 'For Sale • Pending',
            'Back on Market' => 'For Sale • Back on Market',
            'Raised Price'   => 'For Sale • Raised Price',
        ];

        foreach ($renames as $old => $new) {
            DB::table('property_setting_items')
                ->where('group', 'property_status')
                ->where('name', $old)
                ->update(['name' => $new]);
        }

        $statusRenames = [
            'reduced_price'  => 'for_sale_•_reduced_price',
            'pending'        => 'for_sale_•_pending',
            'back_on_market' => 'for_sale_•_back_on_market',
            'raised_price'   => 'for_sale_•_raised_price',
        ];

        foreach ($statusRenames as $old => $new) {
            DB::table('properties')
                ->where('status', $old)
                ->update(['status' => $new]);
        }
    }
};
