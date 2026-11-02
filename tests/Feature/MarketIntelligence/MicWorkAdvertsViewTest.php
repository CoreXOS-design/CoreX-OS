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

    public function test_the_sole_mandate_flag_reads_days_title_and_may_be_ending_from_the_row_on_the_card_the_badge_and_the_full_card(): void
    {
        $tp = $this->file('P24-150100020');
        $l = $this->advert('P24-150100020', 1_200_000, ['tracked_property_id' => $tp, 'description_snippet' => 'Sole mandate - lovely home.']);
        DB::table('prospecting_listings')->where('id', $l->id)->update(['sole_mandate_wording' => 1, 'sole_mandate_first_seen_at' => now()->subDays(81)->startOfDay()]);

        $h = $this->page(['view' => 'adverts']);
        $this->assertStringContainsString('>Sole mandate · 81 d</span>', $h);
        $this->assertStringContainsString('title="first seen as sole mandate ' . now()->subDays(81)->format('j M Y') . '"', $h);
        $this->assertStringNotContainsString('may be ending', $h, '81 days is under the default 90');
        $this->assertStringContainsString('Sole mandate · 81 d', $this->page(['view' => 'list']), 'the List badge says the same');

        DB::table('prospecting_listings')->where('id', $l->id)->update(['sole_mandate_first_seen_at' => now()->subDays(95)->startOfDay()]);
        $h = $this->page(['view' => 'adverts']);
        $this->assertStringContainsString('>Sole mandate · 95 d · may be ending</span>', $h);
        $this->assertMatchesRegularExpression('/var\(--ds-amber[^>]*>Sole mandate · 95 d · may be ending/', $h, 'the ending ribbon takes the warning colour');
        $this->assertStringContainsString('Sole mandate · 95 d · may be ending', $this->page(['view' => 'list']));

        $card = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['_card' => $tp]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="sole-mandate-line"', $card);
        $this->assertStringContainsString('Sole mandate · 95 d · may be ending', $card);

        MicAgencySetting::saveValues($this->agency->id, ['mandate_length_days' => 120]);
        $this->assertStringNotContainsString('may be ending', $this->page(['view' => 'adverts']), 'the agency raised the mandate length');
    }

    public function test_a_price_that_rose_back_shows_no_cut_and_the_card_never_works_out_its_own(): void
    {
        $l = $this->advert('P24-150100021', 1_420_000, ['tracked_property_id' => $this->file('P24-150100021')]);
        DB::table('prospecting_price_history')->insert([
            ['prospecting_listing_id' => $l->id, 'old_price' => 1_399_000, 'new_price' => 1_350_000, 'changed_at' => now()->subDays(40), 'created_at' => now(), 'updated_at' => now()],
            ['prospecting_listing_id' => $l->id, 'old_price' => 1_350_000, 'new_price' => 1_420_000, 'changed_at' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $h = $this->page(['view' => 'adverts']);
        $this->assertStringNotContainsString('line-through', $h, 'the price is back above where it was: no cut shown');
        $this->assertStringNotContainsString('>Reduced</span>', $h);
        $this->assertStringNotContainsString('cut R', $h);
    }

    public function test_the_listed_by_popover_has_one_ruled_entry_per_advert_newest_first_with_the_not_the_same_property_button(): void
    {
        $tp = $this->file('P24-150100030');
        $old = $this->advert('P24-150100030', 1_200_000, ['tracked_property_id' => $tp, 'agency_name' => 'Sea Realty', 'agent_name' => 'Anna Agent'], ['listed_date' => now()->subDays(60)->toDateString(), 'p24_group_id' => 888]);
        foreach (['111', '222'] as $no) {
            DB::table('p24_group_members')->insert(['p24_group_id' => 888, 'listing_number' => $no, 'group_size' => 2, 'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $new = $this->advert('PP-150100031', 1_150_000, ['portal_source' => 'pp', 'agency_name' => 'Other Agents', 'tracked_property_id' => $tp, 'is_active' => false, 'last_seen_at' => now()->subDays(9)], ['listed_date' => now()->subDays(5)->toDateString(), 'sole_mandate_wording' => 1]);
        $h = $this->page(['view' => 'adverts']);

        $this->assertStringContainsString('data-qa="listed-by"', $h);
        $this->assertStringContainsString('miSplit(', $h, 'the pill carries the split state');
        $this->assertStringContainsString('x-text="\'Listed by \' + n + \' agencies\'"', $h, 'the pill count is live');
        $from = strpos($h, 'data-qa="listed-by"');
        $pop = substr($h, $from, strpos($h, 'data-qa="photo-marks"', $from) - $from);
        $this->assertSame(2, substr_count($pop, 'data-qa="pop-advert"'), 'one entry per advert');
        $this->assertSame(2, substr_count($pop, 'data-qa="not-same"'));
        $this->assertSame(2, substr_count($pop, '>Not the same property</button>'));
        $this->assertLessThan(strpos($pop, '>Sea Realty</strong>'), strpos($pop, '>Other Agents</strong>'), 'newest listed first');
        $this->assertStringContainsString('<strong>Sea Realty</strong> · Anna Agent', $pop, 'agency bold, then the agent');
        $this->assertStringContainsString('data-advert-id="' . $new->id . '"', $pop);
        $this->assertStringContainsString('Not seen since ' . now()->subDays(9)->format('j M'), $pop);
        $this->assertStringContainsString('Sole mandate', $pop);
        $this->assertSame(1, substr_count($pop, 'padding: 7px 8px; border-top: 1px solid var(--border);'), 'a hairline on every entry but the first');
        $this->assertStringContainsString('data-qa="split-note"', $pop);
        $this->assertStringContainsString('data-qa="split-undo"', $pop);
        $this->assertStringNotContainsString('confirm(', $pop, 'no browser confirm dialog');
        $this->assertDoesNotMatchRegularExpression('/<a [^>]*>[^<]*<button/', $pop, 'the button is never inside an advert link');
        $this->assertStringContainsString('Confirm - not the same property', $h, 'the armed wording is in the page script');
        // the Adverts line popover shows the same entries but no split button
        $line = substr($h, strpos($h, 'data-qa="line-adverts"'));
        $this->assertStringContainsString('data-qa="pop-advert"', $line);
        $this->assertStringNotContainsString('data-qa="not-same"', substr($line, 0, strpos($line, 'data-qa="line-alerts"') ?: 6000));
    }

    public function test_every_popover_on_the_card_flips_and_shares_one_layout_and_the_split_note_has_a_view_link(): void
    {
        $tp = $this->file('P24-150100040');
        $l = $this->advert('P24-150100040', 1_400_000, ['tracked_property_id' => $tp, 'p24_group_id' => 889]);
        foreach (['111', '222'] as $no) {
            DB::table('p24_group_members')->insert(['p24_group_id' => 889, 'listing_number' => $no, 'group_size' => 2, 'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->advert('PP-150100041', 1_350_000, ['portal_source' => 'pp', 'agency_name' => 'Other Agents', 'tracked_property_id' => $tp]);
        DB::table('prospecting_price_history')->insert([
            ['prospecting_listing_id' => $l->id, 'old_price' => 1_600_000, 'new_price' => 1_500_000, 'changed_at' => now()->subDays(30), 'created_at' => now(), 'updated_at' => now()],
            ['prospecting_listing_id' => $l->id, 'old_price' => 1_500_000, 'new_price' => 1_400_000, 'changed_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $h = $this->page(['view' => 'adverts']);

        $this->assertStringNotContainsString('<x-dropdown', $h);
        $this->assertGreaterThanOrEqual(6, substr_count($h, 'data-qa="mic-pop-panel"'), 'the five fact lines and the Listed-by pill all open through the flipping popover');
        $this->assertStringContainsString('micPop(', $h);
        $this->assertStringContainsString('max-height: min(22rem, var(--pop-max, 22rem))', $h, 'a popover that fits neither side scrolls inside the room it has');

        $price = substr($h, strpos($h, 'Price history'));
        $price = substr($price, 0, strpos($price, 'data-qa="line-adverts"') ?: 4000);
        $this->assertStringContainsString('R 1,600,000 → R 1,500,000', $price);
        $this->assertStringContainsString('R 1,500,000 → R 1,400,000', $price);
        $this->assertGreaterThanOrEqual(2, substr_count($price, 'border-top: 1px solid var(--border)'), 'a hairline between price entries');
        $this->assertStringNotContainsString('A cut counts only', $h, 'no helper text in the popover');

        $from = strpos($h, 'data-qa="listed-by"');
        $pill = substr($h, $from, strpos($h, 'data-qa="photo-marks"', $from) - $from);
        $this->assertStringContainsString('data-qa="split-view"', $pill, 'Split off as its own entry · View · Undo');
        $this->assertStringContainsString('__ID__', $pill, 'the View link opens the new entry from new_file_id');
        $this->assertStringContainsString('agencies_left_on_file', $h, 'the pill count follows the server when it sends one');
    }

    public function test_the_comment_button_opens_an_anchored_popover_and_the_dialogs_do_not_depend_on_a_flex_parent(): void
    {
        $this->advert('P24-150100050', 1_100_000, ['tracked_property_id' => $this->file('P24-150100050')]);
        $h = $this->page(['view' => 'adverts']);

        $this->assertStringContainsString('data-qa="comment-btn"', $h, 'the Comment button on the card');
        $this->assertStringContainsString('data-qa="comment-pop"', $h, 'its comments open in the card popover, anchored to the button');
        $this->assertStringContainsString('loadComments(', $h);
        $btn = strpos($h, 'data-qa="comment-btn"');
        $this->assertNotFalse(strrpos(substr($h, 0, $btn), 'x-data="micPop('), 'the button sits inside the flipping popover component');
        // the shared dialogs: x-show swaps the inline display value, so neither may rely on display:flex on the x-show element
        $this->assertStringContainsString('transform: translate(-50%, -50%)', $h, 'the comments dialog is centred by position');
        $slide = substr($h, strpos($h, 'x-data="miSlideover()"'), 900);
        $this->assertStringNotContainsString('display: flex; justify-content: flex-end', $slide);
        $this->assertStringContainsString('position: absolute; top: 0; right: 0; bottom: 0;', $slide, 'the Intel slide-over keeps to the right edge');
        $this->assertSame(0, preg_match('/x-show="(intel|noteOpen|commentsModalOpen)"[^>]*display: ?flex/s', $h), 'no x-show element relies on an inline display:flex (Alpine replaces it)');
    }

    public function test_the_analyse_and_market_pulse_slide_over_keeps_to_the_right_edge(): void
    {
        foreach (['market-intelligence.analyse', 'market-intelligence.market-pulse'] as $route) {
            $h = $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();
            $at = strpos($h, 'x-data="micSlideover()"');
            $this->assertNotFalse($at, "{$route} carries the slide-over");
            $slide = substr($h, $at, 1200);
            $this->assertStringNotContainsString('z-index: 60; display: flex', substr($slide, 0, 500), $route);
            $this->assertStringContainsString('position: absolute; top: 0; right: 0; bottom: 0;', $slide, $route);
        }
    }

    private function fit(array $over = []): array
    {
        return array_replace([
            'first_name' => 'Alex', 'score' => 88, 'tier' => 'strong',
            'fields' => [
                ['key' => 'price', 'label' => 'Price', 'wants' => '≤ R1.0m', 'has' => 'R 950,000', 'state' => 'ok'],
                ['key' => 'type', 'label' => 'Type', 'wants' => 'House', 'has' => 'Apartment', 'state' => 'miss'],
                ['key' => 'suburb', 'label' => 'Suburb', 'wants' => 'Margate', 'has' => 'Margate', 'state' => 'ok'],
                ['key' => 'beds', 'label' => 'Bedrooms', 'wants' => '3+', 'has' => '3', 'state' => 'ok'],
                ['key' => 'baths', 'label' => 'Bathrooms', 'wants' => '2+', 'has' => null, 'state' => 'unknown'],
                ['key' => 'garages', 'label' => 'Garages', 'wants' => '1+', 'has' => '2', 'state' => 'ok'],
                ['key' => 'parking', 'label' => 'Parking', 'wants' => null, 'has' => null, 'state' => 'na'],
                ['key' => 'erf', 'label' => 'Erf', 'wants' => null, 'has' => null, 'state' => 'na'],
                ['key' => 'floor', 'label' => 'Floor', 'wants' => '150+', 'has' => '120 m²', 'state' => 'miss'],
            ],
            'must_haves' => [['label' => 'Pool', 'state' => 'ok'], ['label' => 'Pet friendly', 'state' => 'unknown'], ['label' => 'Sea view', 'state' => 'miss']],
            'deal_breakers' => [],
        ], $over);
    }

    public function test_buyer_fit_wording_counts_and_colours_follow_the_states_the_engine_gave(): void
    {
        $B = \App\Services\Prospecting\MicWork\BuyerFitLabel::class;
        $sum = $B::summary($this->fit());
        $this->assertSame('Fits Alex · 5 of 10 · misses type, floor +1 · check bathrooms, pet friendly', $sum['text'], 'na fields are not counted');
        $this->assertSame('miss', $sum['tone']);
        $allOk = $this->fit(['fields' => [['key' => 'price', 'label' => 'Price', 'wants' => '≤ R1.0m', 'has' => 'R 1', 'state' => 'ok']], 'must_haves' => [['label' => 'Pool', 'state' => 'ok']]]);
        $this->assertSame(['text' => 'Fits Alex · 2 of 2', 'tone' => 'ok', 'ok' => 2, 'total' => 2], $B::summary($allOk));
        $unk = $this->fit(['fields' => [['key' => 'price', 'label' => 'Price', 'wants' => '≤ R1.0m', 'has' => null, 'state' => 'ok']], 'must_haves' => [['label' => 'Pool', 'state' => 'unknown'], ['label' => 'Pets', 'state' => 'unknown']]]);
        $this->assertSame('Fits Alex · 1 of 3 · check pool, pets', $B::summary($unk)['text']);
        $this->assertSame('unknown', $B::summary($unk)['tone']);
        $this->assertSame(['text' => '≤ R1.0m ✓', 'color' => '#166534'], array_intersect_key($B::chip($this->fit(), 'price'), ['text' => 1, 'color' => 1]));
        $this->assertSame('House ✗', $B::chip($this->fit(), 'type')['text']);
        $this->assertNull($B::chip($this->fit(), 'parking'), 'a field that does not apply gets no chip');
        $this->assertNull($B::chip(['fields' => []], 'price'), 'nothing given, nothing shown');
    }

    public function test_the_mirror_line_the_chips_and_the_popover_render_each_state_in_its_colour(): void
    {
        $fit = $this->fit();
        $line = view('corex.market-intelligence._buyer-fit-line', ['fit' => $fit])->render();
        $this->assertStringContainsString('Alex wants', $line);
        $this->assertSame(1, preg_match('/data-qa="fit-beds"[^>]*color: #166534[^>]*>.*?3\+/s', $line), 'ok is green');
        $this->assertSame(1, preg_match('/data-qa="fit-baths"[^>]*color: #92400e[^>]*>.*?2\+ \?/s', $line), 'unknown is amber with a question mark');
        $this->assertSame(1, preg_match('/data-qa="fit-floor"[^>]*color: #b91c1c[^>]*>.*?150\+/s', $line), 'miss is red');
        $this->assertSame(1, preg_match('/data-qa="fit-parking"[^>]*color: var\(--text-secondary\)[^>]*>.*?–/s', $line), 'na is grey with a dash');
        $this->assertLessThan(strpos($line, 'data-qa="fit-baths"'), strpos($line, 'data-qa="fit-beds"'), 'same order as the facts line');
        $this->assertLessThan(strpos($line, 'data-qa="fit-floor"'), strpos($line, 'data-qa="fit-erf"'));
        $this->assertSame(1, preg_match('/data-qa="fit-garages"[^>]*color: #166534/', $line), 'garages has its own place after baths');
        $this->assertLessThan(strpos($line, 'data-qa="fit-garages"'), strpos($line, 'data-qa="fit-baths"'));

        $this->assertSame('', trim(view('corex.market-intelligence._buyer-fit-line', ['fit' => $this->fit(['fields' => array_map(fn ($f) => array_replace($f, ['state' => 'na', 'wants' => null]), $fit['fields'])])])->render()), 'a buyer who stated none of these gets no line of dashes');

        $chips = view('corex.market-intelligence._buyer-fit-chips', ['fit' => $fit])->render();
        $this->assertStringContainsString('>Pool ✓</span>', $chips);
        $this->assertStringContainsString('>Pet friendly ?</span>', $chips);
        $this->assertStringContainsString('>Sea view ✗</span>', $chips);
        $this->assertStringContainsString('>Deal-breaker: none</span>', $chips);
        $this->assertStringContainsString('>Deal-breaker: Complex ✓</span>', view('corex.market-intelligence._buyer-fit-chips', ['fit' => $this->fit(['deal_breakers' => [['label' => 'Complex', 'state' => 'ok']]])])->render());
        $this->assertSame('', trim(view('corex.market-intelligence._buyer-fit-chips', ['fit' => $this->fit(['must_haves' => [], 'deal_breakers' => []])])->render()), 'nothing set, nothing shown');

        $pop = view('corex.market-intelligence._advert-pop', ['kind' => 'fit', 'adv' => ['buyer_fit' => $fit], 'listing' => (object) ['id' => 1], 'money' => fn ($v) => 'R ' . number_format($v)])->render();
        $this->assertStringContainsString('Alex · 5 of 10 fit · 88% match', preg_replace('/\s+/', ' ', strip_tags($pop)));
        $this->assertSame(12, substr_count($pop, 'data-qa="pop-entry"'), 'nine fields, three must-haves, one line each');
        $this->assertSame(11, substr_count($pop, 'border-top: 1px solid var(--border)'), 'a hairline between lines');
        $this->assertStringContainsString('wants ≤ R1.0m · has R 950,000', $pop);
    }

    public function test_nothing_changes_on_the_cards_when_no_buyer_is_selected(): void
    {
        $this->advert('P24-150100060', 1_100_000, ['tracked_property_id' => $this->file('P24-150100060')], ['description_snippet' => 'A lovely family home close to the beach with a big garden.']);
        $h = $this->page(['view' => 'adverts']);
        foreach (['fit-line', 'fit-chips', 'fit-badge', 'fit-chip-price', 'fit-chip-type', 'mi-advert-body mi-advert-body-fit'] as $k) {
            $this->assertStringNotContainsString($k, $h, $k);
        }
        $this->assertStringContainsString('-webkit-line-clamp:4;', $h, 'the description keeps its four lines');
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
