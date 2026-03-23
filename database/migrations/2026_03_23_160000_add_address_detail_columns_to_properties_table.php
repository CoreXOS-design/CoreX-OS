<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('floor_number', 50)->nullable()->after('unit_number');
            $table->string('unit_section_block', 255)->nullable()->after('floor_number');
            $table->string('stand_number', 100)->nullable()->after('property_number');
            $table->string('zone_type', 100)->nullable()->after('stand_number');
            $table->text('address_internal_note')->nullable()->after('zone_type');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'floor_number',
                'unit_section_block',
                'stand_number',
                'zone_type',
                'address_internal_note',
            ]);
        });
    }
};
