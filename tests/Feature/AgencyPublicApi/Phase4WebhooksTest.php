<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agency Public API — Phase 4 (webhooks: domain event → fan-out → signed delivery).
 *
 * Queue is sync in tests, so the dispatched DeliverAgencyWebhook runs inline and
 * we can assert the HTTP call + HMAC signature + delivery-row outcome.
 *
 * Spec: .ai/specs/agency-public-api.md §6, §11
 */
class Phase4WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private string $secret = 'whsec_test_secret_value';

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin']);
    }

    public function test_toggle_delivers_a_signed_listing_published_webhook(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $key = $this->keyWithWebhook();
        $property = $this->makeProperty();

        app(WebsiteSyndicationService::class)->setEnabled($property, $key, true);

        $this->assertDatabaseHas('agency_webhook_deliveries', [
            'agency_api_key_id' => $key->id,
            'event_name'        => 'listing.published',
        ]);
        // Delivered on 200.
        $delivery = \App\Models\AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame(200, $delivery->response_status);

        // The request was signed with HMAC-SHA256(body, webhook_secret).
        Http::assertSent(function ($request) {
            $expected = hash_hmac('sha256', $request->body(), $this->secret);
            return $request->url() === 'https://site.example/hook'
                && $request->hasHeader('X-CoreX-Signature', $expected)
                && $request->hasHeader('X-CoreX-Event', 'listing.published');
        });
    }

    public function test_non_2xx_records_attempt_and_schedules_retry(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $key = $this->keyWithWebhook();
        $property = $this->makeProperty();

        app(WebsiteSyndicationService::class)->setEnabled($property, $key, true);

        $delivery = \App\Models\AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->first();
        $this->assertNull($delivery->delivered_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('HTTP 500', $delivery->last_error);
        $this->assertNotNull($delivery->next_retry_at);
        $this->assertNull($delivery->failed_at);
    }

    public function test_master_switch_off_fires_no_webhook(): void
    {
        Http::fake();
        $this->agency->update(['website_enabled' => false]);
        $key = $this->keyWithWebhook();
        $property = $this->makeProperty();

        app(WebsiteSyndicationService::class)->setEnabled($property, $key, true);

        $this->assertSame(0, \App\Models\AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->count());
        Http::assertNothingSent();
    }

    public function test_key_without_webhook_scope_or_url_gets_no_delivery(): void
    {
        Http::fake();
        // Has webhook_url but NOT the webhooks:receive scope.
        $key = AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'name' => 'No-hook site',
            'key_prefix' => 'cx_live_' . Str::lower(Str::random(8)), 'secret_hash' => hash('sha256', 'x'),
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ], 'webhook_url' => 'https://site.example/hook',
            'webhook_secret' => $this->secret,
        ]);
        $property = $this->makeProperty();

        app(WebsiteSyndicationService::class)->setEnabled($property, $key, true);

        $this->assertSame(0, \App\Models\AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->count());
        Http::assertNothingSent();
    }

    public function test_removed_webhook_on_disable(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $key = $this->keyWithWebhook();
        $property = $this->makeProperty();
        $svc = app(WebsiteSyndicationService::class);

        $svc->setEnabled($property, $key, true);
        $svc->setEnabled($property, $key, false);

        $this->assertDatabaseHas('agency_webhook_deliveries', ['event_name' => 'listing.removed']);
    }

    public function test_property_update_fans_out_listing_updated(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $key = $this->keyWithWebhook();
        $property = $this->makeProperty();
        app(WebsiteSyndicationService::class)->setEnabled($property, $key, true);

        // Change a marketing field on the now-syndicated listing.
        $property->update(['price' => 1999000]);

        $this->assertDatabaseHas('agency_webhook_deliveries', [
            'agency_api_key_id' => $key->id,
            'event_name'        => 'listing.updated',
        ]);
    }

    // ---- helpers -----------------------------------------------------------

    private function keyWithWebhook(): AgencyApiKey
    {
        return AgencyApiKey::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'   => $this->agency->id,
            'name'        => 'Coastal Website',
            'key_prefix'  => 'cx_live_' . Str::lower(Str::random(8)),
            'secret_hash' => hash('sha256', 'irrelevant'),
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => 'https://site.example/hook',
            'webhook_secret' => $this->secret,
        ]);
    }

    private function makeProperty(): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id, 'branch_id' => $this->branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Sea-view', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 2495000, 'published_at' => now(),
        ]);
    }
}
