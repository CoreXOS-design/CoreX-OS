<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auctions v2 Preview page (.ai/specs/auctions.md §16): the wording and pictures an agency can edit on its public auction page. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_auction_settings', function (Blueprint $t) {
            $t->string('hero_image_path', 500)->nullable()->after('contact_phone');
            $t->string('accent_color', 7)->nullable()->after('hero_image_path');
            $t->text('how_it_works')->nullable()->after('accent_color');
            $t->text('footer_text')->nullable()->after('how_it_works');
            $t->boolean('show_past_auctions')->nullable()->after('footer_text');
        });
    }

    public function down(): void
    {
        Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->dropColumn(['hero_image_path', 'accent_color', 'how_it_works', 'footer_text', 'show_past_auctions']));
    }
};
