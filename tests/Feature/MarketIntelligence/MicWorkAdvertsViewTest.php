<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\MicAgencySetting;
use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\SuggestedAction;
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
        $l = ProspectingListing::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://p.test/' . $ref, 'address' => '7 Card Road', 'suburb' => 'Margate',
            'property_type' => 'House', 'price' => $price, 'bedrooms' => 3, 'bathrooms' => 2, 'garages' => 1, 'agency_name' => 'Sea Realty', 'captured_by_user_id' => $this->admin->id,
            'first_seen_at' => now()->subDays(80), 'last_seen_at' => now(), 'is_active' => true,
        ], $extra));
        if ($raw) {
            DB::table('prospecting_listings')->where('id', $l->id)->update($raw);
        }

        return $l;
    }

    private function file(string $ref): int
    {
        return (int) app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($this->agency->id, ['street_number' => '7', 'street_name' => 'Card Road', 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => $ref])->id;
    }

    private function page(array $q = []): string
    {
        return $this->actingAs($this->admin)->get(route('market-intelligence.work', $q))->assertOk()->getContent();
    }

    public function test_the_adverts_view_shows_a_p24_style_card_with_the_real_snippet_ribbons_and_dates(): void
    {
        $l = $this->advert('P24-150100001', 1_400_000, ['tracked_property_id' => $this->file('P24-150100001')], [
            'card_heading' => '3 Bedroom House in Margate', 'description_snippet' => 'Sole mandate - sunny family home two minutes from the beach.',
            'listed_date' => now()->subDays(75)->toDateString(), 'is_reduced' => 1, 'portal_status' => 'under_offer', 'advertiser_type' => 'owner', 'parking' => 2,
        ]);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $l->id, 'old_price' => 1_500_000, 'new_price' => 1_400_000, 'changed_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()]);
        $h = $this->page(['view' => 'adverts', 'show_status' => 'all']);

        foreach (['advert-card', 'advert-photo', 'advert-left', 'advert-right', 'advert-price', 'advert-heading', 'advert-description', 'advert-icons', 'advert-dates', 'line-buttons', 'line-buyers', 'line-price', 'line-adverts', 'line-alerts', 'line-market'] as $qa) {
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
        $tp = $this->file('P24-150100003');
        $this->advert('P24-150100003', 1_200_000, ['tracked_property_id' => $tp]);
        $other = $this->advert('PP-150100004', 1_000_000, ['portal_source' => 'pp', 'agency_name' => 'Other Agents', 'tracked_property_id' => $tp]);
        $h = $this->page(['view' => 'adverts']);
        $this->assertStringNotContainsString('line-through', $h);
        $this->assertMatchesRegularExpression('/line-adverts.*2 adverts · 2 agencies · 2 live/s', $h);
        $this->assertStringNotContainsString('>Reduced</span>', $h);
    }

    public function test_two_adverts_of_one_property_file_show_as_one_card(): void
    {
        $tp = $this->file('P24-150100005');
        $a = $this->advert('P24-150100005', 1_100_000, ['agency_name' => 'Alpha', 'tracked_property_id' => $tp]);
        $this->advert('P24-150100006', 1_100_000, ['agency_name' => 'Beta', 'tracked_property_id' => $tp]);
        $this->assertSame(1, substr_count($this->page(['view' => 'adverts']), 'data-qa="advert-card"'));
    }

    public function test_the_right_block_is_seven_single_lines_with_no_wrapping_and_the_marks_are_links(): void
    {
        $tp = $this->file('P24-150100010');
        $a = $this->advert('P24-150100010', 1_400_000, ['tracked_property_id' => $tp, 'agency_name' => 'Sea Realty'], [
            'card_heading' => '3 Bedroom House in Margate', 'description_snippet' => 'Lovely home.', 'is_reduced' => 1, 'portal_status' => 'under_offer', 'parking' => 2,
        ]);
        $this->advert('PP-150100011', 1_350_000, ['portal_source' => 'pp', 'portal_url' => 'https://pp.test/150100011', 'agency_name' => 'Other Agents', 'tracked_property_id' => $tp]);
        $this->advert('P24-150100012', 1_300_000, ['agency_name' => 'Third Agents', 'tracked_property_id' => $tp]);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $a->id, 'old_price' => 1_500_000, 'new_price' => 1_400_000, 'changed_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mic_file_stats')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $tp, 'score' => 76, 'reason_1' => 'price_cuts', 'reason_2' => 'long_dom', 'buyers_strong' => 18, 'buyers_mid' => 1, 'cut_amount' => 100000, 'computed_at' => now()]);
        $h = $this->page(['view' => 'adverts', 'show_status' => 'all']);

        $this->assertSame(1, preg_match('/<div class="mi-advert-right" data-qa="advert-right">(.*?)<\/article>/s', $h, $m));
        $right = $m[1];
        $lines = preg_match_all('/data-qa="line-[a-z]+"/', $right);
        $this->assertSame(7, $lines, 'score, buttons, buyers, price, adverts, alerts, market - and nothing else');
        foreach (['flex-wrap', 'break-words', 'whitespace-normal', 'white-space:normal'] as $wrap) {
            $this->assertStringNotContainsString($wrap, $right, "no wrapping: {$wrap}");
        }
        $this->assertStringContainsString('Score 76', $right);
        $this->assertMatchesRegularExpression('/line-buyers.*18 strong · 1 mid/s', $right);
        $this->assertMatchesRegularExpression('/line-price.*cut R 100,000/s', $right);
        $this->assertMatchesRegularExpression('/line-adverts.*3 adverts · 3 agencies · 3 live · P24, PP/s', $right);
        $this->assertMatchesRegularExpression('/line-alerts.*No alerts/s', $right);
        $this->assertStringContainsString('Intel', $right);
        $this->assertStringNotContainsString('Property intel</button>', $right, 'one short label, no second line');
        $this->assertStringContainsString('Full card', $right);
        // the photo's marks are links to the adverts themselves
        $this->assertStringContainsString('data-qa="mark-p24"', $h);
        $this->assertMatchesRegularExpression('/<a href="https:\/\/p\.test\/P24-1501000[^"]*"[^>]*data-qa="mark-p24"/', $h);
        $this->assertMatchesRegularExpression('/<a href="https:\/\/pp\.test\/150100011"[^>]*data-qa="mark-pp"/', $h);
    }

    public function test_the_adverts_line_counts_gone_adverts_and_a_card_without_text_has_no_gap(): void
    {
        $tp = $this->file('P24-150100020');
        $this->advert('P24-150100020', 1_000_000, ['tracked_property_id' => $tp, 'agency_name' => 'A']);
        foreach (['B', 'C', 'D'] as $i => $ag) {
            $this->advert('P24-15010002' . ($i + 1), 1_000_000, ['tracked_property_id' => $tp, 'agency_name' => $ag, 'is_active' => false]);
        }
        $h = $this->page(['view' => 'adverts']);
        $this->assertMatchesRegularExpression('/line-adverts.*4 adverts · 4 agencies · 1 live · P24/s', $h);
        $this->assertStringNotContainsString('data-qa="advert-description"', $h);
        $this->assertMatchesRegularExpression('/margin-top:4px;[^"]*" data-qa="advert-icons"/', $h, 'no description: the icon row sits straight under the address');
    }

    public function test_the_list_row_carries_the_badges_and_its_card_button_opens_the_same_intelligence_block(): void
    {
        $tp = $this->file('P24-150100030');
        $this->advert('P24-150100030', 1_000_000, ['tracked_property_id' => $tp, 'advertiser_type' => 'owner'], ['is_reduced' => 1]);
        $h = $this->page(['view' => 'list']);
        $this->assertStringContainsString('data-qa="row-badge"', $h);
        $this->assertStringContainsString('>Reduced</span>', $h);
        $this->assertStringContainsString('>Owner selling</span>', $h);
        $this->assertStringContainsString('data-qa="row-intel-host"', $h);
        $this->assertStringContainsString('data-qa="line-buyers"', $h, 'the Card button opens the same block as the Adverts card');
        $this->assertStringContainsString('intel = !intel', $h);
        $this->assertStringNotContainsString('data-qa="advert-card"', $h);
    }

    public function test_an_advert_that_is_no_longer_seen_says_since_when_and_its_link_is_greyed_not_removed(): void
    {
        $tp = $this->file('P24-150100040');
        $this->advert('P24-150100040', 1_000_000, ['tracked_property_id' => $tp, 'agency_name' => 'A']);
        $this->advert('PP-150100041', 1_000_000, ['portal_source' => 'pp', 'portal_url' => 'https://pp.test/150100041', 'tracked_property_id' => $tp, 'agency_name' => 'B', 'is_active' => false, 'last_seen_at' => '2026-08-12 10:00:00']);
        $h = $this->page(['view' => 'adverts']);
        $this->assertStringNotContainsString('Gone', $h);
        $this->assertStringContainsString('Not seen since 12 Aug', $h);
        $this->assertMatchesRegularExpression('/<a href="https:\/\/pp\.test\/150100041"[^>]*data-qa="mark-pp"[^>]*line-through/', $h, 'the PP mark is greyed but still a link');
        $this->assertDoesNotMatchRegularExpression('/<a href="https:\/\/p\.test\/P24-150100040"[^>]*data-qa="mark-p24"[^>]*line-through/', $h, 'the live P24 mark is not');
    }

    public function test_a_card_with_no_wording_shows_the_feature_list_and_rates_and_no_text_uses_the_muted_colour(): void
    {
        $tp = $this->file('P24-150100050');
        $this->advert('P24-150100050', 1_000_000, ['tracked_property_id' => $tp], [
            'features' => json_encode(['Pets Allowed' => 'Yes', 'Pool' => '1', 'Security' => 'Yes', 'Garden' => 'Yes', 'Street Address' => '7 Card Road', 'Furnished' => 'No']),
            'rates_amount' => 1200, 'levies_amount' => 950,
        ]);
        $h = $this->page(['view' => 'adverts']);
        $this->assertStringContainsString('data-qa="advert-features"', $h);
        $this->assertStringContainsString('Pool · Garden · Security · Pets Allowed', $h);
        $this->assertStringNotContainsString('Furnished', $h);
        $this->assertStringContainsString('Rates R 1,200 pm · Levies R 950 pm', $h);
        $this->assertStringNotContainsString('data-qa="advert-description"', $h);
        // contrast: --text-muted fails 4.5:1 on the card surface, so no text on the card uses it
        $this->assertSame(1, preg_match('/<article class="mi-advert".*?<\/article>/s', $h, $m));
        $this->assertStringNotContainsString('text-muted', $m[0]);
        // and a card with neither wording nor features has no empty block
        $this->advert('P24-150100051', 900_000);
        $h2 = $this->page(['view' => 'adverts']);
        $this->assertSame(1, substr_count($h2, 'data-qa="advert-features"'));
    }

    public function test_every_compact_button_label_fits_including_the_claimed_by_colleague_state(): void
    {
        $l = $this->advert('P24-150100060', 1_000_000);
        $labels = ['RESOLVE COLLEAGUE CLAIM' => 'Colleague', 'ALREADY EXISTS · OPEN PROPERTY' => 'Exists', 'RE-PITCH STOCK' => 'Re-pitch', 'LOG OUTCOME' => 'Outcome',
            'PITCH NOW · HIGH' => 'Pitch now', 'Involve colleague (Angelique Venter)' => 'Colleague', 'FLAG TO BM' => 'Flag to BM', 'Pitched' => 'Pitched'];
        foreach ($labels as $full => $short) {
            $html = view('corex.market-intelligence._suggested-action-chip', [
                'suggested' => new SuggestedAction(rank: 'R8', label: $full, tier: 'info', icon: 'info', tooltipHtml: 'Angelique Venter held this claim 9 days.', clickType: 'modal', modalKey: 'release-as-manager:1'),
                'listing' => $l, 'compact' => true,
            ])->render();
            $this->assertMatchesRegularExpression('/<span\s+style="min-width:0; overflow:hidden; text-overflow:ellipsis;"\s*>\s*' . preg_quote($short, '/') . '\s*<\/span>/', $html, $full);
            $this->assertLessThanOrEqual(11, mb_strlen($short), $full);
            $this->assertSame(1, preg_match('/title="([^"]*)"/', $html, $tm), $full);
            $this->assertStringContainsString(mb_strtolower(preg_replace('/\s*\(.*$/', '', $full)), mb_strtolower(html_entity_decode($tm[1])), 'the full label is the tooltip: ' . $full);
        }
    }
}
