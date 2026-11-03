<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md 13.16b on the real Work page: the level label on every card, the "Full details only" tick and chip, the "N with full details" count, the List badge, the full-ad block. */
final class MicFullDetailsPageTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true, 'name' => 'Gerda Botha']);
    }

    private function advert(string $ref, int $no, array $extra = []): ProspectingListing
    {
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => (string) $no, 'street_name' => 'Read Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => $ref]);

        $l = new ProspectingListing();
        $l->forceFill(array_merge([
            'agency_id' => $this->agency->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://www.property24.com/x/' . $ref, 'address' => $no . ' Read Road',
            'suburb' => 'Margate', 'property_type' => 'House', 'price' => 1_000_000, 'bedrooms' => 3, 'captured_by_user_id' => $this->admin->id, 'first_seen_at' => now()->subDays(10), 'last_seen_at' => now(),
            'is_active' => true, 'portal_status' => 'active', 'card_heading' => '3 Bedroom House', 'description_snippet' => 'Near the beach.',
        ], $extra))->save();

        return $l;
    }

    private function page(array $q = []): string
    {
        return $this->actingAs($this->admin)->get(route('market-intelligence.work', $q + ['view' => 'adverts']))->assertOk()->getContent();
    }

    public function test_every_card_has_a_level_label_the_count_and_the_filter(): void
    {
        $this->advert('P24-160000001', 1);
        $read = $this->advert('P24-160000002', 2, ['details_read_at' => now()->subDay(), 'details_read_price' => 1_000_000, 'details_read_by' => $this->admin->id,
            'details_read_card_hash' => \App\Services\Prospecting\MicAdvertFacts::cardHash('3 Bedroom House', 'Near the beach.'), 'description' => str_repeat('A long full description. ', 20),
            'photo_urls' => ['https://i.test/1', 'https://i.test/2', 'https://i.test/3'], 'photo_count' => 3]);
        $this->advert('P24-160000003', 3, ['details_read_at' => now()->subDays(3), 'details_read_price' => 900_000, 'details_read_by' => $this->admin->id, 'photo_urls' => ['https://i.test/9']]);

        $h = $this->page();
        $this->assertSame(3, substr_count($h, 'data-qa="advert-level"'), 'a label on every card');
        $this->assertStringContainsString('Search view', $h);
        $this->assertStringContainsString('Full details · ' . now()->subDay()->format('j M') . ' · Gerda', $h);
        $this->assertStringContainsString('Details from ' . now()->subDays(3)->format('j M') . ' · changed since', $h, 'the price moved after the read');
        $this->assertStringContainsString('Refresh details', $h);
        $this->assertStringContainsString('3 photos', $h);
        $this->assertStringContainsString('Read more', $h, 'full details: the wording opens the full ad');
        $this->assertStringContainsString('data-qa="full-details-count"', $h);
        $this->assertMatchesRegularExpression('/<span[^>]*>2<\/span> <span[^>]*>with full details/', $h, 'N with full details');
        $this->assertStringContainsString('data-qa="more-full_details_only"', $h);

        $f = $this->page(['full_details_only' => 1]);
        $this->assertSame(2, substr_count($f, 'data-qa="advert-level"'), 'only the read adverts');
        $this->assertStringNotContainsString('P24-160000001', $f);
        $this->assertStringContainsString('Full details only', $f, 'a chip says so');
        $this->assertMatchesRegularExpression('/<span[^>]*>2<\/span> <span[^>]*>with full details/', $f);

        $list = $this->page(['view' => 'list']);
        $this->assertSame(2, substr_count($list, '✓ Full'), 'the List shows a Full tick on the read adverts');
        $this->assertNotNull($read);
    }

    public function test_the_card_endpoint_answers_the_full_ad_as_title_left_and_right(): void
    {
        $l = $this->advert('P24-160000004', 4, ['details_read_at' => now(), 'details_read_price' => 1_000_000, 'description' => 'Full wording of the ad.', 'photo_urls' => ['https://i.test/1', 'https://i.test/2']]);
        $url = route('market-intelligence.work', ['view' => 'adverts', '_card' => $l->tracked_property_id]);
        $plain = $this->actingAs($this->admin)->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringNotContainsString('data-qa="full-ad"', $plain);
        $j = $this->actingAs($this->admin)->get($url . '&_ad=1', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json();
        $this->assertSame('4 Read Road, Margate', $j['title'], 'the window is titled with the address');
        $this->assertStringContainsString('Full wording of the ad.', $j['left']);
        $this->assertStringContainsString('Price history', $j['right']);
        $this->assertStringNotContainsString('Price history', $j['left']);
    }

    public function test_the_card_keeps_its_line_budget_at_full_details_and_the_count_matches(): void
    {
        $this->advert('P24-160000007', 7, ['details_read_at' => now(), 'details_read_price' => 1_000_000, 'description' => str_repeat('A long description for the card. ', 12), 'photo_urls' => ['https://i.test/1']]);
        $h = $this->page();
        $this->assertStringContainsString('class="mi-clamp" style="--mi-lines: 3;"', $h, 'three lines + Read more at full details');
        $this->assertStringContainsString('data-qa="advert-dates"', $h, 'the First seen line stays');
        $this->assertMatchesRegularExpression('/<span[^>]*>1<\/span> <span[^>]*>with full details/', $h);
    }

    public function test_an_advert_with_no_property_file_opens_its_full_ad_with_a_negative_key(): void
    {
        $l = new ProspectingListing();
        $l->forceFill(['agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-160000009', 'portal_url' => 'https://www.property24.com/x/160000009', 'suburb' => 'Margate', 'price' => 900_000,
            'captured_by_user_id' => $this->admin->id, 'first_seen_at' => now()->subDays(2), 'last_seen_at' => now(), 'is_active' => true, 'portal_status' => 'active', 'card_heading' => '2 Bedroom Flat',
            'details_read_at' => now(), 'details_read_price' => 900_000, 'description' => 'A lone advert, full wording.'])->save();
        $this->assertNull($l->fresh()->tracked_property_id);
        $h = $this->page();
        $this->assertStringContainsString('data-file="-' . $l->id . '"', $h);
        $ad = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'adverts', '_card' => -$l->id, '_ad' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->json();
        $this->assertStringContainsString('A lone advert, full wording.', $ad['left']);
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'adverts', '_card' => -999999, '_ad' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
    }
}
