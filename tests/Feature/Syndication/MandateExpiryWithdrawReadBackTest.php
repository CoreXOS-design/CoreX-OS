<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * AT-68 audit-truth — a withdrawal is recorded as done ONLY after a read-back
 * confirms the listing is actually OFF the portal. P24 acks HTTP 200 while
 * rejecting (AT-221); PP answers "Successful" while leaving the status unchanged
 * (AT-68 parity probe). So `deactivateListing()` must NEVER write 'deactivated'
 * for a removal that did not occur — an unconfirmed / still-live / unreachable
 * read-back returns a retryable failure and leaves the prior status intact.
 *
 * QA1 note: this proves the GATE LOGIC with the portal client faked. The REAL
 * portal round-trip (does a Withdrawn push actually remove the listing; does the
 * read-back return portal truth) needs Staging credentials — see the AT-68 spec's
 * "Verification split".
 */
class MandateExpiryWithdrawReadBackTest extends TestCase
{
    use RefreshDatabase;

    private function seedWorld(array $overrides = []): array
    {
        Queue::fake(); // isolate from observer-dispatched jobs
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $p = Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
        ], $overrides));

        return [$agency, $branch, $agent, $p];
    }

    private function p24Service(): Property24SyndicationService
    {
        return new Property24SyndicationService(new Property24ApiClient(), app(Property24ListingMapper::class));
    }

    private function ppService(PrivatePropertySoapClient $client): PrivatePropertySyndicationService
    {
        return new PrivatePropertySyndicationService($client, app(PrivatePropertyListingMapper::class));
    }

    // ── Property24 ───────────────────────────────────────────────────────────

    public function test_p24_withdraw_confirmed_off_records_deactivated(): void
    {
        [, , , $p] = $this->seedWorld(['p24_syndication_enabled' => true, 'p24_syndication_status' => 'active', 'p24_ref' => '117201765']);
        Http::fake([
            '*is-on-portal*' => Http::response('false', 200, ['Content-Type' => 'application/json']), // OFF
            '*/status*'      => Http::response(['success' => true], 200),
            '*'              => Http::response(['success' => true], 200),
        ]);

        $result = $this->p24Service()->deactivateListing($p->fresh());

        $this->assertTrue($result['success'], 'confirmed-off withdrawal must succeed');
        $this->assertSame('deactivated', $p->fresh()->p24_syndication_status);
    }

    public function test_p24_withdraw_still_on_portal_is_unconfirmed_not_recorded(): void
    {
        [, , , $p] = $this->seedWorld(['p24_syndication_enabled' => true, 'p24_syndication_status' => 'active', 'p24_ref' => '117201765']);
        Http::fake([
            '*is-on-portal*' => Http::response('true', 200, ['Content-Type' => 'application/json']), // STILL ON
            '*/status*'      => Http::response(['success' => true], 200),
            '*'              => Http::response(['success' => true], 200),
        ]);

        $result = $this->p24Service()->deactivateListing($p->fresh());

        $this->assertFalse($result['success'], 'a still-on-portal read-back must NOT be recorded as removed');
        $this->assertTrue($result['unconfirmed'] ?? false);
        $this->assertNotSame('deactivated', $p->fresh()->p24_syndication_status, 'must never claim a removal that did not occur');
        $this->assertStringContainsString('not confirmed', (string) $p->fresh()->p24_last_error);
    }

    public function test_p24_withdraw_readback_unreachable_is_unconfirmed(): void
    {
        [, , , $p] = $this->seedWorld(['p24_syndication_enabled' => true, 'p24_syndication_status' => 'active', 'p24_ref' => '117201765']);
        Http::fake([
            '*is-on-portal*' => Http::response('', 503), // read-back fails
            '*/status*'      => Http::response(['success' => true], 200),
            '*'              => Http::response(['success' => true], 200),
        ]);

        $result = $this->p24Service()->deactivateListing($p->fresh());

        $this->assertFalse($result['success'], 'an unreachable read-back cannot confirm removal');
        $this->assertNotSame('deactivated', $p->fresh()->p24_syndication_status);
    }

    // ── Private Property ─────────────────────────────────────────────────────

    public function test_pp_withdraw_confirmed_inactive_records_deactivated(): void
    {
        [, , , $p] = $this->seedWorld(['pp_syndication_enabled' => true, 'pp_syndication_status' => 'active', 'pp_ref' => 'T55']);
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('deactivateListing')->once()->andReturn(['error' => false]);
        $client->shouldReceive('getListingStatus')->once()->with((string) $p->id)
            ->andReturn(['GetListingStatusResult' => 'Inactive']); // OFF

        $result = $this->ppService($client)->deactivateListing($p->fresh());

        $this->assertTrue($result['success']);
        $this->assertSame('deactivated', $p->fresh()->pp_syndication_status);
    }

    public function test_pp_withdraw_still_live_is_unconfirmed_not_recorded(): void
    {
        [, , , $p] = $this->seedWorld(['pp_syndication_enabled' => true, 'pp_syndication_status' => 'active', 'pp_ref' => 'T55']);
        $client = Mockery::mock(PrivatePropertySoapClient::class);
        $client->shouldReceive('forAgency')->andReturnSelf();
        $client->shouldReceive('deactivateListing')->once()->andReturn(['error' => false]); // PP acks "Successful"…
        $client->shouldReceive('getListingStatus')->once()->with((string) $p->id)
            ->andReturn(['GetListingStatusResult' => 'For Sale']); // …but read-back shows it STILL LIVE

        $result = $this->ppService($client)->deactivateListing($p->fresh());

        $this->assertFalse($result['success'], 'PP "Successful" while still live must NOT be recorded as removed');
        $this->assertTrue($result['unconfirmed'] ?? false);
        $this->assertNotSame('deactivated', $p->fresh()->pp_syndication_status);
        $this->assertStringContainsString('not confirmed', (string) $p->fresh()->pp_last_error);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
