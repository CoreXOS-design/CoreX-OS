<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P24 location tables. p24_countries / p24_provinces / p24_cities already
 * existed in the database with the expected shape (id, p24_id, parent FK,
 * name, timestamps). p24_suburbs also exists from an older feed but lacks
 * a p24_city_id link — we add it here so the cascading
 * Country → Province → City → Suburb tree is complete.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('p24_countries')) {
            Schema::create('p24_countries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('p24_id')->unique();
                $t->string('name');
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('p24_provinces')) {
            Schema::create('p24_provinces', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('p24_id')->unique();
                $t->foreignId('p24_country_id')->constrained('p24_countries')->cascadeOnDelete();
                $t->string('name');
                $t->timestamps();
                $t->index(['p24_country_id', 'name']);
            });
        }

        if (!Schema::hasTable('p24_cities')) {
            Schema::create('p24_cities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('p24_id')->unique();
                $t->foreignId('p24_province_id')->constrained('p24_provinces')->cascadeOnDelete();
                $t->string('name');
                $t->timestamps();
                $t->index(['p24_province_id', 'name']);
            });
        }

        if (!Schema::hasTable('p24_suburbs')) {
            Schema::create('p24_suburbs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('p24_id')->unique();
                $t->foreignId('p24_city_id')->nullable()->constrained('p24_cities')->nullOnDelete();
                $t->string('name');
                $t->timestamps();
                $t->softDeletes();
                $t->index(['p24_city_id', 'name']);
                $t->index('name');
            });
            return;
        }

        // p24_suburbs pre-exists from an earlier feed — patch it.
        Schema::table('p24_suburbs', function (Blueprint $t) {
            if (!Schema::hasColumn('p24_suburbs', 'p24_city_id')) {
                $t->foreignId('p24_city_id')->nullable()->after('p24_id')
                    ->constrained('p24_cities')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('p24_suburbs', 'p24_city_id')) {
            Schema::table('p24_suburbs', function (Blueprint $t) {
                $t->dropForeign(['p24_city_id']);
                $t->dropColumn('p24_city_id');
            });
        }
    }
};
