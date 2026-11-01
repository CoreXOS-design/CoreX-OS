<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Jobs\DownloadListingThumbnail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\MicAdvertFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MIC Adverts view, capture (.ai/specs/mic-work-tab.md 13.6 / 11.2.4): the results card's heading and wording, and the listing
 * page's full wording, photos, feature rows and parking, travel through POST /api/v1/prospecting/import into prospecting_listings.
 */
final class MicAdvertTextIngestTest extends TestCase
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
                'url' => 'https://www.property24.com/for-sale/alias/hibiscus-coast/102/kwazulu-natal/2/p7',
                'search_term' => 'Hibiscus Coast for sale',
                'total_results' => count($listings),
                'pages_captured' => 1,
            ],
            'listings' => $listings,
        ];
    }

    private function tile(array $over = []): array
    {
        return $over + ['portal_ref' => 'P24-117752073', 'suburb' => 'Palm Beach', 'price' => 1495000, 'bedrooms' => 3,
            'portal_url' => 'https://www.property24.com/for-sale/palm-beach/port-edward/kwazulu-natal/6365/117752073', 'agency_name' => 'Pam Golding'];
    }

    private function listing(string $ref = 'P24-117752073'): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('portal_ref', $ref)->firstOrFail();
    }

    public function test_the_card_heading_and_sole_mandate_wording_land_in_corex(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([
            $this->tile(['card_heading' => '3 Bedroom House', 'description_snippet' => "Sole mandate - top quality game lodge quality home at an excellent price, in a gated estate near the beach and shops. Spacious open plan living with views over the dunes towards the sea and a double garage for two cars"]),
            $this->tile(['portal_ref' => 'P24-117061095', 'price' => 1395000, 'card_heading' => '3 Bedroom Apartment', 'description_snippet' => 'Sole Mandate...!!!.. Coastal Retreat with Distant Sea Views in Ramsgate']),
        ]))->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('dropped_fields', 0);

        $l = $this->listing();
        $this->assertSame('3 Bedroom House', $l->card_heading);
        $this->assertStringStartsWith('Sole mandate - top quality', $l->description_snippet);
        $this->assertLessThanOrEqual(200, mb_strlen($l->description_snippet));
        $this->assertNull($l->description, 'the full wording only comes from a listing-page read');
        $this->assertStringStartsWith('Sole Mandate...', $this->listing('P24-117061095')->description_snippet);
    }

    public function test_a_later_tile_without_text_never_blanks_what_is_stored_and_a_new_snippet_replaces_it(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile(['card_heading' => '3 Bedroom House', 'description_snippet' => 'Sole mandate - first wording'])]))->assertOk();
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk();
        $this->assertSame('Sole mandate - first wording', $this->listing()->description_snippet);
        $this->postJson(self::URL, $this->body([$this->tile(['description_snippet' => '   '])]))->assertOk();
        $this->assertSame('Sole mandate - first wording', $this->listing()->description_snippet, 'blank text is not a new wording');
        $this->postJson(self::URL, $this->body([$this->tile(['description_snippet' => 'Reduced! Now open mandate'])]))->assertOk();
        $this->assertSame('Reduced! Now open mandate', $this->listing()->description_snippet);
    }

    public function test_a_listing_page_read_stores_the_full_wording_photos_feature_rows_and_the_names(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile()]))->assertOk();

        $photos = [];
        foreach (range(1, 30) as $i) {
            $photos[] = 'https://images.prop24.com/3857' . (10000 + $i) . '/Ensure960x540';
        }
        $this->postJson(self::URL, $this->body([$this->tile([
            'page_read' => true, 'description' => "<b>Sole mandate</b>\n\n\nA lovely home   in a gated estate.\r\nCall me.", 'photo_urls' => array_merge($photos, [$photos[0], 'http://insecure/x.jpg', 'javascript:alert(1)']),
            'photo_count' => 30, 'features' => ['Pets Allowed' => 'Yes', 'Furnished' => 'No', 'Pool' => 'Yes', 'Garden' => 'Yes', 'Security' => '24 Hour Response', 'Street Address' => '75 David Livingstone street, Palm Beach', '' => 'x', 'Empty' => ''],
            'parking' => 2, 'listed_date' => '2026-10-24', 'agent_name' => 'Nadia Beattie', 'rates_amount' => 1490, 'levies_amount' => 2495,
        ])], ['detail_only' => true]))->assertOk();

        $l = $this->listing();
        $this->assertSame("Sole mandate\n\nA lovely home in a gated estate.\nCall me.", $l->description);
        $this->assertCount(30, $l->photo_urls, 'duplicates and non-https addresses are dropped');
        $this->assertSame(30, $l->photo_count);
        $this->assertSame('Yes', $l->features['Pets Allowed']);
        $this->assertSame('24 Hour Response', $l->features['Security']);
        $this->assertArrayNotHasKey('Empty', $l->features);
        $this->assertSame(5 + 1, count($l->features));
        $this->assertSame(2, (int) $l->parking);
        $this->assertSame('2026-10-24', $l->listed_date->toDateString());
        $this->assertSame('Nadia Beattie', $l->agent_name);
        $this->assertSame(1490, (int) $l->rates_amount);
        $this->assertSame(2495, (int) $l->levies_amount);
        $this->assertNotNull($l->details_read_at);
    }

    public function test_the_full_wording_photos_and_features_are_ignored_on_a_plain_tile(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile(['description' => 'x', 'photo_urls' => ['https://images.prop24.com/1/a'], 'features' => ['a' => 'b'], 'parking' => 3])]))->assertOk();
        $l = $this->listing();
        $this->assertNull($l->description);
        $this->assertNull($l->photo_urls);
        $this->assertNull($l->features);
        $this->assertNull($l->parking);
    }

    public function test_none_of_the_advert_text_or_photos_reach_the_shared_pool(): void
    {
        Queue::fake();
        $facts = new ProspectingListing(['portal_ref' => 'P24-1']);
        $facts->description = 'secret wording';
        $facts->description_snippet = 'snippet';
        $facts->photo_urls = ['https://images.prop24.com/1/a'];
        $facts->features = ['Pool' => 'Yes'];
        $pool = MicAdvertFacts::poolFacts($facts);
        foreach (['description', 'description_snippet', 'photo_urls', 'features', 'card_heading'] as $k) {
            $this->assertArrayNotHasKey($k, $pool);
        }
    }

    public function test_a_resighted_advert_whose_photo_file_is_gone_is_queued_again_and_a_present_file_is_left_alone(): void
    {
        Bus::fake();
        Storage::fake('local');
        Sanctum::actingAs($this->user);
        $this->postJson(self::URL, $this->body([$this->tile(['thumbnail_url' => 'https://images.prop24.com/376222835/Crop600x400'])]))->assertOk();
        Bus::assertDispatchedTimes(DownloadListingThumbnail::class, 1);

        // the first download "finished": a path is recorded but no file exists (the disk-root move)
        DB::table('prospecting_listings')->where('portal_ref', 'P24-117752073')->update(['thumbnail_path' => 'prospecting/thumbnails/p24_P24-117752073.jpg']);
        $this->postJson(self::URL, $this->body([$this->tile(['thumbnail_url' => 'https://images.prop24.com/376222835/Crop600x400'])]))->assertOk();
        Bus::assertDispatchedTimes(DownloadListingThumbnail::class, 2);

        Storage::disk('local')->put('prospecting/thumbnails/p24_P24-117752073.jpg', 'jpeg');
        $this->postJson(self::URL, $this->body([$this->tile(['thumbnail_url' => 'https://images.prop24.com/376222835/Crop600x400'])]))->assertOk();
        Bus::assertDispatchedTimes(DownloadListingThumbnail::class, 2);
    }

    public function test_clean_text_strips_tags_collapses_space_and_cuts_to_length(): void
    {
        $this->assertSame('Sole mandate &amp; more', MicAdvertFacts::cleanText('<p>Sole   mandate &amp;amp; more</p>', 200));
        $this->assertSame(str_repeat('a', 200), MicAdvertFacts::cleanText(str_repeat('a', 500), 200));
        $this->assertNull(MicAdvertFacts::cleanText("  \n ", 200));
        $this->assertNull(MicAdvertFacts::cleanText(['x'], 200));
    }
}
