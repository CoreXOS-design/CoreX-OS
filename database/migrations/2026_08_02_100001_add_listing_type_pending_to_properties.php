<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-262 — duplicate/change-listing-type (Andre's design + Johan's extension).
 *
 * A duplicate opens in a COMPLETABLE DRAFT where the listing type is NOT locked
 * until the user completes it (unlike a normal existing property, where type is
 * immutable). This flag marks that pre-completion window: while true, the listing
 * type is editable; the first real save clears it and the type locks as usual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('listing_type_pending')->default(false)->after('listing_type');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('listing_type_pending');
        });
    }
};
