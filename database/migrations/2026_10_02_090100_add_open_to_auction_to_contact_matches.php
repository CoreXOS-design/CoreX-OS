<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md §6. A buyer who will
 * not bid at an auction must not be matched to auction lots. Default TRUE, so
 * every existing wishlist keeps matching exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->boolean('open_to_auction')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('contact_matches', function (Blueprint $table) {
            $table->dropColumn('open_to_auction');
        });
    }
};
