<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pp_provinces')) {
            Schema::create('pp_provinces', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('pp_province_id')->unique();
                $t->string('pp_province_enum')->nullable();
                $t->string('name');
                $t->timestamps();
                $t->index('name');
            });
        }

        if (!Schema::hasTable('pp_cities')) {
            Schema::create('pp_cities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('pp_city_id')->unique();
                $t->foreignId('pp_province_id')->constrained('pp_provinces')->cascadeOnDelete();
                $t->string('name');
                $t->timestamps();
                $t->index(['pp_province_id', 'name']);
            });
        }

        if (!Schema::hasTable('pp_suburbs')) {
            Schema::create('pp_suburbs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('pp_suburb_id')->unique();
                $t->foreignId('pp_city_id')->constrained('pp_cities')->cascadeOnDelete();
                $t->string('name');
                $t->string('normalised_name')->index();
                $t->timestamps();
                $t->index(['pp_city_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pp_suburbs');
        Schema::dropIfExists('pp_cities');
        Schema::dropIfExists('pp_provinces');
    }
};
