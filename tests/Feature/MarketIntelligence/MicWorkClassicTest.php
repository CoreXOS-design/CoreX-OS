<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** Step 6.11: the classic per-advert list is THE Work screen again; the new parts are additive (tiles, score + reasons, source icons + envelope, chip, record card, search). */
final class MicWorkClassicTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private Agency $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function tp(Agency $a, string $ref, string $no, string $street): TrackedProperty
    {
        return app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate($a->id, ['street_number' => $no, 'street_name' => $street, 'suburb' => 'Margate'], ['type' => 'p24', 'ref' => $ref]);
    }

    private function advert(Agency $a, string $portal, string $ref, ?TrackedProperty $tp, array $extra = []): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $a->id, 'portal_source' => $portal, 'portal_ref' => $ref, 'portal_url' => 'https://portal.test/' . $ref, 'address' => $tp ? '13 Newport Road' : null, 'suburb' => 'Margate',
            'property_type' => 'House', 'price' => 1_500_000, 'bedrooms' => 3, 'agency_name' => 'Acme Realty', 'captured_by_user_id' => $this->admin->id,
            'tracked_property_id' => $tp?->id, 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(), 'is_active' => true,
        ], $extra));
    }

    public function test_the_default_work_screen_is_the_classic_list_with_the_tiles_above_the_stat_bar_and_no_greeting(): void
    {
        $this->advert($this->agency, 'p24', 'P24-120000001', null);
        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();

        $this->assertStringContainsString('class="mi-row"', $html, 'the classic per-advert row');
        $this->assertStringContainsString('Properties matched', $html, 'the stat bar stays');
        $this->assertStringContainsString('mi-filter-rail', $html, 'the left filter column stays');
        $this->assertStringContainsString('data-qa="work-tiles"', $html);
        $this->assertLessThan(strpos($html, 'Properties matched'), strpos($html, 'data-qa="work-tiles"'), 'the tile row sits above the stat bar');
        $this->assertStringNotContainsString("Here's your week", $html);
        $this->assertStringNotContainsString('data-qa="work-property-list"', $html, 'the property view is not the default');
    }

    public function test_rows_carry_score_reasons_source_icons_envelope_possible_match_and_a_card_toggle(): void
    {
        $tp = $this->tp($this->agency, 'P24-120000010', '13', 'Newport Road');
        $other = $this->tp($this->agency, 'P24-120000011', '77', 'Marine Parade');
        $this->advert($this->agency, 'p24', 'P24-120000010', $tp);
        $alert = \App\Models\P24Listing::create(['agency_id' => $this->agency->id, 'p24_listing_number' => 'P24-120000010', 'asking_price' => 1_500_000, 'original_price' => 1_500_000,
            'suburb' => 'Margate', 'first_seen_date' => '2026-06-17', 'last_seen_date' => '2026-08-05', 'times_seen' => 2]);
        $alert->forceFill(['tracked_property_id' => $tp->id])->save();
        DB::table('mic_file_stats')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $tp->id, 'score' => 83.0, 'reason_1' => 'price_cuts', 'reason_2' => 'strong_buyers', 'computed_at' => now()]);
        \App\Models\PropertyMatchDecision::forceCreate(['agency_id' => $this->agency->id, 'subject_type' => \App\Services\Prospecting\PropertyIdentityResolver::DECISION_SUBJECT, 'subject_key' => 'k1',
            'matched_type' => 'tracked_property', 'matched_id' => $tp->id, 'strategy' => 'address_unit', 'confidence_score' => 80, 'reason' => 'Property24 groups these adverts as one property', 'decided_at' => now(),
            'incoming_facts' => ['incoming_tracked_property_id' => $other->id, 'kind' => 'portal_group']]);

        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('data-qa="score-badge"', $html);
        $this->assertStringContainsString('Score 83', $text);
        $this->assertStringContainsString('· Price cuts', $text);
        $this->assertStringContainsString('data-qa="source-icons"', $html);
        $this->assertStringContainsString('data-qa="envelope"', $html);
        $this->assertStringContainsString('data-qa="possible-match-chip"', $html);
        $this->assertStringContainsString('data-qa="card-toggle"', $html);
        $this->assertStringContainsString('_card=' . $tp->id, $html);
        $this->assertStringContainsString('Opportunity score', $html, 'score is a sort option once scores exist');
    }

    public function test_score_is_the_default_sort_once_scores_exist_and_the_old_sorts_still_work(): void
    {
        $low = $this->tp($this->agency, 'P24-120000020', '1', 'Low Road');
        $high = $this->tp($this->agency, 'P24-120000021', '2', 'High Road');
        $this->advert($this->agency, 'p24', 'P24-120000020', $low, ['address' => '1 Low Road', 'last_seen_at' => now()]);
        $this->advert($this->agency, 'p24', 'P24-120000021', $high, ['address' => '2 High Road', 'last_seen_at' => now()->subDays(3)]);
        foreach ([[$low, 20.0], [$high, 90.0]] as [$t, $sc]) {
            DB::table('mic_file_stats')->insert(['agency_id' => $this->agency->id, 'tracked_property_id' => $t->id, 'score' => $sc, 'computed_at' => now()]);
        }
        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, '1 Low Road'), strpos($html, '2 High Road'), 'highest score first by default');
        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['sort' => 'last_seen_at', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, '2 High Road'), strpos($html, '1 Low Road'), 'the old sort still works');
    }

    public function test_the_record_card_endpoint_serves_a_reachable_file_and_404s_for_another_agency(): void
    {
        $tp = $this->tp($this->agency, 'P24-120000030', '13', 'Newport Road');
        $this->advert($this->agency, 'p24', 'P24-120000030', $tp);
        $tpB = $this->tp($this->b, 'P24-120000031', '9', 'Beach Road');
        $this->advert($this->b, 'p24', 'P24-120000031', $tpB);

        $this->actingAs($this->admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('market-intelligence.work', ['_card' => $tp->id]))->assertOk()
            ->assertSee('Adverts', false)->assertSee('Open the property page', false)->assertSee('P24-120000030', false);
        $this->actingAs($this->admin)->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->get(route('market-intelligence.work', ['_card' => $tpB->id]))->assertNotFound();
    }

    public function test_the_old_search_still_works_and_also_finds_the_files_address(): void
    {
        $tp = $this->tp($this->agency, 'P24-120000040', '13', 'Newport Road');
        $this->advert($this->agency, 'pp', 'PP-T9900040', $tp, ['address' => null]); // blank own address: found only through the file
        $other = $this->advert($this->agency, 'p24', 'P24-120000041', null, ['address' => '5 Elsewhere Street']);

        $byFile = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['search' => 'Newport']))->assertOk()->getContent();
        $this->assertStringContainsString('PP-T9900040', $byFile);
        $this->assertStringNotContainsString('P24-120000041', $byFile);
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['search' => 'Elsewhere']))->assertOk()->assertSee('P24-120000041', false);
    }
}
