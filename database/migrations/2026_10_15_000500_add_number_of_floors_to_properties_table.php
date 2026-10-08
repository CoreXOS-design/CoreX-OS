<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/other-agency-stock.md §5f — Property24's Building section states "Number of floors" for the
 * whole building. CoreX had nowhere to hold it (floor_number is the unit's own floor, not the building's
 * height), so it gets its own nullable count. Nothing is backfilled: an empty value means "not stated".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (! Schema::hasColumn('properties', 'number_of_floors')) {
                $table->unsignedSmallInteger('number_of_floors')->nullable()->after('floor_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'number_of_floors')) {
                $table->dropColumn('number_of_floors');
            }
        });
    }
};
