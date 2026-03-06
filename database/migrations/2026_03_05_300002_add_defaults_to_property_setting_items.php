<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_setting_items', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('sort_order');
            $table->boolean('active')->default(true)->after('is_default');
        });

        $defaults = [
            'Apartment', 'Cluster', 'Cottage', 'Development', 'Dual Living',
            'Duet', 'Duplex', 'Garage', 'House', 'Penthouse', 'Plot', 'Room',
            'Small Holding', 'Studio Apartment', 'Townhouse', 'Vacant Land', 'Villa',
        ];

        foreach ($defaults as $i => $name) {
            // Only insert if not already present
            $exists = DB::table('property_setting_items')
                ->where('group', 'property_type')
                ->where('name', $name)
                ->exists();

            if (! $exists) {
                DB::table('property_setting_items')->insert([
                    'group'      => 'property_type',
                    'name'       => $name,
                    'sort_order' => $i,
                    'is_default' => 1,
                    'active'     => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Mark existing entries as default
                DB::table('property_setting_items')
                    ->where('group', 'property_type')
                    ->where('name', $name)
                    ->update(['is_default' => 1]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('property_setting_items', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'active']);
        });
    }
};
