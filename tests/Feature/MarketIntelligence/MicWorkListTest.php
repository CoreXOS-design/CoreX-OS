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
        $this->advert($this->agency, 'p24', 'P24-119000001', $tp);
        $pp = $this->advert($this->agency, 'pp', 'PP-T9100001', $tp, ['agency_name' => 'Other Agents', 'price' => 1_400_000]);
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
}
