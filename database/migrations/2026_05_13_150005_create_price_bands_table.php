<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_bands', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->enum('listing_type', ['sale', 'rental']);
            $table->string('name', 100);
            // Price stored as RAND (not cents) to match the existing
            // contact_matches.price_min convention. Width is unsignedBigInteger
            // so commercial / land prices above the 32-bit limit fit.
            $table->unsignedBigInteger('price_min');
            // NULL = no upper bound (the Premium / Luxury case).
            $table->unsignedBigInteger('price_max')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();

            $table->index(['agency_id', 'listing_type', 'display_order'], 'price_bands_agency_type_order_idx');
            $table->index(['agency_id', 'listing_type', 'price_min'], 'price_bands_agency_type_min_idx');
            $table->index('deleted_at', 'price_bands_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('price_bands', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('price_bands_agency_type_order_idx');
            $table->dropIndex('price_bands_agency_type_min_idx');
            $table->dropIndex('price_bands_deleted_idx');
        });
        Schema::dropIfExists('price_bands');
    }
};
