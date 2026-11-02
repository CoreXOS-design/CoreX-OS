<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\AgencyWorkService;
use App\Services\Prospecting\MicAdvertFacts;
use App\Services\Prospecting\MicWork\MicAdvertFilters;
use App\Services\Prospecting\MicWork\MicWorkList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * mic-work-tab.md 13.16 / 13.16b: "Get full details" on the Adverts card. The read goes through the existing detail-only ingest; this proves what that now adds
 * (who read it, the file's history line, the permission), the "changed since" rule, the three label states, the photo count, the filter and the stats count.
 */
final class MicFullDetailsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/prospecting/import';

    private Agency $a;
    private Agency $b;
    private User $u;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent', 'name' => 'Gerda Botha']);
    }

    private function file(?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now(),
            'street_number' => '1', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo']);
    }

    private function advert(TrackedProperty $tp, array $attrs = []): ProspectingListing
    {
        $this->n++;
        $l = new ProspectingListing();
        $l->forceFill(array_merge(['agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->u->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . (116900000 + $this->n),
            'portal_url' => 'https://www.property24.com/for-sale/uvongo/' . (116900000 + $this->n), 'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'House', 'bedrooms' => 3,
            'card_heading' => '3 Bedroom House', 'description_snippet' => 'Sea views and a pool.', 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(), 'is_active' => true,
            'portal_status' => 'active', 'listing_type' => 'sale'], $attrs))->save();

        return $l->fresh();
    }

    private function read(ProspectingListing $l, array $over = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->u);

        return $this->postJson(self::URL, [
            'source' => 'p24',
            'search_context' => ['url' => $l->portal_url, 'search_term' => 'advert', 'total_results' => 1, 'pages_captured' => 1, 'detail_only' => true],
            'listings' => [array_merge(['portal_ref' => $l->portal_ref, 'portal_url' => $l->portal_url, 'page_read' => true, 'page_price' => 1500000, 'page_heading' => '3 Bedroom House',
                'description' => "Lovely home.\nFive minutes to the beach.", 'photo_urls' => ['https://images.prop24.com/1/Ensure960x540', 'https://images.prop24.com/2/Ensure960x540'], 'photo_count' => 2,
                'features' => ['Pets Allowed' => 'Yes'], 'parking' => 2, 'listed_date' => now()->subDays(20)->toDateString(), 'agent_name' => 'Jane Smith', 'rates_amount' => 1200, 'levies_amount' => 900], $over)],
        ]);
    }

    public function test_a_read_stamps_who_when_and_the_wording_and_writes_one_history_line(): void
    {
        $tp = $this->file();
        $l = $this->advert($tp);
        $this->read($l)->assertOk()->assertJsonPath('updated', 1);

        $l = $l->fresh();
        $this->assertNotNull($l->details_read_at);
        $this->assertSame($this->u->id, (int) $l->details_read_by);
        $this->assertSame(MicAdvertFacts::cardHash('3 Bedroom House', 'Sea views and a pool.'), $l->details_read_card_hash);
        $this->assertSame(2, count($l->photo_urls));
        $this->assertSame(1500000, (int) $l->details_read_price);
        $this->assertSame("Lovely home.\nFive minutes to the beach.", $l->description);
        $this->assertSame('Jane Smith', $l->agent_name);

        $h = app(AgencyWorkService::class)->history($this->a->id, $tp->id);
        $this->assertCount(1, $h);
        $this->assertSame('details_read', $h[0]->kind);
        $this->assertSame('P24', $h[0]->to_value);
        $this->assertSame($this->u->id, (int) $h[0]->user_id);
    }

    public function test_a_page_that_did_not_parse_writes_nothing_and_stamps_nothing(): void
    {
        $tp = $this->file();
        $l = $this->advert($tp);
        $this->read($l, ['page_price' => null, 'page_heading' => null, 'description' => null, 'photo_urls' => []])->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('detail_unparsed', 1);
        $this->assertNull($l->fresh()->details_read_at);
        $this->assertCount(0, app(AgencyWorkService::class)->history($this->a->id, $tp->id));
    }

    public function test_another_agencys_advert_is_not_found_and_nothing_is_written(): void
    {
        $theirs = $this->advert($this->file($this->b));
        $this->read($theirs)->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('detail_unknown', 1);
        $this->assertNull($theirs->fresh()->details_read_at);
    }

    public function test_a_user_without_the_prospecting_right_cannot_read_an_advert(): void
    {
        $l = $this->advert($this->file());
        // the agency has a permission set, and it does not include access_prospecting (an empty set permits everything in tests)
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'access_my_portal', 'agency_id' => $this->a->id]);
        $this->read($l)->assertForbidden();
        $this->assertNull($l->fresh()->details_read_at);
    }

    public function test_changed_since_the_read_price_or_wording_but_never_an_unread_advert(): void
    {
        $tp = $this->file();
        $l = $this->advert($tp);
        $this->assertFalse(MicAdvertFacts::changedSinceRead($l), 'never read = never changed');
        $this->read($l)->assertOk();
        $l = $l->fresh();
        $this->assertFalse(MicAdvertFacts::changedSinceRead($l), 'just read');

        $l->price = 1_400_000;
        $this->assertTrue(MicAdvertFacts::changedSinceRead($l), 'price moved');
        $l->price = 1_500_000;
        $l->description_snippet = 'Sea views, a pool and a new roof.';
        $this->assertTrue(MicAdvertFacts::changedSinceRead($l), 'card wording changed');
        $l->description_snippet = '  sea VIEWS and a   pool. ';
        $this->assertFalse(MicAdvertFacts::changedSinceRead($l), 'spacing and case are not a change');

        $old = $this->advert($tp, ['details_read_at' => now()->subDays(3), 'details_read_price' => 1_500_000, 'details_read_card_hash' => null, 'description_snippet' => 'whatever']);
        $this->assertFalse(MicAdvertFacts::changedSinceRead($old), 'a read from before the fingerprint is judged on price alone');
        $this->assertNull(MicAdvertFacts::cardHash(null, '  '), 'a card with no wording has nothing to compare against');
    }

    public function test_the_record_carries_level_date_reader_and_stale_flag(): void
    {
        $tp = $this->file();
        $l = $this->advert($tp);
        $rec = fn () => app(MicWorkList::class)->forFiles([$tp->id], $this->a->id)[0]['details'];

        $d = $rec();
        $this->assertSame('search', $d['level']);
        $this->assertNull($d['read_on']);
        $this->assertFalse($d['stale']);
        $this->assertSame($l->id, $d['target']['id']);
        $this->assertSame('p24', $d['target']['portal']);

        $this->read($l)->assertOk();
        $d = $rec();
        $this->assertSame('full', $d['level']);
        $this->assertSame(now()->format('j M'), $d['read_on']);
        $this->assertSame('Gerda', $d['read_by']);
        $this->assertFalse($d['stale']);
        $this->assertSame(2, $d['photo_count']);

        DB::table('prospecting_listings')->where('id', $l->id)->update(['price' => 1_390_000]);
        $this->assertTrue($rec()['stale']);
    }

    public function test_the_three_label_states_and_the_photo_count_render(): void
    {
        $tp = $this->file();
        $l = $this->advert($tp, ['thumbnail_source_url' => 'https://images.prop24.com/9/Ensure960x540']);
        $listing = $l;
        $render = function (array $adv) use ($listing) {
            return view('corex.market-intelligence._advert-fetch', ['adv' => $adv, 'listing' => $listing])->render()
                . view('corex.market-intelligence._advert-gallery', ['adv' => $adv, 'thumbSrc' => $adv['thumbnail_source_url'] ?? null])->render();
        };
        $target = ['id' => $l->id, 'portal' => 'p24', 'ref' => $l->portal_ref, 'url' => $l->portal_url];

        $search = $render(['tp_id' => $tp->id, 'thumbnail_source_url' => 'https://x/y.jpg', 'details' => ['level' => 'search', 'target' => $target, 'stale' => false]]);
        $this->assertStringContainsString('Search view', $search);
        $this->assertStringContainsString('Get full details', $search);
        $this->assertStringContainsString('>1 photo<', $search);
        $this->assertStringNotContainsString('data-gallery-prev', $search);

        $urls = array_map(fn ($i) => "https://images.prop24.com/$i/Ensure960x540", range(1, 14));
        $full = $render(['tp_id' => $tp->id, 'photo_urls' => $urls, 'details' => ['level' => 'full', 'read_on' => '2 Nov', 'read_by' => 'Gerda', 'stale' => false, 'target' => $target]]);
        $this->assertStringContainsString('Full details · 2 Nov · Gerda', $full);
        $this->assertStringContainsString('data-advert-open', $full);
        $this->assertStringNotContainsString('data-qa="get-full-details"', $full);
        $this->assertStringContainsString('14 photos', $full);
        $this->assertStringContainsString('data-gallery-prev', $full);
        $this->assertStringContainsString('data-gallery-next', $full);

        $stale = $render(['tp_id' => $tp->id, 'photo_urls' => $urls, 'details' => ['level' => 'full', 'read_on' => '2 Nov', 'read_by' => 'Gerda', 'stale' => true, 'target' => $target]]);
        $this->assertStringContainsString('Details from 2 Nov · changed since', $stale);
        $this->assertStringContainsString('Refresh details', $stale);
        $this->assertStringContainsString('data-advert-fetch', $stale);

        $none = $render(['tp_id' => $tp->id, 'details' => ['level' => 'search', 'target' => null, 'stale' => false]]);
        $this->assertStringContainsString('Search view', $none);
        $this->assertStringNotContainsString('data-qa="get-full-details"', $none);
        $this->assertStringNotContainsString('data-qa="photo-count"', $none, 'no photo, no count');
    }

    private function emtunzi(): array
    {
        // the keys the real 4 Emtunzi Drive page gave (QA1 advert 6281, 2 Nov)
        return ['Other' => 'Dining room', 'Garage' => 'Roll up, Single', 'Garden' => 'Communal, Courtyard, Garden Services, Immaculate Condition', 'Levies' => 'R 1 975',
            'Lounge' => 'Curtain Rails, Fan, Internet Port, Open Plan, Laminated Floors, Veranda', 'Kitchen' => 'Breakfast Nook, Open Plan, Extractor Fan, Granite Tops', 'Parking' => 'Visitors Parking, Carport, Secure Parking, Single',
            'Bedrooms' => '2', 'Security' => 'Totally Fenced, Electric Gate, Security Gate, Safe', 'Bathrooms' => '2', 'Bedroom 1' => 'Air Conditioner, Curtain Rails, Fan, Double Bedroom', 'Bedroom 2' => 'Air Conditioner, Queen Bedroom',
            'Bathroom 1' => 'Tiled Floors, Shower, Bath, Toilet and Basin', 'Bathroom 2' => 'Tiled Floors, Main en Suite, Shower', 'Floor Size' => '187 m²', 'Backup Water' => 'Water Tank', 'Listing Date' => '02 May 2026',
            'Pets Allowed' => 'Yes', 'Listing Number' => '117177245', 'Street Address' => '4 Emtunzi Drive, Uvongo', 'Internet Access' => 'Fibre', 'Rates and Taxes' => 'R 925', 'Special Features' => 'Open Plan, Totally Walled',
            'Type of Property' => 'Townhouse', 'Temperature Control' => 'Air Conditioning Unit, Cooling Fans', 'Wheelchair Accessible' => 'Yes', 'Mystery Key' => 'Something new'];
    }

    public function test_features_are_grouped_the_p24_way_every_key_once_and_the_room_lists_are_kept_apart(): void
    {
        $g = \App\Services\Prospecting\MicWork\FullAdFeatures::group($this->emtunzi(), ['floor_size_m2' => 187, 'erf_size_m2' => 0, 'rates_amount' => 925, 'levies_amount' => 1975, 'listed_date' => '2026-05-02']);
        $labels = fn (string $grp) => array_column($g['groups'][$grp], 0);
        $this->assertSame(['Bedrooms', 'Bathrooms', 'Other rooms'], $labels('Rooms'));
        $this->assertSame(['Garage', 'Parking', 'Garden', 'Security'], $labels('Outside'));
        $this->assertSame(['Type', 'Floor size', 'Rates', 'Levies', 'Listing date', 'Listing number'], $labels('Building'), 'Building in the P24 order; the record fills nothing twice');
        $this->assertSame(['Pets allowed', 'Internet', 'Temperature control', 'Backup water', 'Wheelchair accessible', 'Special features', 'Mystery Key'], $labels('Extras'), 'unknown keys go to Extras');
        $this->assertSame(['Lounge', 'Kitchen', 'Bedroom 1', 'Bedroom 2', 'Bathroom 1', 'Bathroom 2'], array_column($g['room_details'], 0));
        $all = array_merge(...array_map(fn ($rows) => array_column($rows, 0), array_values($g['groups'])));
        $this->assertSame($all, array_values(array_unique($all)), 'nothing is listed twice');
        $this->assertNotContains('Street address', $all, 'the street address is the header');
        $rows = array_column($g['groups']['Building'], 1, 0);
        $this->assertSame('187 m²', $rows['Floor size']);
        $this->assertSame('Townhouse', $rows['Type']);
        // the record fills a Building row the page rows did not give
        $g2 = \App\Services\Prospecting\MicWork\FullAdFeatures::group(['Pets Allowed' => 'No', 'Pool' => 'Yes'], ['erf_size_m2' => 1200, 'rates_amount' => 800]);
        $this->assertSame([['Erf size', '1,200 m²'], ['Rates', 'R 800']], $g2['groups']['Building']);
        $this->assertSame([['Pool', 'Yes']], $g2['groups']['Outside']);
        $this->assertArrayNotHasKey('Extras', $g2['groups'], 'a "No" row is not shown');
    }

    public function test_the_full_ad_is_the_ad_left_and_ours_right_with_no_helper_text(): void
    {
        $tp = $this->file();
        $l = $this->advert($tp);
        $this->read($l, ['features' => $this->emtunzi(), 'description' => "First paragraph.\n\nSecond paragraph.", 'photo_urls' => array_map(fn ($i) => "https://i.test/$i", range(1, 17)), 'photo_count' => 17])->assertOk();
        $r = app(MicWorkList::class)->forFiles([$tp->id], $this->a->id)[0];
        $left = view('corex.market-intelligence.partials._full-ad', ['r' => $r])->render();
        $right = view('corex.market-intelligence.partials._full-ad-ours', ['r' => $r])->render();

        $this->assertStringContainsString('1 of 17', $left);
        $this->assertStringContainsString('data-fullad-strip', $left);
        $this->assertStringContainsString('data-fullad-strip-next', $left, 'thumbnail arrows at the ends');
        $this->assertSame(2, substr_count($left, '<p style="margin: 0 0 .85em;">'), 'the description is paragraphs');
        foreach (['Rooms', 'Outside', 'Building', 'Extras'] as $grp) {
            $this->assertStringContainsString('data-group="' . $grp . '"', $left, $grp);
        }
        $this->assertStringContainsString('Room details', $left);
        $this->assertStringContainsString('Listed by', $left);
        $this->assertStringContainsString('Open on P24', $left);
        $this->assertStringContainsString('Jane Smith', $left);
        $this->assertStringNotContainsString('Phone numbers', $left, 'no helper text');
        $this->assertLessThanOrEqual(5, substr_count($left, 'data-qa="fact-tile"'));
        foreach (['Price history', 'Portal alert', 'Matched buyers', 'Deeds', 'Adverts on this property', 'Our history'] as $ours) {
            $this->assertStringNotContainsString($ours, $left, "nothing of ours on the left: $ours");
            $this->assertStringContainsString($ours, $right, "ours on the right: $ours");
        }
        $this->assertStringContainsString('Open the property page', $right);
        $this->assertStringNotContainsString('max-height: min(22rem', $right, 'no inner scroll boxes in the window');
    }

    public function test_the_full_details_only_filter_keeps_only_read_adverts_and_is_url_stateful(): void
    {
        $tp = $this->file();
        $read = $this->advert($tp);
        $unread = $this->advert($this->file());
        $this->read($read)->assertOk();

        $ids = fn (array $in) => app(MicAdvertFilters::class)->apply(ProspectingListing::withoutGlobalScopes()->where('agency_id', $this->a->id), $in, $this->a->id, $this->u)->pluck('id')->sort()->values()->all();
        $this->assertSame([$read->id, $unread->id], $ids([]));
        $this->assertSame([$read->id], $ids(['full_details_only' => '1']));
        $this->assertSame([$read->id, $unread->id], $ids(['full_details_only' => '0']));
        $this->assertContains('full_details_only', MicAdvertFilters::KEYS, 'remembered per user and kept in the URL like the other filters');
        $this->assertTrue(MicAdvertFilters::present(['full_details_only' => '1']));
    }
}
