<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\ProspectingSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MIC capture lane step 3.4 (.ai/specs/mic-work-tab.md §11.2): POST /api/v1/prospecting/import accepts and stores the
 * public advert facts the new extension reads, drops malformed values without failing the batch, and keeps older
 * extension builds working.
 */
final class MicCaptureIngestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/prospecting/import';

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Margate Agency', 'slug' => 'margate-agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id]);
    }

    private function body(array $listings, array $context = []): array
    {
        return [
            'source' => 'p24',
            'search_context' => $context + [
                'url' => 'https://www.property24.com/for-sale/margate/kwazulu-natal/1/p1',
                'search_term' => 'Margate for sale',
                'total_results' => count($listings),
                'pages_captured' => 1,
            ],
            'listings' => $listings,
        ];
    }

    private function tile(array $over = []): array
    {
        return $over + ['portal_ref' => 'P24-116922912', 'suburb' => 'Margate', 'price' => 1500000, 'bedrooms' => 3,
            'portal_url' => 'https://www.property24.com/for-sale/margate/116922912', 'agency_name' => 'Pam Golding'];
    }

    private function listing(string $ref = 'P24-116922912'): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('portal_ref', $ref)->firstOrFail();
    }

    public function test_tile_facts_are_stored_and_the_advert_is_offered_for_a_listing_page_read(): void
    {
        Sanctum::actingAs($this->user);
        $res = $this->postJson(self::URL, $this->body([$this->tile([
            'advertiser_type' => 'agency', 'agency_portal_id' => '8821', 'is_reduced' => true, 'is_exclusive' => false,
            'badges' => ['auction', 'on_show', 'not-a-badge'], 'p24_mandate_id' => '445566',
            'property_size_m2' => 96, 'erf_size_m2' => 1200,
            'wording_fingerprint' => str_repeat('ab', 20), 'first_photo_fingerprint' => str_repeat('0f', 20),
        ])]))->assertOk();

        $res->assertJsonPath('imported', 1)->assertJsonPath('needs_detail.0', 'P24-116922912')
            ->assertJsonPath('limits.detail_pages_per_session', 40)->assertJsonPath('dropped_fields', 0);

        $l = $this->listing();
        $this->assertSame('agency', $l->advertiser_type);
        $this->assertSame('8821', $l->agency_portal_id);
        $this->assertTrue($l->is_reduced);
        $this->assertFalse($l->is_exclusive);
        $this->assertSame(['auction', 'on_show'], $l->badges);
        $this->assertSame('445566', $l->p24_mandate_id);
        $this->assertSame('96.00', (string) $l->property_size_m2);
        $this->assertSame('1200.00', (string) $l->erf_size_m2);
        $this->assertSame(str_repeat('ab', 20), $l->wording_fingerprint);
    }

    public function test_p24_group_members_are_recorded_with_the_adverts_own_number_and_shared_globally(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile([
            'p24_group_id' => '7001', 'p24_group_size' => 3, 'p24_group_listing_numbers' => ['116922912', '116922999', 'x1', '118000001'],
        ])]))->assertOk()->assertJsonPath('groups_recorded', 3);

        $rows = DB::table('p24_group_members')->where('p24_group_id', '7001')->orderBy('listing_number')->get();
        $this->assertSame(['116922912', '116922999', '118000001'], $rows->pluck('listing_number')->all());
        $this->assertSame([3], $rows->pluck('group_size')->unique()->map(fn ($v) => (int) $v)->all());

        // re-sending refreshes, never duplicates
        $this->postJson(self::URL, $this->body([$this->tile([
            'p24_group_id' => '7001', 'p24_group_listing_numbers' => ['116922912', '116922999'],
        ])]))->assertOk();
        $this->assertSame(3, DB::table('p24_group_members')->where('p24_group_id', '7001')->count());
    }

    public function test_owner_advert_never_keeps_an_agency_name(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile(['advertiser_type' => 'owner', 'agency_name' => 'Sale'])]))->assertOk();
        $l = $this->listing();
        $this->assertSame('owner', $l->advertiser_type);
        $this->assertNull($l->agency_name);
    }

    public function test_an_older_extension_sending_sale_as_the_agency_is_still_read_as_owner(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile(['agency_name' => 'Sale'])]))->assertOk();
        $l = $this->listing();
        $this->assertSame('owner', $l->advertiser_type);
        $this->assertNull($l->agency_name);
    }

    public function test_an_older_extension_payload_still_imports_and_leaves_the_new_columns_empty(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk()->assertJsonPath('imported', 1);
        $l = $this->listing();
        $this->assertNull($l->advertiser_type);
        $this->assertNull($l->badges);
        $this->assertNull($l->is_reduced);
        $this->assertSame('Pam Golding', $l->agency_name);
    }

    public function test_malformed_values_are_dropped_and_counted_without_failing_the_batch(): void
    {
        Sanctum::actingAs($this->user);
        $res = $this->postJson(self::URL, $this->body([
            $this->tile(['advertiser_type' => 'martian', 'wording_fingerprint' => 'nothex', 'portal_latitude' => 48.1, 'portal_longitude' => 2.3,
                'rates_amount' => -5, 'listed_date' => '2031-01-01', 'page_read' => true, 'p24_mandate_id' => 'abc']),
            $this->tile(['portal_ref' => 'P24-116922913', 'advertiser_type' => 'owner']),
        ]))->assertOk();

        $res->assertJsonPath('imported', 2)->assertJsonPath('dropped_fields', 6);
        $bad = $this->listing();
        $this->assertNull($bad->advertiser_type);
        $this->assertNull($bad->portal_latitude);
        $this->assertNull($bad->listed_date);
        $this->assertSame('owner', $this->listing('P24-116922913')->advertiser_type);
    }

    public function test_a_later_tile_without_the_new_keys_never_blanks_what_a_listing_page_stored(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk();
        $this->postJson(self::URL, $this->body([$this->tile([
            'agent_name' => 'Jane Smith', 'agent_portal_id' => '55', 'listed_date' => '2026-09-01', 'rates_amount' => 1450,
            'levies_amount' => 2100, 'portal_latitude' => -30.8588, 'portal_longitude' => 30.3711, 'page_read' => true,
        ])], ['detail_only' => true]))->assertOk()->assertJsonPath('needs_detail', []);

        // an ordinary tile re-sight, same price, none of the new keys, no agent
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk()->assertJsonPath('needs_detail', []);

        $l = $this->listing();
        $this->assertSame('Jane Smith', $l->agent_name);
        $this->assertSame('55', $l->agent_portal_id);
        $this->assertSame('2026-09-01', $l->listed_date->toDateString());
        $this->assertSame('portal', $l->listed_date_source);
        $this->assertSame(1450, (int) $l->rates_amount);
        $this->assertSame(2100, (int) $l->levies_amount);
        $this->assertSame('jsonld', $l->portal_geo_source);
        $this->assertEqualsWithDelta(-30.8588, (float) $l->portal_latitude, 0.00001);
        $this->assertNotNull($l->details_read_at);
        $this->assertSame(1500000, (int) $l->details_read_price);
    }

    public function test_detail_only_batches_skip_unknown_adverts_and_do_not_count_as_a_crawl(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk();
        $search = ProspectingSearch::where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame(1, $search->listing_count);

        $res = $this->postJson(self::URL, $this->body([
            $this->tile(['agent_name' => 'Jane Smith']),
            $this->tile(['portal_ref' => 'P24-999999999', 'agent_name' => 'Nobody']),
        ], ['detail_only' => true, 'capture_complete' => true]))->assertOk();

        $res->assertJsonPath('detail_unknown', 1)->assertJsonPath('imported', 0)->assertJsonPath('reconcile', null);
        $this->assertSame(1, $search->fresh()->listing_count);
        $this->assertFalse(ProspectingListing::where('portal_ref', 'P24-999999999')->exists());
    }

    public function test_a_search_tile_never_sets_a_listed_date_only_a_listing_page_read_does(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile(['listed_date' => '2026-09-01'])]))->assertOk();
        $this->assertNull($this->listing()->listed_date);
    }

    public function test_an_agent_corrected_listed_date_is_never_overwritten(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk();
        $l = $this->listing();
        $l->forceFill(['listed_date' => '2026-08-15', 'listed_date_source' => 'agent'])->save();

        $this->postJson(self::URL, $this->body([$this->tile(['listed_date' => '2026-09-01', 'page_read' => true])], ['detail_only' => true]))->assertOk();
        $this->assertSame('2026-08-15', $this->listing()->listed_date->toDateString());
    }

    public function test_a_changed_price_asks_for_a_listing_page_read_an_unchanged_one_does_not(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk();
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk()->assertJsonPath('needs_detail', []);
        $this->postJson(self::URL, $this->body([$this->tile(['price' => 1450000])]))->assertOk()->assertJsonPath('needs_detail.0', 'P24-116922912');
    }

    public function test_each_crawl_session_gets_its_own_search_row_and_complete_never_goes_back(): void
    {
        Sanctum::actingAs($this->user);
        $s1 = '11111111-1111-4111-8111-111111111111';
        $s2 = '22222222-2222-4222-8222-222222222222';
        $crawl = fn (string $id, int $page, bool $complete) => ['crawl' => ['session_id' => $id, 'portal' => 'p24', 'suburb' => 'Margate',
            'page' => $page, 'pages_total' => 3, 'complete' => $complete, 'started_at' => '2026-10-31T08:00:00Z']];

        $this->postJson(self::URL, $this->body([$this->tile()], $crawl($s1, 1, false)))->assertOk();
        $this->postJson(self::URL, $this->body([$this->tile(['portal_ref' => 'P24-2'])], $crawl($s1, 3, true) + ['capture_complete' => false]))->assertOk();
        $this->postJson(self::URL, $this->body([$this->tile(['portal_ref' => 'P24-3'])], $crawl($s1, 2, false)))->assertOk();
        $this->postJson(self::URL, $this->body([$this->tile()], $crawl($s2, 1, false)))->assertOk();

        $rows = ProspectingSearch::where('agency_id', $this->agency->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($s1, $rows[0]->crawl_session_id);
        $this->assertSame(3, $rows[0]->crawl_page);
        $this->assertSame(3, $rows[0]->crawl_pages_total);
        $this->assertTrue($rows[0]->crawl_complete);
        $this->assertSame('Margate', $rows[0]->crawl_suburb);
        $this->assertFalse($rows[1]->crawl_complete);
        $this->assertSame($rows[0]->id, $this->listing('P24-2')->last_search_id);
    }

    public function test_another_agencys_capture_never_touches_this_agencys_adverts(): void
    {
        $now = now();
        DB::table('prospecting_listings')->insert([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->user->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-116922912',
            'suburb' => 'Margate', 'first_seen_at' => $now, 'last_seen_at' => $now, 'advertiser_type' => 'owner', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $other = Agency::create(['name' => 'Cape Agency', 'slug' => 'cape-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $u2 = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id]);
        Sanctum::actingAs($u2);
        $this->postJson(self::URL, $this->body([$this->tile(['advertiser_type' => 'agency'])]))->assertOk()->assertJsonPath('imported', 1);

        $this->assertSame('owner', $this->listing()->advertiser_type);
        $this->assertSame('agency', ProspectingListing::withoutGlobalScopes()->where('agency_id', $other->id)->firstOrFail()->advertiser_type);
    }

    public function test_public_advert_facts_reach_the_shared_pool_without_an_extra_sighting(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile(['advertiser_type' => 'owner', 'badges' => ['auction']])]))->assertOk();

        $fact = DB::table('portal_advert_facts')->where('canonical_ref', 'p24:116922912')->first();
        $this->assertNotNull($fact);
        $this->assertSame('owner', $fact->advertiser_type);
        $this->assertSame(['auction'], json_decode($fact->badges, true));
        $this->assertSame(1, (int) $fact->seen_count, 'the resolver counted the sighting; the MIC facts add none');
    }
}
