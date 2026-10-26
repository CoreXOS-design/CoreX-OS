<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The country an agency's addresses are geocoded in (the accepted area). NULL = the config default (ZA).
 * Spec: .ai/specs/geocoding-spec.md "Accepted area".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('agencies', 'geocode_country')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->string('geocode_country', 2)->nullable()->after('slug');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agencies', 'geocode_country')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->dropColumn('geocode_country');
            });
        }
    }
};
