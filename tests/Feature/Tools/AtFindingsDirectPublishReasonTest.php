<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\User;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use App\Services\PermissionService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 finding — a direct (non-scheduled) Facebook/Instagram publish that failed set status 'failed'
 * with no reason. It now stores the same plain, token-scrubbed reason the scheduled publisher uses.
 */
final class AtFindingsDirectPublishReasonTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    public function test_a_failed_direct_publish_stores_a_plain_scrubbed_reason_and_the_history_shows_it(): void
    {
        [$agent, $property] = $this->world();

        // Meta answers with a hard error whose text carries an access token.
        $leak = 'Client error: `POST https://graph.facebook.com/v19.0/1000/feed` resulted in a `400 Bad Request` '
            . 'response: {"error":{"message":"Invalid parameter access_token=EAABsecrettokenvalue1234567890 rejected"}}';
        $handler = new MockHandler([new Response(400, [], json_encode(['error' => ['message' => $leak]]))]);
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService(new Client(['handler' => HandlerStack::create($handler)])));
        $this->app->instance(MetaOAuthService::class, new MetaOAuthService(new Client(['handler' => HandlerStack::create(new MockHandler([]))])));

        $res = $this->actingAs($agent)->postJson(route('corex.properties.marketing.publish', $property), [
            'platforms' => ['facebook'],
            'copy'      => 'Lovely home for sale',
        ]);

        $res->assertOk()->assertJsonPath('results.facebook.ok', false);

        $post = PropertyMarketingPost::withoutGlobalScopes()->sole();
        $this->assertSame('failed', $post->status);
        $this->assertNotEmpty($post->failure_reason, 'a failed direct publish must record why');
        $this->assertStringNotContainsString('EAABsecrettokenvalue', (string) $post->failure_reason);
        $this->assertStringNotContainsString('EAABsecrettokenvalue', (string) $res->json('results.facebook.error'));
        $this->assertStringStartsWith('Facebook', (string) $post->failure_reason);

        // The marketing hub's post history shows the reason next to the Failed badge.
        $this->get(route('corex.properties.marketing.index', $property))
            ->assertOk()->assertSee($post->failure_reason);
    }

    public function test_a_post_that_publishes_keeps_no_failure_reason(): void
    {
        [$agent, $property] = $this->world();
        $handler = new MockHandler([new Response(200, [], json_encode(['id' => '1000_555']))]);
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService(new Client(['handler' => HandlerStack::create($handler)])));

        $this->actingAs($agent)->postJson(route('corex.properties.marketing.publish', $property), [
            'platforms' => ['facebook'], 'copy' => 'Lovely home for sale',
        ])->assertOk()->assertJsonPath('results.facebook.ok', true);

        $post = PropertyMarketingPost::withoutGlobalScopes()->sole();
        $this->assertSame('published', $post->status);
        $this->assertNull($post->failure_reason);
    }

    /** @return array{0: User, 1: Property} */
    private function world(): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $agent  = User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => 'agent']);

        AgentSocialAccount::create([
            'agency_id' => $agency, 'user_id' => $agent->id, 'platform' => 'facebook',
            'platform_page_id' => '1000', 'platform_page_name' => 'Test Page',
            'access_token' => 'EAABsecrettokenvalue1234567890', 'token_expires_at' => now()->addDays(30), 'is_active' => true,
        ]);

        $property = Property::create([
            'agency_id' => $agency, 'branch_id' => $branch, 'agent_id' => $agent->id, 'title' => 'Test House',
            'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
        ]);
        DB::table('properties')->where('id', $property->id)->update(['compliance_snapshot_at' => now()]);

        return [$agent, $property->fresh()];
    }
}
