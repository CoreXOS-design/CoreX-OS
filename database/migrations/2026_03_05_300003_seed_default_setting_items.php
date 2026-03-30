<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $groups = [
            'category' => [
                'Residential', 'Commercial', 'Industrial', 'Retirement', 'Holiday', 'Project',
            ],
            'property_status' => [
                'Sales Listing', 'For Sale', 'Reduced Price', 'Pending',
                'Back on Market', 'Raised Price', 'Sold', 'Under Offer',
                'On Show', 'On Auction', 'Draft', 'Withdrawn', 'Unavailable', 'Archived',
            ],
            'mandate_type' => [
                'Open', 'Joint', 'Sole', 'Dual',
            ],
        ];

        foreach ($groups as $group => $names) {
            foreach ($names as $i => $name) {
                $exists = DB::table('property_setting_items')
                    ->where('group', $group)
                    ->where('name', $name)
                    ->exists();

                if (! $exists) {
                    DB::table('property_setting_items')->insert([
                        'group'      => $group,
                        'name'       => $name,
                        'sort_order' => $i,
                        'is_default' => 1,
                        'active'     => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('property_setting_items')
                        ->where('group', $group)
                        ->where('name', $name)
                        ->update(['is_default' => 1]);
                }
            }
        }
    }

    public function down(): void
    {
        $groups = [
            'category'        => ['Residential', 'Commercial', 'Industrial', 'Retirement', 'Holiday', 'Project'],
            'property_status' => ['Sales Listing', 'For Sale', 'Reduced Price', 'Pending', 'Back on Market', 'Raised Price', 'Sold', 'Under Offer', 'On Show', 'On Auction', 'Draft', 'Withdrawn', 'Unavailable', 'Archived'],
            'mandate_type'    => ['Open', 'Joint', 'Sole', 'Dual'],
        ];

        foreach ($groups as $group => $names) {
            DB::table('property_setting_items')
                ->where('group', $group)
                ->whereIn('name', $names)
                ->where('is_default', 1)
                ->delete();
        }
    }
};
