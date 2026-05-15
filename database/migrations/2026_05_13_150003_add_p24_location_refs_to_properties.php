<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->foreignId('p24_suburb_id')->nullable()->after('suburb')
                ->constrained('p24_suburbs')->nullOnDelete();
            $t->foreignId('p24_city_id')->nullable()->after('p24_suburb_id')
                ->constrained('p24_cities')->nullOnDelete();
            $t->foreignId('p24_province_id')->nullable()->after('p24_city_id')
                ->constrained('p24_provinces')->nullOnDelete();
            $t->boolean('p24_suburb_mismatch')->default(false)->after('p24_province_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->dropForeign(['p24_suburb_id']);
            $t->dropForeign(['p24_city_id']);
            $t->dropForeign(['p24_province_id']);
            $t->dropColumn(['p24_suburb_id', 'p24_city_id', 'p24_province_id', 'p24_suburb_mismatch']);
        });
    }
};
