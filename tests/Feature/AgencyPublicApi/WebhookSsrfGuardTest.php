<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Jobs\DeliverAgencyWebhook;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Support\Net\SafeFeedUrl;
use App\Support\Net\SafeWebhookUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Audit fix (2026-10-20, finding B2): the webhook address is an SSRF vector. "Test connection", the save
 * validation and the queued delivery all refuse non-public destinations, follow no redirects and never
 * echo raw connection errors. Spec: .ai/specs/agency-public-api.md §6.4.
 */
class WebhookSsrfGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    /** @var array<string, array<int, string>> */
    private array $dns = ['site.example.co.za' => ['93.184.216.34'], 'sneaky.example.co.za' => ['10.0.0.5']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SafeFeedUrl::class, new SafeFeedUrl(fn (string $host) => $this->dns[$host] ?? []));

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-rentals']);
        $this->agency->forceFill(['website_enabled' => true])->save();
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);
    }

    private function makeKey(?string $url): AgencyApiKey
    {
        $minted = AgencyApiKey::mintSecret();
        $key = new AgencyApiKey([
            'name' => 'Main website', 'key_prefix' => $minted['prefix'], 'secret_hash' => $minted['hash'],
            'scopes' => [AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => $url,
        ]);
        $key->agency_id = $this->agency->id;
        $key->webhook_secret = 'whsec-test';
        $key->save();

        return $key;
    }

    /** @return array<string, array{0: string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback'           => ['http://127.0.0.1/hook'],
            'localhost ipv6'     => ['http://[::1]/hook'],
            'metadata'           => ['http://169.254.169.254/latest/meta-data/'],
            'rfc1918 10/8'       => ['https://10.1.2.3/hook'],
            'rfc1918 192.168'    => ['https://192.168.1.10/hook'],
            'rfc1918 172.16'     => ['https://172.16.0.9/hook'],
            'cgnat'              => ['https://100.64.0.1/hook'],
            'multicast'          => ['https://224.0.0.1/hook'],
            'ipv6 unique local'  => ['https://[fd00::1]/hook'],
            'ipv4-mapped v6'     => ['https://[::ffff:127.0.0.1]/hook'],
            'decimal ip'         => ['http://2130706433/hook'],
            'hostname to 10.x'   => ['https://sneaky.example.co.za/hook'],
            'file scheme'        => ['file:///etc/passwd'],
            'private ip on custom port' => ['http://10.1.2.3:1050/hook'],
            'loopback on custom port'   => ['http://127.0.0.1:6379/hook'],
        ];
    }

    /** @dataProvider blockedUrls */
    public function test_guard_refuses_non_public_destinations(string $url): void
    {
        $this->assertNotNull(app(SafeWebhookUrl::class)->error($url), $url);
    }

    public function test_guard_accepts_a_public_https_host(): void
    {
        $this->assertNull(app(SafeWebhookUrl::class)->error('https://site.example.co.za/api/corex-webhook'));
    }

    public function test_guard_accepts_a_public_host_on_a_custom_port(): void
    {
        // An agency's site receiver may listen on a custom port (HFC's does: http://<public ip>:1050/...).
        // Refusing it failed every website delivery from 2026-10-21, so prices stopped updating on Refresh.
        $this->assertNull(app(SafeWebhookUrl::class)->error('http://91.99.130.85:1050/api/corex-webhook'));
        $this->assertNull(app(SafeWebhookUrl::class)->error('https://site.example.co.za:8443/api/corex-webhook'));
    }

    public function test_a_custom_port_webhook_is_actually_delivered(): void
    {
        Http::fake(['site.example.co.za:8443/*' => Http::response('', 200)]);

        $response = app(SafeWebhookUrl::class)->post('https://site.example.co.za:8443/api/corex-webhook', '{}', []);

        $this->assertTrue($response->successful());
        Http::assertSentCount(1);
    }

    public function test_feed_guard_still_refuses_custom_ports(): void
    {
        // The relaxation is webhook-only: article feeds keep the 80/443 rule.
        $this->assertNotNull(app(SafeFeedUrl::class)->error('https://site.example.co.za:6379/feed'));
    }

    /** @dataProvider blockedUrls */
    public function test_test_connection_sends_nothing_to_a_non_public_destination_and_does_not_echo_errors(string $url): void
    {
        Http::fake();
        $key = $this->makeKey($url);
        $key->forceFill(['last_used_at' => now()])->save();

        $json = $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $key]))
            ->assertOk()->json();

        Http::assertNothingSent();
        $row = collect($json['checks'])->firstWhere('label', 'CoreX → website');
        $this->assertSame('fail', $row['status']);
        $this->assertStringNotContainsStringIgnoringCase('cURL', $row['detail']);
    }

    public function test_redirects_are_not_followed(): void
    {
        Http::fake(['site.example.co.za/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);
        $key = $this->makeKey('https://site.example.co.za/api/corex-webhook');
        $key->forceFill(['last_used_at' => now()])->save();

        $json = $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $key]))
            ->assertOk()->json();

        Http::assertSentCount(1);
        $row = collect($json['checks'])->firstWhere('label', 'CoreX → website');
        $this->assertSame('fail', $row['status']);
        $this->assertStringContainsString('redirects', $row['detail']);
    }

    public function test_a_connection_error_is_shown_in_plain_words_only(): void
    {
        Http::fake(['site.example.co.za/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to site.example.co.za port 443 after 3 ms: Connection refused')]);
        $key = $this->makeKey('https://site.example.co.za/api/corex-webhook');

        $json = $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $key]))
            ->assertOk()->json();

        $row = collect($json['checks'])->firstWhere('label', 'CoreX → website');
        $this->assertSame('fail', $row['status']);
        $this->assertStringNotContainsString('cURL', $row['detail']);
        $this->assertStringNotContainsString('Connection refused', $row['detail']);
        $this->assertStringContainsString('Could not reach the website', $row['detail']);
    }

    public function test_saving_a_private_webhook_url_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('agencies.api-keys.store', $this->agency), ['name' => 'Site', 'webhook_url' => 'http://169.254.169.254/latest/meta-data/'])
            ->assertSessionHasErrors('webhook_url');
        $this->assertSame(0, AgencyApiKey::query()->where('agency_id', $this->agency->id)->count());

        $this->actingAs($this->user)
            ->post(route('agencies.api-keys.store', $this->agency), ['name' => 'Site', 'webhook_url' => 'https://site.example.co.za/hook'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, AgencyApiKey::query()->where('agency_id', $this->agency->id)->count());
    }

    public function test_the_real_delivery_refuses_a_private_address_and_does_not_retry(): void
    {
        Http::fake();
        $key = $this->makeKey('https://sneaky.example.co.za/hook'); // resolves to 10.0.0.5
        $delivery = AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agency_api_key_id' => $key->id,
            'event_name' => 'listing.published', 'payload' => ['id' => 1], 'attempts' => 0,
        ]);

        (new DeliverAgencyWebhook($delivery->id))->handle();

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertNull($delivery->delivered_at);
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->next_retry_at);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_the_delivery_pins_the_checked_address(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $key = $this->makeKey('https://site.example.co.za/hook');
        $delivery = AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'agency_api_key_id' => $key->id,
            'event_name' => 'listing.published', 'payload' => ['id' => 1], 'attempts' => 0,
        ]);

        (new DeliverAgencyWebhook($delivery->id))->handle();

        $this->assertNotNull($delivery->refresh()->delivered_at);
    }

    public function test_the_test_route_is_throttled(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $key = $this->makeKey('https://site.example.co.za/hook');

        $last = null;
        for ($i = 0; $i < 11; $i++) {
            $last = $this->actingAs($this->user)->postJson(route('agencies.api-keys.test', [$this->agency, $key]));
        }
        $last->assertStatus(429);
    }
}
