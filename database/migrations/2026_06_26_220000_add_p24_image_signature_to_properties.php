<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-P24: store a fingerprint of the image set last successfully synced to
 * Property24, so a re-submit can send `photos: null` (P24 keeps existing
 * photos) when the gallery has not changed — turning a routine refresh from a
 * full ~30-80s photo re-upload into a couple of seconds. Photos are only
 * re-uploaded when this signature differs from the property's current gallery
 * fingerprint (i.e. an image was added / deleted / reordered / recaptioned).
 *
 * Added at the end of the table (no AFTER) so MySQL 8 uses the INSTANT
 * algorithm — no table rebuild, no write lock on the live properties table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('p24_image_signature', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('p24_image_signature');
        });
    }
};
