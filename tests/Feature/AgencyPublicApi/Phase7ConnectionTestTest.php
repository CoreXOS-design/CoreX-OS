<?php

namespace Tests\Feature\AgencyPublicApi;

use App\Models\Agency;
use App\Models\AgencyApiKey;
use App\Models\AgencyWebhookDelivery;
use App\Models\Branch;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Agency Public API — "Test connection" on the API Access panel.
 *
 * Spec: .ai/specs/agency-public-api.md §7.1a
 */
class Phase7ConnectionTestTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-rentals']);
        $this->agency->forceFill(['website_enabled' => true])->save();
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin',
        ]);
    }

    private function makeKey(Agency $agency, array $attrs = []): AgencyApiKey
    {
        $minted = AgencyApiKey::mintSecret();
        $key = new AgencyApiKey(array_merge([
            'name'        => 'Main website',
            'key_prefix'  => $minted['prefix'],
            'secret_hash' => $minted['hash'],
            'scopes'      => [AgencyApiKey::SCOPE_LISTINGS_READ, AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE],
            'webhook_url' => 'https://site.example.co.za/api/corex-webhook',
        ], $attrs));
        $key->agency_id = $agency->id;
        $key->webhook_secret = 'whsec-test';
        $key->save();
        return $key;
    }

    private function check(array $json, string $label): array
    {
        return collect($json['checks'])->firstWhere('label', $label);
    }

    public function test_connected_site_passes_and_receives_signed_test_post(): void
    {
        Http::fake(['site.example.co.za/*' => Http::response(['ok' => true], 200)]);
        $key = $this->makeKey($this->agency);
        $key->forceFill(['last_used_at' => now()->subMinutes(5)])->save();

        $json = $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $key]))
            ->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertSame('ok', $this->check($json, 'Website → CoreX')['status']);
        $this->assertSame('ok', $this->check($json, 'CoreX → website')['status']);

        Http::assertSent(function ($req) {
            return $req->url() === 'https://site.example.co.za/api/corex-webhook'
                && $req->header('X-CoreX-Event')[0] === 'connection.test'
                && $req->header('X-CoreX-Signature')[0] === hash_hmac('sha256', $req->body(), 'whsec-test');
        });

        // The test POST is not logged as a delivery (never enters the retry sweep).
        $this->assertSame(0, AgencyWebhookDelivery::withoutGlobalScope(AgencyScope::class)->count());
    }

    public function test_never_used_key_and_rejected_signature_fail(): void
    {
        Http::fake(['site.example.co.za/*' => Http::response('bad signature', 401)]);
        $key = $this->makeKey($this->agency);

        $json = $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $key]))
            ->assertOk()->json();

        $this->assertFalse($json['success']);
        $this->assertSame('fail', $this->check($json, 'Website → CoreX')['status']);
        $this->assertSame('fail', $this->check($json, 'CoreX → website')['status']);
    }

    public function test_missing_webhook_url_warns_without_sending(): void
    {
        Http::fake();
        $key = $this->makeKey($this->agency, ['webhook_url' => null]);
        $key->forceFill(['last_used_at' => now()])->save();

        $json = $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $key]))
            ->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertSame('warn', $this->check($json, 'CoreX → website')['status']);
        Http::assertNothingSent();
    }

    public function test_offline_switch_fails(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $this->agency->forceFill(['website_enabled' => false])->save();
        $key = $this->makeKey($this->agency);

        $json = $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $key]))
            ->assertOk()->json();

        $this->assertFalse($json['success']);
        $this->assertSame('fail', $this->check($json, 'Website switch')['status']);
    }

    public function test_key_from_another_agency_is_404(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-agency']);
        $foreignKey = $this->makeKey($other);

        $this->actingAs($this->user)
            ->postJson(route('agencies.api-keys.test', [$this->agency, $foreignKey]))
            ->assertNotFound();
    }

    public function test_panel_shows_test_connection_button(): void
    {
        $this->makeKey($this->agency);

        $this->actingAs($this->user)
            ->get(route('agencies.edit', $this->agency))
            ->assertOk()
            ->assertSee('Test connection');
    }
}
