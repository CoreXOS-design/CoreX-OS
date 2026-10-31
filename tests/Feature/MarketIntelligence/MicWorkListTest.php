<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\MicWork\MicWorkList;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** mic-work-tab.md 2.1 D (step 6.3): one row per property file, columns, expand in place, agency wall, old list kept at ?view=adverts. */
final class MicWorkListTest extends TestCase
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
            'agency_id' => $a->id, 'portal_source' => $portal, 'portal_ref' => $ref, 'portal_url' => 'https://portal.test/' . $ref, 'address' => null, 'suburb' => 'Margate',
            'property_type' => 'House', 'price' => 1_500_000, 'bedrooms' => 3, 'agency_name' => 'Acme Realty', 'captured_by_user_id' => $this->admin->id,
            'tracked_property_id' => $tp?->id, 'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(), 'is_active' => true,
        ], $extra));
    }

    public function test_adverts_of_one_file_are_one_row_with_both_portals_and_the_files_address(): void
    {
        $tp = $this->tp($this->agency, 'P24-119000001', '13', 'Newport Road');
        $this->advert($this->agency, 'p24', 'P24-119000001', $tp, ['last_seen_at' => now()->subDay()]);
        $pp = $this->advert($this->agency, 'pp', 'PP-T9100001', $tp, ['agency_name' => 'Other Agents', 'price' => 1_400_000]); // the lead advert (seen last)
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $pp->id, 'old_price' => 1_600_000, 'new_price' => 1_400_000, 'changed_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now()]);
        $lone = $this->advert($this->agency, 'p24', 'P24-119000002', null);
        $this->advert($this->b, 'p24', 'P24-119000003', null); // another agency's advert never shows

        $r = $this->actingAs($this->admin)->get(route('market-intelligence.work'));
        $r->assertOk()->assertSee('data-qa="work-property-list"', false)->assertSee('13 Newport Road', false)->assertSee('Reduced', false)->assertSee('Other Agents', false)
            ->assertSee('PP-T9100001', false)->assertDontSee('P24-119000003', false)->assertSee('2 properties', false);
        $this->assertSame(2, substr_count($r->getContent(), 'data-qa="work-row"'), 'file with two adverts = ONE row, plus the lone advert');
    }

    public function test_old_per_advert_list_is_kept_and_stat_strip_is_gone_from_the_new_view(): void
    {
        $this->advert($this->agency, 'p24', 'P24-119000010', null);
        $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->assertDontSee('Properties matched', false)->assertDontSee('listings to action', false);
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['view' => 'adverts']))->assertOk();
    }

    public function test_sorts_and_empty_state(): void
    {
        $a = $this->tp($this->agency, 'P24-119000020', '5', 'Alpha Road');
        $b = $this->tp($this->agency, 'P24-119000021', '9', 'Zulu Road');
        $this->advert($this->agency, 'p24', 'P24-119000020', $a, ['price' => 900_000]);
        $this->advert($this->agency, 'p24', 'P24-119000021', $b, ['price' => 2_000_000]);

        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['sort' => 'price', 'dir' => 'desc']))->assertOk()->getContent();
        $this->assertTrue(strpos($html, '9 Zulu Road') < strpos($html, '5 Alpha Road'), 'price desc puts the dearer file first');
        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['sort' => 'address', 'dir' => 'asc']))->getContent();
        $this->assertTrue(strpos($html, '5 Alpha Road') < strpos($html, '9 Zulu Road'));
        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['search' => 'nothing-matches-this-zzz']))->assertOk()->assertSee('data-qa="work-empty"', false);
        $this->assertContains('time', MicWorkList::sortKeys());
    }

    public function test_search_matches_the_files_resolved_address_refs_and_every_word(): void
    {
        $tp = $this->tp($this->agency, 'P24-119000030', '13', 'Newport Road');
        $this->advert($this->agency, 'p24', 'P24-119000030', $tp, ['address' => '13 Newport Road']);
        $this->advert($this->agency, 'pp', 'PP-T4203603', $tp); // blank own address: found only through the file
        $other = $this->tp($this->agency, 'P24-119000031', '2', 'Beach Drive');
        $this->advert($this->agency, 'p24', 'P24-119000031', $other);
        $tpB = $this->tp($this->b, 'P24-119000032', '9', 'Newport Road'); // another agency's file never matches ours
        $this->advert($this->b, 'p24', 'P24-119000032', $tpB);

        $get = fn (array $q) => $this->actingAs($this->admin)->get(route('market-intelligence.work', $q))->assertOk()->getContent();
        $html = $get(['search' => 'Newport']);
        $this->assertSame(1, substr_count($html, 'data-qa="work-row"'), 'the file with both adverts is ONE row');
        $this->assertStringContainsString('PP-T4203603', $html);
        $this->assertStringNotContainsString('Beach Drive', $html);
        $this->assertSame(1, substr_count($get(['search' => 'T4203603']), 'data-qa="work-row"'), 'ref digits');
        $this->assertSame(1, substr_count($get(['search' => '13 newport']), 'data-qa="work-row"'), 'every word must match');
        $this->assertSame(0, substr_count($get(['search' => 'newport beach']), 'data-qa="work-row"'));
        $this->assertStringContainsString('P24-119000031', $get(['search' => 'Beach', 'view' => 'adverts']), 'the old advert list gets the same search');
    }

    public function test_price_move_comes_from_the_lead_advert_only_and_never_contradicts_itself(): void
    {
        $tp = $this->tp($this->agency, 'P24-119000040', '13', 'Newport Road');
        $p24 = $this->advert($this->agency, 'p24', 'P24-119000040', $tp, ['price' => 1_499_000, 'last_seen_at' => now()]);
        $pp = $this->advert($this->agency, 'pp', 'PP-T9100040', $tp, ['price' => 700_000, 'agency_name' => 'eXp', 'last_seen_at' => now()->subDays(2)]);
        $h = fn ($id, $old, $new) => DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $id, 'old_price' => $old, 'new_price' => $new, 'changed_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $h($p24->id, 1_600_000, 1_499_000);
        $h($pp->id, 1_499_000, 700_000); // a different advert's history must not leak into the lead's move
        $up = $this->tp($this->agency, 'P24-119000041', '4', 'Rise Road');
        $a = $this->advert($this->agency, 'p24', 'P24-119000041', $up, ['price' => 1_195_000]);
        $h($a->id, 1_000_000, 900_000);
        $h($a->id, 900_000, 1_195_000);

        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertStringContainsString('▼ R101k', $html);
        $this->assertStringNotContainsString('R799k', $html);
        $this->assertStringContainsString('▲ R195k', $html);
        $this->assertStringNotContainsString('▲ R195k ·', $html, 'a net rise never prints a cuts count');
    }

    public function test_own_stock_is_out_by_default_suburb_is_the_resolved_name_new_is_labelled_and_alerts_list_in_place(): void
    {
        DB::table('p24_suburbs')->insert(['name' => 'Southbroom', 'slug' => 'southbroom', 'p24_id' => 987002, 'created_at' => now(), 'updated_at' => now()]);
        $subId = (int) DB::table('p24_suburbs')->where('name', 'Southbroom')->value('id');
        $ours = $this->tp($this->agency, 'P24-119000050', '22', 'Avon Road');
        $this->advert($this->agency, 'p24', 'P24-119000050', $ours);
        \App\Models\Prospecting\TrackedPropertyAgencyWork::forceCreate(['agency_id' => $this->agency->id, 'tracked_property_id' => $ours->id, 'is_own_stock' => true]);
        $theirs = $this->tp($this->agency, 'P24-119000051', '8', 'Beach Road');
        $this->advert($this->agency, 'p24', 'P24-119000051', $theirs, ['suburb' => 'SOUTHBROOM', 'p24_suburb_id' => $subId, 'first_seen_at' => now()->subDays(2)]);
        $alert = \App\Models\P24Listing::create(['agency_id' => $this->agency->id, 'p24_listing_number' => 'P24-119000051', 'asking_price' => 1_400_000, 'original_price' => 1_500_000,
            'suburb' => 'Southbroom', 'first_seen_date' => '2026-06-03', 'last_seen_date' => '2026-06-26', 'times_seen' => 2]);
        $alert->forceFill(['tracked_property_id' => $theirs->id])->save();

        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertStringNotContainsString('22 Avon Road', $html, 'our own stock is not prospecting');
        $this->assertStringContainsString('8 Beach Road', $html);
        $this->assertStringContainsString('Southbroom', $html);
        $this->assertStringNotContainsString('SOUTHBROOM', $html);
        $this->assertStringContainsString('>new<', preg_replace('/\s+/', '', $html) === '' ? '' : str_replace(["\n", '  '], '', $html));
        $this->assertStringContainsString('data-qa="alert-line"', $html);
        $this->assertStringContainsString('was R 1,500,000', $html);

        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['include_in_stock' => 1]))->assertOk()->assertSee('22 Avon Road', false);
    }

    public function test_item_batch_poa_hidden_first_seen_possible_match_chip_agencies_and_address_format(): void
    {
        $this->assertSame('12 Foo Road, Shelly Beach', MicWorkList::tidyAddress('12 Foo Road, SHELLY BEACH'));
        $this->assertSame('Oslo Beach', MicWorkList::titleSuburb('OSLO BEACH'));
        $this->assertSame('938 Fascadale', MicWorkList::tidyLine('938 FASCADALE'));
        $this->assertSame('12 Foo Road, Shelly Beach', MicWorkList::tidyLine('12 Foo Road, SHELLY BEACH'));

        $a = $this->tp($this->agency, 'P24-119000060', '13', 'Newport Road');
        $b = $this->tp($this->agency, 'P24-119000061', '77', 'Marine Parade');
        $lead = $this->advert($this->agency, 'p24', 'P24-119000060', $a, ['price' => 1_499_000, 'agency_name' => 'RE/MAX', 'listed_date' => null]);
        $this->advert($this->agency, 'pp', 'PP-T9100060', $a, ['price' => 1_499_000, 'agency_name' => 'RE/MAX', 'last_seen_at' => now()->subDay()]);
        $this->advert($this->agency, 'p24', 'P24-119000061', $b);
        $h = fn ($old, $new) => DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $lead->id, 'old_price' => $old, 'new_price' => $new, 'changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $h(1_499_000, 0);
        $h(0, 4_500_000);
        $decision = \App\Models\PropertyMatchDecision::forceCreate(['agency_id' => $this->agency->id, 'subject_type' => \App\Services\Prospecting\PropertyIdentityResolver::DECISION_SUBJECT,
            'subject_key' => 'tracked:' . $b->id, 'matched_type' => 'tracked_property', 'matched_id' => $a->id, 'strategy' => 'same_address', 'confidence_score' => 80, 'reason' => 'test', 'decided_at' => now(),
            'incoming_facts' => ['incoming_tracked_property_id' => $b->id, 'kind' => 'same_address']]);

        $html = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertStringContainsString('RE/MAX · 2 adverts', preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('first seen', $html, 'no listed date = first seen is the estimate');
        $this->assertStringNotContainsString('R 0', $html, 'a price on application is not a price');
        $this->assertSame(2, substr_count($html, 'data-qa="possible-match-chip"'), 'both files of the pending pair carry the chip');
        $this->assertStringContainsString(route('corex.possible-matches.index', ['pair' => $decision->id]), $html);
        $this->assertStringContainsString('data-qa="record-card"', $html);
        $this->assertStringContainsString('Open the property page', $html);
    }
}

