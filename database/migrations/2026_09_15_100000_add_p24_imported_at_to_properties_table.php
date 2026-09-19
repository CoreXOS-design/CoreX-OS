<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // AT-419 — stamped once by ConfirmP24PropertyRowJob the first time a
            // property is confirmed from a P24 import. Doubles as the "did this
            // come from the importer" flag and the "Imported Date" shown on the
            // new Imported Stock page. Null for every property not from the P24
            // importer, and for pre-AT-419 imports until backfilled on purpose.
            $table->timestamp('p24_imported_at')->nullable()->after('p24_listing_number');
            $table->index('p24_imported_at');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex(['p24_imported_at']);
            $table->dropColumn('p24_imported_at');
        });
    }
};
