<?php

namespace Tests\Feature\Syndication;

use App\Jobs\ProcessPrivatePropertyEventFeed;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * STANDING RULE (Johan, 2026-10-08): "the listed date is the date the property went live on the
 * portals." CoreX stamps listed_date the moment a listing FIRST goes live on a portal (first successful
 * P24 publish / PP activation) and RE-stamps it when it is re-published after being deactivated. A
 * refresh of an advert that is already live - including an imported listing - never moves it.
 */
class ListedDateStampedOnGoLiveTest extends TestCase
{
    use RefreshDatabase;

    private const P24_AGENCY_ID = 29159;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.property24_syndication.api_url' => 'https://p24.test']);
        Cache::flush();
        $memo = new \ReflectionProperty(Property24ApiClient::class, 'agentsCache');
        $memo->setAccessible(true);
        $memo->setValue(null, []);
    }

    // ── the rule itself ──────────────────────────────────────────────────

    public function test_rule_first_go_live_and_republish_after_deactivation_stamp_today_a_refresh_does_not(): void
    {
        $today = now()->toDateString();

        $first = new Property(['p24_syndication_status' => null]);
        $this->assertSame(['listed_date' => $today], $first->listedDateStampOnGoLive('p24'));
        $this->assertSame(['listed_date' => $today], $first->listedDateStampOnGoLive('pp'));

        $refresh = new Property(['p24_syndication_status' => 'active']);
        $refresh->p24_activated_at = now()->subDays(30);
        $this->assertSame([], $refresh->listedDateStampOnGoLive('p24'));

        $republished = new Property(['p24_syndication_status' => 'deactivated']);
        $republished->p24_activated_at = now()->subDays(30);
        $this->assertSame(['listed_date' => $today], $republished->listedDateStampOnGoLive('p24'));

        // Deactivated on P24 but still live on Private Property: the property never left the market.
        $stillLive = new Property(['p24_syndication_status' => 'deactivated', 'pp_syndication_status' => 'active']);
        $stillLive->p24_activated_at = now()->subDays(30);
        $stillLive->pp_activated_at = now()->subDays(30);
        $this->assertSame([], $stillLive->listedDateStampOnGoLive('p24'));

        // Second portal going live for a property already live on the first: not a new listing date.
        $second = new Property(['p24_syndication_status' => 'active']);
        $second->p24_activated_at = now()->subDays(10);
        $this->assertSame([], $second->listedDateStampOnGoLive('pp'));
    }

    // ── Property24 submit ────────────────────────────────────────────────

    public function test_first_successful_p24_publish_stamps_the_listed_date(): void
    {
        $property = $this->p24Listing(['listed_date' => now()->subDays(40)->toDateString(), 'p24_syndication_status' => null, 'p24_ref' => null]);

        $this->fakeP24();
        $result = app(Property24SyndicationService::class)->submitListing($property);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(now()->toDateString(), $property->fresh()->listed_date->toDateString(), 'creation-day stamp replaced by the go-live day');
    }

    public function test_refreshing_a_live_p24_listing_does_not_move_the_listed_date(): void
    {
        $property = $this->p24Listing([
            'listed_date' => now()->subDays(40)->toDateString(), 'p24_ref' => '117411168',
            'p24_syndication_status' => 'active', 'p24_activated_at' => now()->subDays(40),
        ]);

        $this->fakeP24();
        $result = app(Property24SyndicationService::class)->submitListing($property);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(now()->subDays(40)->toDateString(), $property->fresh()->listed_date->toDateString());
    }

    public function test_refreshing_an_imported_live_listing_never_replaces_the_listed_date_with_the_refresh_day(): void
    {
        // Imported 105 days ago and live on P24 since long before: the first CoreX submit is a refresh.
        $property = $this->p24Listing([
            'listed_date' => null, 'p24_ref' => '117411168', 'p24_syndication_status' => 'active',
            'p24_imported_at' => now()->subDays(105), 'p24_activated_at' => now()->subDays(105),
        ]);

        $this->fakeP24();
        $result = app(Property24SyndicationService::class)->submitListing($property);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertNull($property->fresh()->listed_date, 'a refresh is not a go-live');
    }

    public function test_republishing_a_deactivated_p24_listing_re_stamps_the_listed_date(): void
    {
        $property = $this->p24Listing([
            'listed_date' => now()->subDays(60)->toDateString(), 'p24_ref' => '117411168',
            'p24_syndication_status' => 'deactivated', 'p24_activated_at' => now()->subDays(60),
        ]);

        $this->fakeP24();
        $result = app(Property24SyndicationService::class)->submitListing($property);

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(now()->toDateString(), $property->fresh()->listed_date->toDateString());
    }

    // ── Private Property activation event ────────────────────────────────

    public function test_pp_first_activation_stamps_and_a_repeat_activation_does_not(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'pp_enabled' => true, 'pp_branch_guid' => 'GUID-A']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'L', 'suburb' => 'Uvongo', 'property_type' => 'house',
            'status' => 'active', 'price' => 1000000, 'pp_syndication_enabled' => true, 'pp_syndication_status' => 'submitted',
            'listed_date' => now()->subDays(30)->toDateString(),
        ]);

        $this->runActivated($p);
        $this->assertSame(now()->toDateString(), $p->fresh()->listed_date->toDateString(), 'first PP activation = went live');

        // Backdate, then a repeat Activated event for the already-live listing.
        Property::withoutGlobalScopes()->where('id', $p->id)->update(['listed_date' => now()->subDays(12)->toDateString()]);
        $this->runActivated($p);
        $this->assertSame(now()->subDays(12)->toDateString(), $p->fresh()->listed_date->toDateString());

        // Deactivated on PP, then activated again = re-listed.
        Property::withoutGlobalScopes()->where('id', $p->id)->update(['pp_syndication_status' => 'deactivated']);
        $this->runActivated($p);
        $this->assertSame(now()->toDateString(), $p->fresh()->listed_date->toDateString());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function runActivated(Property $p): void
    {
        $response = ['GetListingEventFeedByBranchResult' => ['ContinuationKey' => null, 'FeedData' => ['LisitngEventFeedData' => [
            ['ListingFeedEventType' => 'Activated', 'ListingFeedRef' => (string) $p->id, 'OfficeFeedRef' => 'GUID-A', 'EventDescription' => 'T999'],
        ]]]];
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('getListingEventFeed')->andReturn($response);
        (new ProcessPrivatePropertyEventFeed())->handle($client);
    }

    private function fakeP24(): void
    {
        Http::fake([
            '*/listings' => Http::response(['listingNumber' => 117411168, 'isOnPortal' => true], 200),
            '*'          => Http::response([], 200),
        ]);
    }

    private function p24Listing(array $attrs): Property
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'p24_agency_id' => (string) self::P24_AGENCY_ID, 'p24_username' => 'u', 'p24_password' => 'p']);
        $agent = User::factory()->create([
            'agency_id' => $agency->id, 'name' => 'Retha Kelly', 'email' => 'retha@example.test',
        ]);
        // Not mass-assignable on User: set explicitly (the P24 agent link the mapper needs).
        $agent->forceFill(['p24_agent_id' => 440413, 'p24_agent_agency_id' => self::P24_AGENCY_ID, 'agent_photo_path' => null])->saveQuietly();
        $branch = Branch::firstOrCreate(['agency_id' => $agency->id, 'name' => 'Main']);

        // P24's agent list for this agency (cached, as in production) names our listing agent.
        $key = (new \ReflectionClassConstant(Property24ApiClient::class, 'AGENTS_CACHE_PREFIX'))->getValue() . self::P24_AGENCY_ID;
        Cache::put($key, ['success' => true, 'data' => [['id' => 440413, 'sourceReference' => 'CoreX-Agent-' . $agent->id]]], 3600);

        $property = Property::withoutEvents(fn () => Property::create(array_merge([
            'external_id' => (string) Str::uuid(), 'title' => 'Syndication test listing', 'branch_id' => $branch->id,
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'listing_type' => 'sale', 'property_type' => 'house',
            'status' => 'active', 'price' => 1500000, 'suburb' => 'Margate', 'address' => '1 Test Road',
            'description' => 'A well-kept family home a short walk from the beach.', 'p24_suburb_id' => $this->verifiedP24SuburbId(),
        ], $attrs)));

        // State immediately after a successful sync, as in Property24RefreshCostTest: agent profile + gallery
        // fingerprints match, so the submit is the single listing POST (no agent scan, no photo upload).
        $property->forceFill(['p24_image_signature' => $property->p24ImageSignature()])->saveQuietly();
        $agent->forceFill(['p24_profile_signature' => $this->profileSignature($agent)])->saveQuietly();

        return $property->fresh();
    }

    private function profileSignature(User $agent): string
    {
        $service = app(Property24SyndicationService::class);
        $method  = new \ReflectionMethod($service, 'agentProfilePayload');
        $method->setAccessible(true);

        return md5((string) json_encode($method->invoke($service, $agent, (int) $agent->p24_agent_id, self::P24_AGENCY_ID)));
    }

    private function verifiedP24SuburbId(): int
    {
        $country  = \App\Models\P24Country::firstOrCreate(['p24_id' => 1], ['name' => 'South Africa']);
        $province = \App\Models\P24Province::firstOrCreate(['p24_id' => 4], ['p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $city     = \App\Models\P24City::firstOrCreate(['p24_id' => 376], ['p24_province_id' => $province->id, 'name' => 'Margate']);

        return \App\Models\P24Suburb::firstOrCreate(
            ['p24_id' => 6360],
            ['name' => 'Margate', 'slug' => 'margate', 'p24_city_id' => $city->id, 'p24_verified_at' => now()]
        )->id;
    }
}
