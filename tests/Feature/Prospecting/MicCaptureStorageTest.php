<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\P24GroupMember;
use App\Models\ProspectingListing;
use App\Services\Prospecting\SharedPortalPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MIC capture lane step 3.2 (.ai/specs/mic-work-tab.md §11.2): the storage the new extension fields land in.
 */
final class MicCaptureStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_search_pool_and_group_tables_have_the_contract_columns(): void
    {
        foreach (['advertiser_type', 'agent_portal_id', 'agency_portal_id', 'rates_amount', 'levies_amount', 'portal_latitude',
            'portal_longitude', 'portal_geo_source', 'is_reduced', 'is_exclusive', 'badges', 'p24_group_id', 'p24_mandate_id',
            'wording_fingerprint', 'first_photo_fingerprint', 'details_read_at', 'details_read_price'] as $col) {
            $this->assertTrue(Schema::hasColumn('prospecting_listings', $col), "prospecting_listings.$col");
        }
        foreach (['crawl_session_id', 'crawl_portal', 'crawl_suburb', 'crawl_page', 'crawl_pages_total', 'crawl_complete',
            'crawl_started_at', 'crawl_finished_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('prospecting_searches', $col), "prospecting_searches.$col");
        }
        foreach (['advertiser_type', 'agent_portal_id', 'agency_portal_id', 'listed_date', 'rates_amount', 'levies_amount', 'is_reduced',
            'is_exclusive', 'badges', 'p24_group_id', 'p24_mandate_id', 'wording_fingerprint', 'first_photo_fingerprint'] as $col) {
            $this->assertTrue(Schema::hasColumn('portal_advert_facts', $col), "portal_advert_facts.$col");
        }
        $this->assertTrue(Schema::hasColumns('p24_group_members', ['p24_group_id', 'listing_number', 'group_size', 'first_seen_at', 'seen_at']));
        $this->assertFalse(Schema::hasColumn('p24_group_members', 'agency_id'), 'group membership is a global public fact');
    }

    public function test_group_member_is_unique_per_group_and_listing_number(): void
    {
        P24GroupMember::create(['p24_group_id' => '5551', 'listing_number' => '116922912', 'group_size' => 3]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        P24GroupMember::create(['p24_group_id' => '5551', 'listing_number' => '116922912', 'group_size' => 3]);
    }

    public function test_listing_model_casts_the_new_columns(): void
    {
        $l = new ProspectingListing(['badges' => ['auction', 'on_show'], 'is_reduced' => 1, 'details_read_at' => '2026-10-31 10:00:00']);
        $this->assertSame(['auction', 'on_show'], $l->badges);
        $this->assertTrue($l->is_reduced);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $l->details_read_at);
    }

    public function test_pool_stores_the_advert_facts_but_find_does_not_return_them_as_property_prefill(): void
    {
        $agency = \App\Models\Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $pool = app(SharedPortalPool::class);
        $pool->contribute('p24:116922912', [
            'suburb' => 'Margate', 'bedrooms' => 3, 'advertiser_type' => 'owner', 'badges' => ['auction'], 'is_reduced' => true,
            'p24_group_id' => '5551', 'wording_fingerprint' => str_repeat('a', 40), 'rates_amount' => 1200, 'listed_date' => '2026-09-01',
        ], $agency->id);

        $row = DB::table('portal_advert_facts')->where('canonical_ref', 'p24:116922912')->first();
        $this->assertSame('owner', $row->advertiser_type);
        $this->assertSame(['auction'], json_decode($row->badges, true));
        $this->assertSame(1, (int) $row->is_reduced);
        $this->assertSame('5551', $row->p24_group_id);
        $this->assertSame('2026-09-01', substr((string) $row->listed_date, 0, 10));

        $found = $pool->find('p24:116922912');
        $this->assertSame(3, (int) $found['bedrooms']);
        foreach (['advertiser_type', 'badges', 'is_reduced', 'p24_group_id', 'wording_fingerprint', 'rates_amount', 'listed_date'] as $k) {
            $this->assertArrayNotHasKey($k, $found);
        }
        $this->assertNotContains('agency_id', SharedPortalPool::writableColumns());
    }
}
