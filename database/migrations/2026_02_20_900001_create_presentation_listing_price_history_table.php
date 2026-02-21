<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presentation_listing_price_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('presentation_id');
            $table->unsignedBigInteger('active_listing_id');
            $table->unsignedBigInteger('price_inc');
            $table->timestamp('captured_at');
            $table->unsignedBigInteger('source_snapshot_id')->nullable();
            $table->timestamps();

            $table->foreign('presentation_id')
                  ->references('id')->on('presentations')
                  ->onDelete('cascade');

            $table->foreign('active_listing_id')
                  ->references('id')->on('presentation_active_listings')
                  ->onDelete('cascade');

            $table->index(['active_listing_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_listing_price_history');
    }
};
