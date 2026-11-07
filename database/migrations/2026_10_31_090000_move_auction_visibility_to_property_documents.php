<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auctions v2 fix. The property Drive stores its files as `documents` linked through `document_properties`
 * (property_files is deprecated), so the "Show on auction page" flag belongs on that link — one document can sit
 * on several properties and be public on one and hidden on another. The phase-1 column on property_files was never
 * read by a real upload and is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_properties', function (Blueprint $t) {
            $t->string('auction_visibility', 12)->nullable();   // null = not in the auction pack | public | registered
        });

        if (Schema::hasColumn('property_files', 'auction_visibility')) {
            Schema::table('property_files', fn (Blueprint $t) => $t->dropColumn('auction_visibility'));
        }
    }

    public function down(): void
    {
        Schema::table('document_properties', fn (Blueprint $t) => $t->dropColumn('auction_visibility'));
        Schema::table('property_files', fn (Blueprint $t) => $t->string('auction_visibility', 12)->nullable());
    }
};
