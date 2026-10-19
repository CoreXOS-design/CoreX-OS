<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Ads, step E-1 (.ai/specs/company-ads.md §4).
 *
 * company_ads       — the agency's brand ads (season's greetings, closures, welcomes). Not tied to a property.
 * company_ad_images — pictures an agency uploaded to PLACE in a company ad (public disk, same-origin so the
 *                     export renders them). Never purged: an ad must never be left pointing at a dead picture.
 *
 * Both BelongsToAgency + SoftDeletes (no hard delete anywhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('occasion', 40)->nullable();   // key from config/company-ad-library.php; null = Other
            $table->string('canvas', 20)->default('facebook');
            $table->json('layout_json');
            $table->text('caption')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->text('ai_idea')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'deleted_at', 'updated_at'], 'company_ads_agency_list_idx');
        });

        Schema::create('company_ad_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'deleted_at'], 'company_ad_images_agency_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_ad_images');
        Schema::dropIfExists('company_ads');
    }
};
