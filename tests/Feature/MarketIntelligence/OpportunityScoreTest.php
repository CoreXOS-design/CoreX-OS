<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Jobs\MarketIntelligence\RefreshMicFileStatsJob;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Observers\MicSuburbIdObserver;
use App\Services\Address\SuburbResolver;
use App\Services\MarketIntelligence\Pockets\MicStatsBuilder;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use App\Services\MarketIntelligence\Score\OpportunityScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** .ai/specs/mic-work-tab.md section 3 + 11.1, step 5.7 (AT-519): weights from settings, top-2 reasons, stored, refreshed on change. */
final class OpportunityScoreTest extends TestCase
{
    use RefreshDatabase;

    private int $a;
    private int $b;
    private User $agent;
    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        MicSuburbIdObserver::flush();
        SuburbResolver::flush();
        PropertyTypeResolver::flush();
        $this->a = $this->agency();
        $this->b = $this->agency();
        $this->agent = User::factory()->create(['agency_id' => $this->a, 'branch_id' => $this->a, 'role' => 'admin', 'is_active' => true]);
        $country = DB::table('p24_countries')->insertGetId(['p24_id' => 1, 'name' => 'SA', 'created_at' => now(), 'updated_at' => now()]);
        $prov = DB::table('p24_provinces')->insertGetId(['p24_id' => 2, 'p24_country_id' => $country, 'name' => 'KZN', 'created_at' => now(), 'updated_at' => now()]);
        $city = DB::table('p24_cities')->insertGetId(['p24_id' => 376, 'p24_province_id' => $prov, 'name' => 'Ray', 'created_at' => now(), 'updated_at' => now()]);
        $this->sub = DB::table('p24_suburbs')->insertGetId(['p24_id' => 6359, 'p24_city_id' => $city, 'name' => 'Uvongo', 'slug' => 'uvongo', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function agency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => 'T ' . Str::random(6), 'slug' => 't-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function file(int $agency, array $tp = []): int
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(['agency_id' => $agency, 'status' => 'active', 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->sub, 'property_type' => 'House', 'bedrooms' => 3], $tp))->id;
    }

    private function advert(int $agency, int $tp, string $ref, array $extra = []): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agency, 'captured_by_user_id' => $this->agent->id, 'tracked_property_id' => $tp, 'portal_source' => 'p24', 'portal_ref' => $ref,
            'listing_type' => 'sale', 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->sub, 'price' => 800_000, 'bedrooms' => 3, 'property_type' => 'House',
            'is_active' => 1, 'first_seen_at' => now()->subDays(100), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function strongBuyer(int $listing, int $agency): void
    {
        $c = Contact::withoutGlobalScopes()->create(['agency_id' => $agency, 'branch_id' => $agency, 'created_by_user_id' => $this->agent->id, 'is_buyer' => true, 'buyer_state' => 'new',
            'first_name' => 'B', 'last_name' => 'Buyer ' . Str::random(4), 'phone' => '082' . random_int(1000000, 9999999), 'email' => Str::random(6) . '@e.co.za']);
        DB::table('prospecting_buyer_matches')->insert(['prospecting_listing_id' => $listing, 'contact_id' => $c->id, 'agency_id' => $agency, 'score' => 90, 'tier' => 'strong', 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A hot file (cut, re-listed, owner advertising, strong buyer, owner contact) and a bare one. */
    private function seedPair(): array
    {
        $contact = Contact::withoutGlobalScopes()->create(['agency_id' => $this->a, 'branch_id' => $this->a, 'created_by_user_id' => $this->agent->id, 'first_name' => 'O', 'last_name' => 'Owner', 'phone' => '0821234567', 'email' => 'o@e.co.za']);
        $hot = $this->file($this->a, ['street_name' => 'Beach Road', 'owner_contact_id' => $contact->id]);
        $live = $this->advert($this->a, $hot, 'P24-1', ['advertiser_type' => 'owner']);
        $this->advert($this->a, $hot, 'P24-0', ['is_active' => 0]);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $live, 'old_price' => 900_000, 'new_price' => 800_000, 'changed_at' => now()->subDays(5), 'created_at' => now(), 'updated_at' => now()]);
        $this->strongBuyer($live, $this->a);

        $bare = $this->file($this->a, ['property_type' => null, 'bedrooms' => null]);
        $this->advert($this->a, $bare, 'P24-5', ['price' => null, 'bedrooms' => null, 'property_type' => null, 'first_seen_at' => now()->subDays(3)]);

        return [$hot, $bare];
    }

    public function test_a_hot_file_outranks_a_bare_one_and_shows_its_top_two_reasons(): void
    {
        [$hot, $bare] = $this->seedPair();
        app(MicStatsBuilder::class)->refreshAgency($this->a);

        $h = DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $hot)->first();
        $b = DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $bare)->first();
        $this->assertGreaterThan((float) $b->score, (float) $h->score);
        $this->assertLessThanOrEqual(100.0, (float) $h->score);
        $this->assertNotNull($h->reason_1);
        $this->assertNotNull($h->reason_2);
        $this->assertNotSame($h->reason_1, $h->reason_2);
        $this->assertContains($h->reason_1, ['price_cuts', 'owner_selling', 'relisted', 'strong_buyers', 'long_dom', 'reachable', 'unworked', 'deeds_owner']);
        $this->assertSame(30 + 0, (int) ($h->score_seller >= 30 ? 30 : 0), 'seller part reflects cut + re-listed + owner advert');

        $r = app(OpportunityScoreService::class)->score($this->a, $hot);
        $this->assertSame(2, count($r->reasons));
        $this->assertSame((float) $h->score, $r->score);
        $this->assertNull(app(OpportunityScoreService::class)->score($this->a, 999999), 'a file with no stats row has no score');
    }

    public function test_agency_weights_change_the_score_and_bad_weights_fall_back_to_the_defaults(): void
    {
        [$hot] = $this->seedPair();
        $builder = app(MicStatsBuilder::class);
        $builder->refreshAgency($this->a);
        $default = (float) DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $hot)->value('score');
        $buyerPart = (int) DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $hot)->value('score_buyer');

        DB::table('mic_agency_settings')->insert(['agency_id' => $this->a, 'values' => json_encode(['score_weights' => ['seller' => 0, 'buyer' => 100, 'pocket' => 0, 'known' => 0, 'reachable' => 0]]), 'created_at' => now(), 'updated_at' => now()]);
        app(OpportunityScoreService::class)->scoreAgency($this->a);
        $this->assertSame((float) $buyerPart, (float) DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $hot)->value('score'), 'all weight on buyers = the buyer part');

        DB::table('mic_agency_settings')->where('agency_id', $this->a)->update(['values' => json_encode(['score_weights' => ['seller' => 90, 'buyer' => 90, 'pocket' => 0, 'known' => 0, 'reachable' => 0]])]);
        app(OpportunityScoreService::class)->scoreAgency($this->a);
        $this->assertSame($default, (float) DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $hot)->value('score'), 'weights not summing to 100 -> defaults');
    }

    public function test_scoring_one_agency_never_touches_another(): void
    {
        [$hot] = $this->seedPair();
        $other = $this->file($this->b);
        $this->advert($this->b, $other, 'P24-77');
        app(MicStatsBuilder::class)->refreshAgency($this->b);
        $before = DB::table('mic_file_stats')->where('agency_id', $this->b)->first();

        app(MicStatsBuilder::class)->refreshAgency($this->a);

        $after = DB::table('mic_file_stats')->where('agency_id', $this->b)->first();
        $this->assertEquals($before->score, $after->score);
        $this->assertSame(1, DB::table('mic_file_stats')->where('agency_id', $this->b)->count());
    }

    public function test_a_claim_or_price_drop_queues_one_unique_refresh_for_that_file_and_it_can_be_switched_off(): void
    {
        [$hot] = $this->seedPair();
        $listing = (int) DB::table('prospecting_listings')->where('tracked_property_id', $hot)->where('is_active', 1)->value('id');

        config(['mic.refresh_on_change' => false]);
        Queue::fake();
        RefreshMicFileStatsJob::forListing($listing);
        Queue::assertNothingPushed();

        config(['mic.refresh_on_change' => true]);
        RefreshMicFileStatsJob::forListing($listing);
        Queue::assertPushed(RefreshMicFileStatsJob::class, fn ($j) => $j->agencyId === $this->a && $j->trackedPropertyId === $hot && $j->uniqueId() === $this->a . ':' . $hot);

        (new RefreshMicFileStatsJob($this->a, $hot))->handle(app(MicStatsBuilder::class));
        $this->assertNotNull(DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $hot)->value('score'));
    }
}
