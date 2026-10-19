<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Ads E-2 — the "Create company ad with AI" pop-up (.ai/specs/company-ads.md §4, §8).
 *
 * A company-ad request is one more row in ai_template_generations (decision C2: one shared monthly allowance, so the
 * existing counter keeps counting every success row whatever its kind). Existing rows are property-template requests
 * and get kind = property_template from the default; nothing else about them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->string('kind', 20)->default('property_template')->after('user_id');
            $table->string('occasion', 40)->nullable()->after('kind');
            // place = the user's own picture is put in the ad; library = a built-in picture; look = an uploaded post used
            // as inspiration only (stored in reference_image_path, purged after 7 days); none = no picture.
            $table->string('image_mode', 10)->nullable()->after('occasion');
            $table->foreignId('company_ad_image_id')->nullable()->after('image_mode')->constrained('company_ad_images')->nullOnDelete();
            $table->string('library_picture', 255)->nullable()->after('company_ad_image_id');
            // option index => company_ads.id, so saving the same design twice returns the ad made the first time.
            $table->json('saved_company_ad_ids')->nullable()->after('saved_template_ids');
        });
    }

    public function down(): void
    {
        Schema::table('ai_template_generations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_ad_image_id');
            $table->dropColumn(['kind', 'occasion', 'image_mode', 'library_picture', 'saved_company_ad_ids']);
        });
    }
};
