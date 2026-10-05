<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\PortalAgentGuard;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Portal Agent Mismatch Guard — .ai/specs/portal-agent-mismatch-guard.md.
 *
 * The incident (2026-09-30): Barbara put Unit 402 Glyndale Sands back on the
 * market. P24 refused with "Some of the specified agents are not active.
 * AgentIds: 191056" — the listing was imported under Johan's P24 agent, which P24
 * had since deactivated. BackOnMarket only flips a status and never says who the
 * agent is, so nothing Barbara could press would ever have fixed it.
 *
 * Johan's decision: when the portal holds a different agent, warn and ask first —
 * never switch on our own.
 */
class PortalAgentMismatchGuardTest extends TestCase
{
    use RefreshDatabase;

    private const P24_AGENCY_ID = 29159;
    private const JOHAN_P24     = 191056;
    private const BARBARA_P24   = 532703;

    private Agency $agency;
    private User $johan;
    private User $barbara;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.property24_syndication.api_url' => 'https://p24.test']);
        Cache::flush();

        $memo = new \ReflectionProperty(Property24ApiClient::class, 'agentsCache');
        $memo->setAccessible(true);
        $memo->setValue(null, []);

        $this->agency = Agency::create([
            'name'          => 'Coastal',
            'slug'          => 'coastal',
            'p24_agency_id' => (string) self::P24_AGENCY_ID,
            'p24_username'  => 'u',
            'p24_password'  => 'p',
        ]);

        $this->johan   = $this->agent('Johan Reichel', self::JOHAN_P24);
        $this->barbara = $this->agent('Barbara Jackson', self::BARBARA_P24);
    }

    private function agent(string $name, int $p24AgentId): User
    {
        return User::factory()->create([
            'agency_id'           => $this->agency->id,
            'name'                => $name,
            'email'               => strtolower(str_replace(' ', '.', $name)) . '@example.test',
            'p24_agent_id'        => $p24AgentId,
            'p24_agent_agency_id' => self::P24_AGENCY_ID,
            'agent_photo_path'    => null,
        ]);
    }

    /** Barbara's listing, still held on P24 under Johan's agent. */
    private function listingHeldUnderJohan(array $overrides = []): Property
    {
        $property = Property::factory()->create(array_merge([
            'agency_id'               => $this->agency->id,
            'agent_id'                => $this->barbara->id,
            'p24_ref'                 => '105396852',
            'p24_syndication_enabled' => true,
            'p24_syndication_status'  => 'active',
        ], $overrides));
        $property->forceFill(['p24_portal_agent_ids' => [(string) self::JOHAN_P24]])->saveQuietly();

        return $property->fresh();
    }

    private function service(): Property24SyndicationService
    {
        return app(Property24SyndicationService::class);
    }

    public function test_a_send_under_a_different_portal_agent_stops_and_asks_first(): void
    {
        Http::fake();
        $property = $this->listingHeldUnderJohan();

        $result = $this->service()->submitListing($property);

        $this->assertFalse($result['success']);
        $this->assertSame(PortalAgentGuard::AGENT_DIFFERS, $result['agent_conflict']['code']);
        $this->assertTrue($result['agent_conflict']['can_switch']);
        $this->assertSame(
            'Property24 has this listing under Johan Reichel. Send it under Barbara Jackson instead?',
            $result['agent_conflict']['message']
        );
        Http::assertNothingSent();

        // Recorded for the listings filter; the listing's own status untouched.
        $fresh = $property->fresh();
        $this->assertTrue($fresh->needsPortalAgentAttention());
        $this->assertSame('active', $fresh->p24_syndication_status);
    }

    public function test_confirming_the_switch_sends_under_the_listing_agent_and_records_it(): void
    {
        Http::fake([
            '*/listings' => Http::response(['listingNumber' => 105396852, 'isOnPortal' => true], 200),
            '*'          => Http::response([], 200),
        ]);
        $property = $this->listingHeldUnderJohan();

        $result = $this->service()->submitListing($property, true);

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/listings')
            && in_array(self::BARBARA_P24, (array) ($r->data()['contactAgentIds'] ?? []), false));

        $fresh = $property->fresh();
        $this->assertSame([(string) self::BARBARA_P24], $fresh->p24_portal_agent_ids);
        $this->assertFalse($fresh->needsPortalAgentAttention());
        $this->assertNull(app(PortalAgentGuard::class)->check($fresh, PortalAgentGuard::P24));
    }

    public function test_an_inactive_listing_agent_blocks_the_send_even_when_confirmed(): void
    {
        Http::fake();
        $this->barbara->forceFill(['is_active' => false])->saveQuietly();
        $property = $this->listingHeldUnderJohan();

        $result = $this->service()->submitListing($property, true);

        $this->assertFalse($result['success']);
        $this->assertSame(PortalAgentGuard::AGENT_INACTIVE, $result['agent_conflict']['code']);
        $this->assertFalse($result['agent_conflict']['can_switch']);
        $this->assertStringContainsString('Barbara Jackson is no longer active in CoreX', $result['message']);
        Http::assertNothingSent();
    }

    public function test_the_person_pressing_the_button_is_irrelevant_when_the_portal_agent_matches(): void
    {
        Http::fake([
            '*/listings' => Http::response(['listingNumber' => 105396852, 'isOnPortal' => true], 200),
            '*'          => Http::response([], 200),
        ]);
        $property = $this->listingHeldUnderJohan();
        $property->forceFill(['p24_portal_agent_ids' => [(string) self::BARBARA_P24]])->saveQuietly();

        $this->actingAs($this->johan);
        $result = $this->service()->submitListing($property->fresh());

        $this->assertTrue($result['success'], 'an admin sending an agent\'s listing is normal — no warning');
        $this->assertArrayNotHasKey('agent_conflict', $result);
    }

    public function test_the_2026_09_30_refusal_becomes_the_switch_question_not_a_dead_end(): void
    {
        // P24's portal agent was never recorded — exactly Barbara's listing.
        Http::fake([
            '*/status*' => Http::response([
                'errorMessage' => 'Validation Failed',
                'errors'       => ['Some of the specified agents are not active. AgentIds: 191056.'],
            ], 400),
            '*' => Http::response([], 200),
        ]);
        $property = Property::factory()->create([
            'agency_id'               => $this->agency->id,
            'agent_id'                => $this->barbara->id,
            'p24_ref'                 => '105396852',
            'p24_syndication_enabled' => true,
            'p24_syndication_status'  => 'deactivated',
        ]);

        $result = $this->service()->reactivateListing($property);

        $this->assertFalse($result['success']);
        $this->assertSame(PortalAgentGuard::AGENT_DIFFERS, $result['agent_conflict']['code']);
        $this->assertSame('reactivate', $result['agent_conflict']['action']);
        $this->assertSame(['Johan Reichel'], $result['agent_conflict']['portal_agents']);

        $fresh = $property->fresh();
        // Still off the portal exactly as before — its Reactivate button stays.
        $this->assertSame('deactivated', $fresh->p24_syndication_status);
        $this->assertSame([(string) self::JOHAN_P24], $fresh->p24_portal_agent_ids);
        $this->assertNotNull(app(PortalAgentGuard::class)->current($fresh, PortalAgentGuard::P24));
    }

    public function test_reactivate_never_flips_status_on_a_listing_held_under_someone_else(): void
    {
        Http::fake();
        $property = $this->listingHeldUnderJohan(['p24_syndication_status' => 'deactivated']);

        $result = $this->service()->reactivateListing($property);

        $this->assertFalse($result['success']);
        $this->assertSame(PortalAgentGuard::AGENT_DIFFERS, $result['agent_conflict']['code']);
        Http::assertNothingSent();
    }

    public function test_confirmed_switch_and_reactivate_sends_the_listing_then_puts_it_back_on_market(): void
    {
        Http::fake([
            '*/listings' => Http::response(['listingNumber' => 105396852, 'isOnPortal' => true], 200),
            '*/status*'  => Http::response([], 200),
            '*'          => Http::response([], 200),
        ]);
        $property = $this->listingHeldUnderJohan(['p24_syndication_status' => 'deactivated']);

        $result = $this->service()->switchAgentAndReactivate($property);

        $this->assertTrue($result['success']);
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->method() . ' ' . parse_url($pair[0]->url(), PHP_URL_PATH));
        $post = $sent->search(fn ($s) => str_starts_with($s, 'POST') && str_ends_with($s, '/listings'));
        $put  = $sent->search(fn ($s) => str_starts_with($s, 'PUT') && str_contains($s, '/status'));
        $this->assertNotFalse($post, 'the full listing (which carries the agent) must be sent');
        $this->assertNotFalse($put, 'then the listing is put back on the market');
        $this->assertLessThan($put, $post, 'agent first, status second');

        $fresh = $property->fresh();
        $this->assertSame([(string) self::BARBARA_P24], $fresh->p24_portal_agent_ids);
        $this->assertFalse($fresh->needsPortalAgentAttention());
    }

    public function test_private_property_stops_and_asks_before_switching_its_agent(): void
    {
        $this->agency->forceFill(['pp_enabled' => true, 'pp_branch_guid' => 'C9FECB32-2025-4ADD-B3F2-29531DD939B9'])->saveQuietly();
        $this->mock(PrivatePropertySoapClient::class, function ($mock) {
            $mock->shouldReceive('forAgency')->andReturnSelf();
            $mock->shouldNotReceive('updateListing');
            $mock->shouldNotReceive('reactivateListing');
        });

        $property = Property::factory()->create([
            'agency_id'              => $this->agency->id,
            'agent_id'               => $this->barbara->id,
            'pp_syndication_enabled' => true,
            'pp_syndication_status'  => 'active',
        ]);
        $property->forceFill(['pp_portal_agent_ids' => [(string) $this->johan->id]])->saveQuietly();

        $result = app(PrivatePropertySyndicationService::class)->submitListing($property->fresh());

        $this->assertFalse($result['success']);
        $this->assertSame(PortalAgentGuard::AGENT_DIFFERS, $result['agent_conflict']['code']);
        $this->assertSame(
            'Private Property has this listing under Johan Reichel. Send it under Barbara Jackson instead?',
            $result['message']
        );
        $this->assertSame('active', $property->fresh()->pp_syndication_status);
    }
}
