<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Not for my buyer" (.ai/specs/mic-work-tab.md 13.17): one durable record that a property is not for a buyer, shared by MIC and Core Matches.
 * Keyed by the property FILE (tracked_property_id; a lone advert by its prospecting_listing_id), per BUYER (all of his wishlists). Active = restored_at IS NULL.
 * Never deleted: hide and restore are both kept for the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_property_exclusions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('tracked_property_id')->nullable();
            $table->unsignedBigInteger('prospecting_listing_id')->nullable();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('source', 20)->default('mic');
            $table->unsignedBigInteger('hidden_by_user_id')->nullable();
            $table->timestamp('hidden_at');
            $table->unsignedBigInteger('restored_by_user_id')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'contact_id', 'restored_at'], 'bpe_buyer_active');
            $table->index('tracked_property_id', 'bpe_tracked');
            $table->index('prospecting_listing_id', 'bpe_listing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_property_exclusions');
    }
};
