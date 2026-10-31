<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab, capture lane (.ai/specs/mic-work-tab.md §5 and §11.2).
 *
 *  - prospecting_listings: the public facts the new extension reads off P24 / PP tiles and listing pages
 *    (advertiser type, portal agent/agency ids, rates, levies, the portal's own map point, reduced / exclusive /
 *    badges, P24 group + mandate ids, wording + first-photo fingerprints, when the listing page was last read).
 *    Floor size reuses property_size_m2, erf size reuses erf_size_m2, listed date reuses listed_date.
 *  - prospecting_searches: the crawl-session fields cc4 builds the crawl record from.
 *  - portal_advert_facts (the shared pool): the same PUBLIC facts, so a second agency's first sight is pre-filled.
 *  - p24_group_members: P24's own statement "these listing numbers are one property" (global, public).
 *
 * Every new column is nullable (NULL = not read yet). Nothing existing is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $t) {
            $t->string('advertiser_type', 12)->nullable();
            $t->string('agent_portal_id', 40)->nullable();
            $t->string('agency_portal_id', 40)->nullable();
            $t->unsignedInteger('rates_amount')->nullable();
            $t->unsignedInteger('levies_amount')->nullable();
            $t->decimal('portal_latitude', 10, 7)->nullable();
            $t->decimal('portal_longitude', 10, 7)->nullable();
            $t->string('portal_geo_source', 12)->nullable();
            $t->boolean('is_reduced')->nullable();
            $t->boolean('is_exclusive')->nullable();
            $t->json('badges')->nullable();
            $t->string('p24_group_id', 30)->nullable();
            $t->string('p24_mandate_id', 30)->nullable();
            $t->char('wording_fingerprint', 40)->nullable();
            $t->char('first_photo_fingerprint', 40)->nullable();
            $t->timestamp('details_read_at')->nullable();
            $t->unsignedInteger('details_read_price')->nullable();
            $t->index(['agency_id', 'advertiser_type'], 'idx_pl_agency_advertiser');
            $t->index('p24_group_id', 'idx_pl_p24_group');
            $t->index('p24_mandate_id', 'idx_pl_p24_mandate');
        });

        Schema::table('prospecting_searches', function (Blueprint $t) {
            $t->char('crawl_session_id', 36)->nullable();
            $t->string('crawl_portal', 4)->nullable();
            $t->string('crawl_suburb', 120)->nullable();
            $t->unsignedSmallInteger('crawl_page')->nullable();
            $t->unsignedSmallInteger('crawl_pages_total')->nullable();
            $t->boolean('crawl_complete')->nullable();
            $t->timestamp('crawl_started_at')->nullable();
            $t->timestamp('crawl_finished_at')->nullable();
            $t->index('crawl_session_id', 'idx_ps_crawl_session');
        });

        Schema::table('portal_advert_facts', function (Blueprint $t) {
            $t->string('advertiser_type', 12)->nullable();
            $t->string('agent_portal_id', 40)->nullable();
            $t->string('agency_portal_id', 40)->nullable();
            $t->date('listed_date')->nullable();
            $t->unsignedInteger('rates_amount')->nullable();
            $t->unsignedInteger('levies_amount')->nullable();
            $t->boolean('is_reduced')->nullable();
            $t->boolean('is_exclusive')->nullable();
            $t->json('badges')->nullable();
            $t->string('p24_group_id', 30)->nullable();
            $t->string('p24_mandate_id', 30)->nullable();
            $t->char('wording_fingerprint', 40)->nullable();
            $t->char('first_photo_fingerprint', 40)->nullable();
            $t->index('p24_group_id', 'idx_paf_p24_group');
        });

        Schema::create('p24_group_members', function (Blueprint $t) {
            $t->id();
            $t->string('p24_group_id', 30);
            $t->string('listing_number', 20);
            $t->unsignedSmallInteger('group_size')->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
            $t->unique(['p24_group_id', 'listing_number'], 'uniq_p24gm_group_listing');
            $t->index('listing_number', 'idx_p24gm_listing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('p24_group_members');

        Schema::table('portal_advert_facts', function (Blueprint $t) {
            $t->dropIndex('idx_paf_p24_group');
            $t->dropColumn(['advertiser_type', 'agent_portal_id', 'agency_portal_id', 'listed_date', 'rates_amount', 'levies_amount',
                'is_reduced', 'is_exclusive', 'badges', 'p24_group_id', 'p24_mandate_id', 'wording_fingerprint', 'first_photo_fingerprint']);
        });

        Schema::table('prospecting_searches', function (Blueprint $t) {
            $t->dropIndex('idx_ps_crawl_session');
            $t->dropColumn(['crawl_session_id', 'crawl_portal', 'crawl_suburb', 'crawl_page', 'crawl_pages_total',
                'crawl_complete', 'crawl_started_at', 'crawl_finished_at']);
        });

        Schema::table('prospecting_listings', function (Blueprint $t) {
            $t->dropIndex('idx_pl_agency_advertiser');
            $t->dropIndex('idx_pl_p24_group');
            $t->dropIndex('idx_pl_p24_mandate');
            $t->dropColumn(['advertiser_type', 'agent_portal_id', 'agency_portal_id', 'rates_amount', 'levies_amount',
                'portal_latitude', 'portal_longitude', 'portal_geo_source', 'is_reduced', 'is_exclusive', 'badges',
                'p24_group_id', 'p24_mandate_id', 'wording_fingerprint', 'first_photo_fingerprint', 'details_read_at', 'details_read_price']);
        });
    }
};
