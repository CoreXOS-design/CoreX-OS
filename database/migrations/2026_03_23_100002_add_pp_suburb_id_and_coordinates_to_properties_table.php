<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->unsignedInteger('pp_suburb_id')->nullable()->after('pp_listing_last_synced_at');
            $table->decimal('latitude', 10, 7)->nullable()->after('pp_suburb_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('street_name')->nullable()->after('address');
            $table->string('street_number')->nullable()->after('street_name');
            $table->string('province')->nullable()->after('district');
            $table->string('town')->nullable()->after('province');
            $table->string('headline')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'pp_suburb_id',
                'latitude',
                'longitude',
                'street_name',
                'street_number',
                'province',
                'town',
                'headline',
            ]);
        });
    }
};
