<?php

declare(strict_types=1);

namespace Tests\Feature\Mic;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\BuyerPropertyExclusion;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\BuyerMatchRules;
use App\Services\Matching\BuyerPropertyExclusions;
use App\Services\Matching\MatchingService;
use App\Services\Prospecting\BuyerMatchTierService;
use App\Services\PropertyMatchScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * .ai/specs/mic-work-tab.md 13.17 - "Not for <buyer>": one record per buyer and property file, shared by Market Intelligence and Core Matches.
 */
final class NotForBuyerTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private Contact $buyer;
    private ContactMatch $wishlist;
    private int $file;
    private int $advert;
    private Property $stock;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        BuyerMatchRules::flush();
        $this->setUpAgency();
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true, 'name' => 'Johan Reichel']);
        $this->buyer = $this->makeBuyer($this->agency, 'Alex');
        $this->wishlist = $this->makeWishlist($this->buyer, ['is_primary' => true]);
        $this->file = $this->makeFile($this->agency->id, '522 Bidstone Rd');
        $this->advert = $this->makeAdvert($this->agency->id, $this->file);
        $this->stock = $this->makeStock($this->agency->id, $this->file);
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
    }

    private function makeBuyer(Agency $agency, string $first): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => Branch::query()->where('agency_id', $agency->id)->value('id'), 'created_by_user_id' => $this->admin->id ?? null,
            'is_buyer' => true, 'buyer_state' => 'new', 'first_name' => $first, 'last_name' => 'Swanepoel',
            'phone' => '082' . random_int(1000000, 9999999), 'email' => strtolower($first) . '-' . Str::random(5) . '@e.co.za',
        ]);
    }

    private function makeWishlist(Contact $buyer, array $extra = []): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $buyer->agency_id, 'contact_id' => $buyer->id, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'price_max' => 1_000_000, 'category' => 'Residential', 'property_types' => ['House'],
        ], $extra));
    }

    private function makeFile(int $agencyId, string $address): int
    {
        $num = explode(' ', $address)[0];

        return (int) app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $agencyId, ['street_number' => $num, 'street_name' => trim(substr($address, strlen($num))), 'suburb' => 'Uvongo'], ['type' => 'p24', 'ref' => 'P24-' . Str::random(8)]
        )->id;
    }

    private function makeAdvert(int $agencyId, int $file): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $agencyId, 'tracked_property_id' => $file, 'captured_by_user_id' => $this->admin->id, 'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8),
            'portal_url' => 'https://example.com/' . Str::random(6), 'address' => '522 Bidstone Rd', 'suburb' => 'Uvongo', 'listing_type' => 'sale',
            'price' => 900_000, 'bedrooms' => 3, 'bathrooms' => 2, 'garages' => 1, 'property_type' => 'House',
            'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeStock(int $agencyId, int $file): Property
    {
        return Property::forceCreate([
            'agency_id' => $agencyId, 'agent_id' => $this->admin->id, 'title' => 'Own stock', 'status' => 'active', 'listing_type' => 'sale', 'price' => 900_000, 'beds' => 3,
            'baths' => 2, 'garages' => 1, 'property_type' => 'House', 'category' => 'Residential', 'tracked_property_id' => $file,
        ]);
    }

    private function cacheRow(): ?object
    {
        return DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->where('prospecting_listing_id', $this->advert)->first();
    }

    private function micIds(array $q = []): array
    {
        $r = \Illuminate\Http\Request::create(route('market-intelligence.work'), 'GET', ['buyer_id' => $this->buyer->id] + $q);
        $r->setUserResolver(fn () => $this->admin);
        $view = app()->call([app(\App\Http\Controllers\CoreX\MarketIntelligenceController::class), 'work'], ['request' => $r]);

        return collect($view->getData()['listings']->items())->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function hide(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-hide.store'), ['buyer_id' => $this->buyer->id, 'file' => $this->file]);
    }

    private function hiddenIds(): array
    {
        return array_map('intval', $this->wishlist->fresh()->hidden_property_ids ?? []);
    }

    public function test_the_premise_the_advert_is_a_strong_match_for_the_buyer_and_in_the_list(): void
    {
        $this->assertSame(100, (int) $this->cacheRow()->score);
        $this->assertContains($this->advert, $this->micIds());
        $this->assertSame(1, app(BuyerMatchTierService::class)->tiersForListings([$this->advert], $this->agency->id)[$this->advert]['strong']);
        $this->assertNotContains($this->stock->id, $this->hiddenIds());
    }

    public function test_hiding_removes_it_from_the_mic_list_the_counts_the_buyer_line_and_the_core_match_page(): void
    {
        $this->hide()->assertOk()->assertJson(['ok' => true, 'hidden_count' => 1]);

        $this->assertNotNull($this->cacheRow()->dismissed_at, 'the cache row is dismissed - every reader skips dismissed rows');
        $this->assertNotContains($this->advert, $this->micIds(), 'gone from the normal list (and so from the banner count)');
        $this->assertSame(0, app(BuyerMatchTierService::class)->tiersForListings([$this->advert], $this->agency->id)[$this->advert]['strong'] ?? 0, 'a hidden buyer is not a matched buyer for that property');
        $this->assertContains($this->stock->id, $this->hiddenIds(), 'the own-stock twin is hidden on the buyer\'s wishlist, so the Core Match page hides it too');
        $listed = app(MatchingService::class)->propertiesForMatch($this->wishlist->fresh(), ['agent_id' => null, 'include_hidden' => false]);
        $this->assertFalse($listed->contains('id', $this->stock->id));

        $this->assertContains($this->advert, $this->micIds(['hidden' => 1]), 'the hidden list shows it');
        $this->assertSame(1, BuyerPropertyExclusion::query()->active()->count());
    }

    public function test_it_applies_to_every_wishlist_the_buyer_has(): void
    {
        $second = $this->makeWishlist($this->buyer, ['property_types' => ['House', 'Townhouse']]);
        $this->hide()->assertOk();
        $this->assertContains($this->stock->id, array_map('intval', $second->fresh()->hidden_property_ids ?? []));
        $this->assertContains($this->stock->id, $this->hiddenIds());
    }

    public function test_undo_and_restore_put_it_back_everywhere(): void
    {
        $id = (int) $this->hide()->json('id');
        $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-hide.restore', $id))->assertOk()->assertJson(['hidden_count' => 0]);

        $this->assertNull($this->cacheRow()->dismissed_at);
        $this->assertContains($this->advert, $this->micIds());
        $this->assertNotContains($this->stock->id, $this->hiddenIds());
        $this->assertSame(0, BuyerPropertyExclusion::query()->active()->count());
        $this->assertNotNull(BuyerPropertyExclusion::query()->find($id)->restored_at, 'kept for the history, not deleted');
    }

    public function test_it_stays_hidden_through_recomputes_even_when_the_cache_row_was_rebuilt_from_nothing(): void
    {
        $this->hide()->assertOk();

        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
        $this->assertNotNull($this->cacheRow()->dismissed_at, 'a normal recompute keeps it hidden');

        DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->delete(); // a truncating regeneration
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
        $this->assertNotNull($this->cacheRow()->dismissed_at, 'a rebuilt row is born hidden');

        DB::table('prospecting_buyer_matches')->where('contact_id', $this->buyer->id)->delete();
        app(PropertyMatchScoringService::class)->recomputeProspectingMatches($this->advert); // the per-listing writer
        $this->assertNotNull($this->cacheRow()->dismissed_at);
        $this->assertNotContains($this->advert, $this->micIds());
    }

    public function test_hiding_on_the_core_match_page_hides_it_in_mic_and_unhiding_restores_it(): void
    {
        $url = route('corex.contacts.matches.toggleHide', [$this->buyer, $this->wishlist, $this->stock->id]);
        $this->actingAs($this->admin)->post($url, ['reason' => 'Too small for him'])->assertRedirect();

        $this->assertNotNull($this->cacheRow()->dismissed_at, 'hidden on the Core Match page = hidden in MIC');
        $this->assertNotContains($this->advert, $this->micIds());
        $this->assertContains($this->stock->id, $this->hiddenIds());
        $this->assertSame('Too small for him', BuyerPropertyExclusion::query()->active()->first()->reason);

        $this->actingAs($this->admin)->post($url)->assertRedirect(); // toggles back
        $this->assertNull($this->cacheRow()->dismissed_at);
        $this->assertNotContains($this->stock->id, $this->hiddenIds());
        $this->assertContains($this->advert, $this->micIds());
    }

    public function test_the_buyers_contact_history_and_the_property_file_history_say_who_and_when(): void
    {
        $this->hide()->assertOk();
        $text = '522 Bidstone Road marked not for Alex by Johan Reichel on ' . now()->format('j M');

        $this->assertSame($text, DB::table('contact_audit_log')->where('contact_id', $this->buyer->id)->where('event_type', 'property_not_for_buyer')->value('human_summary'));
        $this->assertSame($text, DB::table('tracked_property_agency_work_events')->where('tracked_property_id', $this->file)->where('kind', 'buyer_hidden')->value('reason'));

        $id = (int) BuyerPropertyExclusion::query()->active()->value('id');
        $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-hide.restore', $id))->assertOk();
        $this->assertStringContainsString('restored for Alex by Johan Reichel', (string) DB::table('contact_audit_log')->where('event_type', 'property_restored_for_buyer')->value('human_summary'));
        $this->assertSame(1, DB::table('tracked_property_agency_work_events')->where('kind', 'buyer_restored')->count());
    }

    public function test_the_banner_hidden_link_and_the_card_button_data_are_on_the_page(): void
    {
        $page = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['buyer_id' => $this->buyer->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="buyer-hide-btn"', $page);
        $this->assertStringContainsString('Not for Alex', $page);

        $this->hide()->assertOk();
        $after = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['buyer_id' => $this->buyer->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="buyer-hidden-link"', $after);
        $this->assertStringNotContainsString('data-qa="buyer-hide-btn"', $after, 'no advert left in the normal list');

        $hidden = $this->actingAs($this->admin)->get(route('market-intelligence.work', ['buyer_id' => $this->buyer->id, 'hidden' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="buyer-restore-btn"', $hidden);
        $this->assertStringContainsString('Back to matches', $hidden);
    }

    public function test_no_button_without_a_selected_buyer(): void
    {
        $page = $this->actingAs($this->admin)->get(route('market-intelligence.work'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-qa="buyer-hide"', $page);
    }

    public function test_another_agency_cannot_hide_or_restore_for_this_agencys_buyer_or_file(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $outsider = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);

        // their user, our buyer
        $this->actingAs($outsider)->postJson(route('market-intelligence.buyer-hide.store'), ['buyer_id' => $this->buyer->id, 'file' => $this->file])->assertNotFound();
        // our user, our buyer, THEIR file
        $theirFile = $this->makeFile($other->id, '9 Other Rd');
        $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-hide.store'), ['buyer_id' => $this->buyer->id, 'file' => $theirFile])->assertNotFound();
        // their user restoring our exclusion
        $id = (int) $this->hide()->json('id');
        $this->actingAs($outsider)->postJson(route('market-intelligence.buyer-hide.restore', $id))->assertNotFound();
        $this->assertNotNull($this->cacheRow()->dismissed_at, 'still hidden');
    }

    public function test_hiding_twice_is_one_record(): void
    {
        $this->hide()->assertOk();
        $this->hide()->assertOk();
        $this->assertSame(1, BuyerPropertyExclusion::query()->active()->count());
        $this->assertSame(1, DB::table('contact_audit_log')->where('event_type', 'property_not_for_buyer')->count());
    }

    public function test_the_service_hides_a_lone_advert_that_is_not_linked_to_a_file(): void
    {
        $lone = (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->admin->id, 'portal_source' => 'p24', 'portal_ref' => 'ref-' . Str::random(8),
            'portal_url' => 'https://example.com/' . Str::random(6), 'address' => '1 Lone Rd', 'suburb' => 'Uvongo', 'listing_type' => 'sale', 'price' => 850_000, 'bedrooms' => 3,
            'property_type' => 'House', 'is_active' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($this->buyer->id);
        $this->assertContains($lone, $this->micIds());

        $this->actingAs($this->admin)->postJson(route('market-intelligence.buyer-hide.store'), ['buyer_id' => $this->buyer->id, 'file' => -$lone])->assertOk();
        $this->assertNotContains($lone, $this->micIds());
        $this->assertContains($this->advert, $this->micIds(), 'the other advert is untouched');
        app(BuyerPropertyExclusions::class); // resolves
    }
}
