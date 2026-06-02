<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — Phase 1a.
 *
 * Per-(property × website) syndication state. The website is a Syndication
 * Portal exactly like P24/PP, but because an agency can have many websites
 * (many keys), state is a pivot keyed by (property_id, agency_api_key_id)
 * rather than columns on `properties`. Mirrors the pp_ and p24_ tracking
 * fields. A listing reaches a given website only when its row here is
 * enabled.
 *
 * Spec: .ai/specs/agency-public-api.md §6.5.2
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('property_website_syndication')) {
            return;
        }

        Schema::create('property_website_syndication', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('agency_api_key_id')->index();

            $table->boolean('enabled')->default(false);

            // pending / submitted / active / deactivated / error — mirrors pp_syndication_status.
            $table->string('status')->nullable();

            $table->timestamp('last_submitted_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('property_id')->references('id')->on('properties')->cascadeOnDelete();
            $table->foreign('agency_api_key_id')->references('id')->on('agency_api_keys')->cascadeOnDelete();

            // One state row per property per website.
            $table->unique(['property_id', 'agency_api_key_id'], 'property_website_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_website_syndication');
    }
};
