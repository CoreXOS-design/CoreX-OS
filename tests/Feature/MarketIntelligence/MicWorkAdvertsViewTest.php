<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\MicAgencySetting;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md 13.3: the Adverts view - one P24-style card per property file, our intelligence beside it, the List unchanged. */
final class MicWorkAdvertsViewTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function advert(string $ref, int $price, array $extra = [], array $raw = []): ProspectingListing
    {
        $tp = app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => '7', 'street_name' => 'Card Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => $ref]);
        $l = ProspectingListing::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://p.test/' . $ref, 'address' => '7 Card Road', 'suburb' => 'Margate',
            'property_type' => 'House', 'price' => $price, 'bedrooms' => 3, 'bathrooms' => 2, 'garages' => 1, 'agency_name' => 'Sea Realty', 'captured_by_user_id' => $this->admin->id,
            'first_seen_at' => now()->subDays(80), 'last_seen_at' => now(), 'is_active' => true, 'tracked_property_id' => $tp->id,
        ], $extra));
        if ($raw) {
            DB::table('prospecting_listings')->where('id', $l->id)->update($raw);
        }

        return $l;
    }

    private function page(array $q = []): string
    {
        return $this->actingAs($this->admin)->get(route('market-intelligence.work', $q))->assertOk()->getContent();
    }

    public function test_the_adverts_view_shows_a_p24_style_card_with_the_real_snippet_ribbons_and_dates(): void
    {
        $l = $this->advert('P24-150100001', 1_400_000, [], [
            'card_heading' => '3 Bedroom House in Margate', 'description_snippet' => 'Sole mandate - sunny family home two minutes from the beach.',
            'listed_date' => now()->subDays(75)->toDateString(), 'is_reduced' => 1, 'portal_status' => 'under_offer', 'advertiser_type' => 'owner', 'parking' => 2,
        ]);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $l->id, 'old_price' => 1_500_000, 'new_price' => 1_400_000, 'changed_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()]);
        $h = $this->page(['view' => 'adverts', 'show_status' => 'all']);

        foreach (['advert-card', 'advert-left', 'advert-right', 'advert-price', 'advert-heading', 'advert-description', 'advert-icons', 'advert-dates', 'advert-buttons', 'advert-lines'] as $qa) {
            $this->assertStringContainsString('data-qa="' . $qa . '"', $h, $qa);
        }
        $this->assertStringContainsString('3 Bedroom House in Margate', $h);
        $this->assertStringContainsString('sunny family home two minutes from the beach', $h, 'the real snippet text is on the card');
        $this->assertStringContainsString('R 1,400,000', $h);
        $this->assertStringContainsString('line-through', $h, 'old price struck');
        $this->assertStringContainsString('cut R 100,000', $h);
        foreach (['Reduced', 'Under offer', 'Owner selling', 'Sole mandate'] as $rb) {
            $this->assertStringContainsString('>' . $rb . '</span>', $h, "ribbon {$rb}");
        }
        $this->assertStringContainsString('Listed ', $h);
        $this->assertStringContainsString('First seen ', $h);
        $this->assertStringContainsString('Property intel', $h);
        $this->assertStringContainsString('Full card', $h);
        $this->assertStringContainsString('https://p.test/P24-150100001', $h, 'the photo opens the advert on the portal');
        $this->assertStringNotContainsString('data-qa="row-wrap"><article', $h);
    }

    public function test_the_list_view_is_still_the_classic_list(): void
    {
        $this->advert('P24-150100002', 900_000);
        $h = $this->page(['view' => 'list']);
        $this->assertStringContainsString('class="mi-row"', $h);
        $this->assertStringNotContainsString('data-qa="advert-card"', $h);
    }

    public function test_the_adverts_view_pages_by_the_rows_per_page_setting(): void
    {
        MicAgencySetting::saveValues($this->agency->id, ['rows_per_page' => 10]);
        foreach (range(1, 12) as $i) {
            $this->advert('P24-1502000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 1_000_000 + $i);
        }
        $this->assertSame(10, substr_count($this->page(['view' => 'adverts']), 'data-qa="advert-card"'));
        $this->assertSame(2, substr_count($this->page(['view' => 'adverts', 'page' => 2]), 'data-qa="advert-card"'));
    }

    public function test_a_different_advert_on_the_same_property_at_another_price_is_not_a_cut(): void
    {
        $this->advert('P24-150100003', 1_200_000);
        $tp = (int) DB::table('prospecting_listings')->where('portal_ref', 'P24-150100003')->value('tracked_property_id');
        $other = $this->advert('PP-150100004', 1_000_000, ['portal_source' => 'pp', 'agency_name' => 'Other Agents', 'tracked_property_id' => $tp]);
        $h = $this->page(['view' => 'adverts']);
        $this->assertStringNotContainsString('line-through', $h);
        $this->assertStringContainsString('data-qa="line-other-adverts"', $h);
        $this->assertStringNotContainsString('>Reduced</span>', $h);
    }
}
