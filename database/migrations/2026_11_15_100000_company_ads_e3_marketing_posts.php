<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company Ads E-3 — post now / schedule a company ad (.ai/specs/company-ads.md §4, §7).
 *
 * A social post is now about a listing OR a company ad: property_id becomes nullable and company_ad_id is added.
 * Exactly one of the two is set (PropertyMarketingPost's save path refuses anything else). Every existing row has
 * property_id and keeps it; nothing changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_marketing_posts', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->change();
            $table->foreignId('company_ad_id')->nullable()->after('property_id')->constrained('company_ads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('property_marketing_posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_ad_id');
        });
        // Only put NOT NULL back when no company-ad post is left (rows are never deleted to make it fit).
        if (! DB::table('property_marketing_posts')->whereNull('property_id')->exists()) {
            Schema::table('property_marketing_posts', function (Blueprint $table) {
                $table->unsignedBigInteger('property_id')->nullable(false)->change();
            });
        }
    }
};
