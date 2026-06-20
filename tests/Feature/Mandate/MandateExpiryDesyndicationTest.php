<?php

namespace Tests\Feature\Mandate;

use App\Events\Mandate\MandateExpired;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * When a mandate expires the property must come off EVERY advertising channel
 * (PPRA / Property Practitioners Act). Covers the DesyndicateExpiredMandate
 * listener + the website-feed belt-and-braces filter.
 *
 * Audit: .ai/audits/mandate-expiry-desyndication-2026-06-20.md
 */
class MandateExpiryDesyndicationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private AgencyApiKey $key;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // ShouldQueue listeners run inline so firing the event exercises the
        // real discovery-wired listener.
        config(['queue.default' => 'sync']);

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);

        $minted = AgencyApiKey::mintSecret();
        $this->key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'Main Website',
            'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ],
        ]);
    }

    public function test_expiry_delists_all_three_portals_when_live(): void
    {
        $p = $this->makeProperty('active');
        $p->forceFill([
            'pp_syndication_enabled' => true,
            'pp_syndication_status'  => 'active',
            'pp_ref'                 => 'T123',
            'p24_syndication_enabled' => true,
            'p24_syndication_status' => 'active',
            'p24_ref'                => '99887766',
        ])->save();

        // Live on a website.
        app(WebsiteSyndicationService::class)->setEnabled($p, $this->key, true);

        // Portal API services are external (SOAP/HTTP) — assert the listener
        // calls each delist exactly once, without hitting the network.
        $this->mock(Property24SyndicationService::class, function ($m) {
            $m->shouldReceive('deactivateListing')->once()->andReturn(['success' => true]);
        });
        $this->mock(PrivatePropertySyndicationService::class, function ($m) {
            $m->shouldReceive('deactivateListing')->once()->andReturn(['success' => true]);
        });

        event(new MandateExpired(mandate: $p, agencyIdHint: $this->agency->id));

        // Website pivot is now disabled.
        $row = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $p->id)->where('agency_api_key_id', $this->key->id)->first();
        $this->assertFalse((bool) $row->enabled);
        $this->assertSame(PropertyWebsiteSyndication::STATUS_DEACTIVATED, $row->status);
    }

    public function test_expiry_skips_portals_that_were_never_live(): void
    {
        $p = $this->makeProperty('active'); // no PP/P24 refs, no website pivot

        // Neither external service should be touched.
        $this->mock(Property24SyndicationService::class, function ($m) {
            $m->shouldReceive('deactivateListing')->never();
        });
        $this->mock(PrivatePropertySyndicationService::class, function ($m) {
            $m->shouldReceive('deactivateListing')->never();
        });

        event(new MandateExpired(mandate: $p, agencyIdHint: $this->agency->id));

        $this->assertDatabaseMissing('property_website_syndication', ['property_id' => $p->id, 'enabled' => 1]);
    }

    public function test_expired_listing_is_excluded_from_the_website_feed(): void
    {
        // Live on the website but mandate has expired.
        $expired = $this->makeProperty('expired');
        app(WebsiteSyndicationService::class)->setEnabled($expired, $this->key, true);

        // A normal active listing must still appear.
        $active = $this->makeProperty('active');
        app(WebsiteSyndicationService::class)->setEnabled($active, $this->key, true);

        $token = $this->keyToken();
        $resp = $this->withToken($token)->getJson('/api/v1/website/listings')->assertOk();

        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($expired->id, $ids, 'Expired-mandate listing must never be served to a website.');
    }

    // ---- helpers -----------------------------------------------------------

    private function makeProperty(string $status): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => $status, 'price' => 1500000, 'published_at' => now(),
        ]);
    }

    private function keyToken(): string
    {
        $minted = AgencyApiKey::mintSecret();
        $this->key->forceFill(['key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash']])->save();
        return $minted['plaintext'];
    }
}
