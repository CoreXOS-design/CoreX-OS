<?php

declare(strict_types=1);

namespace Tests\Feature\Mic;

use App\Models\Branch;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\Matching\BuyerMatchFreshness;
use App\Services\Matching\BuyerMatchRules;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * mic-work-tab.md 13.15 addendum: a buyer whose cache rows pre-date the current rules is rebuilt ONCE, on demand, before the list is shown.
 */
final class BuyerRefreshOnDemandTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private Contact $buyer;
    private int $good;
    private int $bad;

    protected function setUp(): void
    {
        parent::setUp();
        BuyerMatchRules::flush();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->buyer = Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => 'Jan', 'last_name' => 'Breedt',
            'phone' => '082' . random_int(1000000, 9999999), 'email' => 'jan-' . Str::random(5) . '@e.co.za',
        ]);
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'contact_id' => $this->buyer->id, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale', 'is_primary' => true,
            'price_max' => 1_200_000, 'beds_min' => 2, 'property_types' => ['Apartment / Flat'],
        ]);
        $this->good = $this->advert(['property_type' => 'Apartment', 'bedrooms' => 2, 'price' => 900_000]);
        $this->bad = $this->advert(['property_type' => 'House', 'bedrooms' => 1, 'price' => 599_000]); // a 1-bed House: not for Jan
        // the OLD cache: both rows, no rules marker (as written before the rules changed), the bad one scoring 65
        foreach ([[$this->good, 100], [$this->bad, 65]] as [$id, $score]) {
            DB::table('prospecting_buyer_matches')->insert([
                'prospecting_listing_id' => $id, 'contact_id' => $this->buyer->id, 'agency_id' => $this->agency->id, 'score' => $score, 'tier' => 'strong',
                'matched_features' => json_encode(['engine' => 'canonical']), 'matched_at' => now(), 'last_recompute_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function advert(array $extra): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->admin->id, 'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8),
            'portal_url' => 'https://example.com/' . Str::random(6), 'address' => Str::random(5) . ' Road', 'suburb' => 'Uvongo', 'listing_type' => 'sale',
            'bathrooms' => 1, 'garages' => 0, 'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function page(): string
    {
        return $this->actingAs($this->admin)->get(route('market-intelligence.work', ['buyer_id' => $this->buyer->id]))->assertOk()->getContent();
    }

    public function test_a_buyer_with_old_rows_is_stale_and_the_page_shows_updating_and_no_list(): void
    {
        $this->assertTrue(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $this->buyer->id));
        $page = $this->page();
        $this->assertStringContainsString('data-qa="buyer-updating"', $page);
        $this->assertStringContainsString("Updating Jan's matches", $page);
        $this->assertStringNotContainsString('data-qa="advert-card"', $page, 'a stale row never reaches the list');
        $this->assertStringNotContainsString('data-qa="buyer-banner-count"', $page);
    }

    public function test_the_refresh_rebuilds_that_buyer_stamps_the_rows_and_drops_the_wrong_one(): void
    {
        $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-refresh'), ['buyer_id' => $this->buyer->id])->assertOk()->assertJson(['state' => 'done']);

        $this->assertFalse(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $this->buyer->id));
        $rows = DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->get()->keyBy('prospecting_listing_id');
        $this->assertTrue($rows->has($this->good));
        $this->assertFalse($rows->has($this->bad), 'the 1-bed House fails Jan\'s hard filters, so its old row is gone');
        $this->assertSame(BuyerMatchRules::VERSION, json_decode($rows[$this->good]->matched_features, true)['rules']);

        $page = $this->page();
        $this->assertStringNotContainsString('data-qa="buyer-updating"', $page, 'once: the second visit goes straight to the list');
        $this->assertStringContainsString('data-qa="advert-card"', $page);
        $this->assertStringContainsString('data-qa="buyer-banner-count"', $page);
    }

    public function test_a_rule_change_marks_everyone_stale_again(): void
    {
        $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-refresh'), ['buyer_id' => $this->buyer->id])->assertOk();
        $this->assertFalse(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $this->buyer->id));

        DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->update(['matched_features' => json_encode(['engine' => 'canonical', 'rules' => '0.0-older'])]);
        $this->assertTrue(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $this->buyer->id), 'a different rules version counts as stale');
    }

    public function test_a_second_request_while_one_is_running_gets_busy_and_a_buyer_with_no_rows_is_never_stale(): void
    {
        $lock = Cache::lock('mic-buyer-refresh:' . $this->agency->id . ':' . $this->buyer->id, 60);
        $this->assertTrue($lock->get());
        $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-refresh'), ['buyer_id' => $this->buyer->id])->assertOk()->assertJson(['state' => 'busy']);
        $lock->release();

        $other = Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'is_buyer' => true, 'first_name' => 'No', 'last_name' => 'Rows']);
        $this->assertFalse(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $other->id));
    }

    public function test_another_agencys_user_cannot_trigger_a_rebuild_of_this_agencys_buyer(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $outsider = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->actingAs($outsider)->postJson(route('market-intelligence.buyer-refresh'), ['buyer_id' => $this->buyer->id])->assertNotFound();
        $this->assertTrue(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $this->buyer->id), 'untouched');
    }

    public function test_fresh_writes_are_stamped_by_every_writer(): void
    {
        DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->delete();
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
        $this->assertFalse(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $this->buyer->id));

        DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->delete();
        app(PropertyMatchScoringService::class)->recomputeProspectingMatches($this->good);
        $this->assertFalse(app(BuyerMatchFreshness::class)->isStale($this->agency->id, $this->buyer->id));
        $this->assertSame(1, DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->count());
    }
}
