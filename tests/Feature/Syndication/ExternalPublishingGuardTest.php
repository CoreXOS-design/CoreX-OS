<?php

declare(strict_types=1);

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\User;
use App\Services\Marketing\ScheduledPostPublisher;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertyTokenService;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Support\ExternalPublishingGuard;
use App\Support\ExternalPublishingRefused;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Release audit 2026-10-18, C-F1 + C-F4 - ONE guard (ExternalPublishingGuard) in front of every WRITE to Meta,
 * Property24 and Private Property. Allowed by default (the real live server is unchanged); refused on a demo instance
 * or when COREX_EXTERNAL_PUBLISHING is false. Reads are never gated.
 *
 * "Nothing was sent" is proved at the transport: a Guzzle mock queue for Meta (untouched, no request recorded),
 * Http::fake for Property24 (assertNothingSent), a mocked SoapClient for Private Property (__soapCall never called).
 */
final class ExternalPublishingGuardTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $metaQueue;

    /** @var list<string> */
    private array $metaRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));
        Storage::fake('public');
        config(['services.property24_syndication.api_url' => 'https://p24.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ── the switch itself ───────────────────────────────────────────────────

    public function test_publishing_is_allowed_by_default(): void
    {
        $this->assertTrue(ExternalPublishingGuard::isAllowed());
        $this->assertNull(ExternalPublishingGuard::refusalReason());
    }

    public function test_publishing_is_refused_on_a_demo_instance(): void
    {
        config(['corex.instance.role' => 'demo']);

        $this->assertFalse(ExternalPublishingGuard::isAllowed());
        $this->assertSame('demo instance', ExternalPublishingGuard::refusalReason());
    }

    public function test_publishing_is_refused_when_the_config_value_is_false(): void
    {
        config(['corex.external_publishing' => false]);
        $this->assertFalse(ExternalPublishingGuard::isAllowed());

        config(['corex.external_publishing' => 'false']);
        $this->assertFalse(ExternalPublishingGuard::isAllowed());
    }

    public function test_a_garbled_value_never_switches_the_real_server_off(): void
    {
        config(['corex.external_publishing' => 'banana']);

        $this->assertTrue(ExternalPublishingGuard::isAllowed());
    }

    // ── Meta ────────────────────────────────────────────────────────────────

    public function test_a_due_post_is_published_when_publishing_is_allowed(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent);
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent));
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'p1', 'post_id' => 'PAGE_1']))]);

        $out = app(ScheduledPostPublisher::class)->runDue();

        $this->assertSame(1, $out['published']);
        $this->assertSame('published', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
        $this->assertCount(1, $this->metaRequests);
    }

    public function test_a_refused_scheduled_post_is_marked_failed_with_a_reason_and_never_sent_to_meta(): void
    {
        config(['corex.external_publishing' => false]);
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent);
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent));
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'p1', 'post_id' => 'PAGE_1']))]);

        $out = app(ScheduledPostPublisher::class)->runDue();

        $this->assertSame(1, $out['failed']);
        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertSame(ExternalPublishingGuard::userMessage(), $post->failure_reason);
        $this->assertSame([], $this->metaRequests, 'nothing reached Meta');
        $this->assertSame(1, $this->metaQueue->count(), 'the faked Meta response was never consumed');

        // Switching publishing on later must NOT publish the refused post by surprise.
        config(['corex.external_publishing' => true]);
        $again = app(ScheduledPostPublisher::class)->runDue();
        $this->assertSame(0, $again['published']);
        $this->assertSame('failed', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
        $this->assertSame([], $this->metaRequests);
    }

    public function test_a_refused_scheduled_post_on_a_demo_instance_is_marked_failed(): void
    {
        config(['corex.instance.role' => 'demo']);
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent);
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent));
        $this->fakeMeta([]);

        app(ScheduledPostPublisher::class)->runDue();

        $this->assertSame('failed', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
        $this->assertSame([], $this->metaRequests);
    }

    public function test_the_direct_publish_methods_refuse_before_any_http_call(): void
    {
        config(['corex.external_publishing' => false]);
        [, , $agent] = $this->world();
        $account = $this->connect($agent);
        $this->fakeMeta([]);
        $service = app(MetaPublishingService::class);

        foreach (['facebook' => fn () => $service->publishToFacebook($account, 'hi', ['https://x.test/a.jpg']),
                  'instagram' => fn () => $service->publishToInstagram($account, 'hi', ['https://x.test/a.jpg'])] as $call) {
            try {
                $call();
                $this->fail('Expected the post to be refused.');
            } catch (ExternalPublishingRefused $e) {
                $this->assertSame(ExternalPublishingGuard::userMessage(), $e->getMessage());
            }
        }

        $this->assertSame([], $this->metaRequests);
    }

    // ── Property24 ──────────────────────────────────────────────────────────

    public function test_a_property24_write_is_sent_when_publishing_is_allowed(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json'])]);

        $result = $this->p24()->createAgent(['name' => 'A']);

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/agents'));
    }

    public function test_property24_writes_are_refused_with_one_warning_and_no_http_call(): void
    {
        config(['corex.external_publishing' => false]);
        Http::fake();
        Log::spy();
        $client = $this->p24();

        foreach ([
            $client->setListingStatus(55, 123456, 'BackOnMarket'),
            $client->saveListing(55, ['x' => 1]),
            $client->createAgent(['name' => 'A']),
            $client->updateAgent(['name' => 'A']),
            $client->uploadAgentPhoto(9, ['x' => 1]),
        ] as $result) {
            $this->assertFalse($result['success']);
            $this->assertTrue($result['refused']);
        }

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m) => str_contains((string) $m, 'External publishing refused (property24)')
                && str_contains((string) $m, 'status_update') && str_contains((string) $m, '55'))
            ->once();
    }

    public function test_property24_reads_still_go_through_when_publishing_is_refused(): void
    {
        config(['corex.external_publishing' => false]);
        Http::fake(['*' => Http::response(['data' => []], 200, ['Content-Type' => 'application/json'])]);

        $result = $this->p24()->getCountries();

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
    }

    // ── Private Property ────────────────────────────────────────────────────

    public function test_a_private_property_write_is_sent_when_publishing_is_allowed(): void
    {
        $soap = Mockery::mock(\SoapClient::class);
        $soap->shouldReceive('__soapCall')->once()->andReturn((object) ['ok' => true]);
        $client = $this->pp($soap);

        $result = $client->setListingStatus('55', 'Rental', 'ToLet');

        $this->assertSame(['ok' => true], $result);
    }

    public function test_private_property_writes_are_refused_with_one_warning_and_no_soap_call(): void
    {
        config(['corex.external_publishing' => false]);
        $soap = Mockery::mock(\SoapClient::class);
        $soap->shouldNotReceive('__soapCall');
        Log::spy();
        $client = $this->pp($soap);

        foreach ([
            $client->setListingStatus('55', 'Rental', 'ToLet'),
            $client->deactivateListing('55', 'Rental'),
            $client->reactivateListing('55', 'Rental'),
            $client->updateListing(['PropertyId' => '55']),
            $client->updateAgent(['AgentId' => '1']),
        ] as $result) {
            $this->assertTrue($result['error']);
            $this->assertTrue($result['refused']);
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($m) => str_contains((string) $m, 'External publishing refused (private_property)') && str_contains((string) $m, 'ListingStatusUpdate'))
            ->times(3);
    }

    public function test_private_property_reads_still_go_through_when_publishing_is_refused(): void
    {
        config(['corex.external_publishing' => false]);
        $soap = Mockery::mock(\SoapClient::class);
        $soap->shouldReceive('__soapCall')->once()->andReturn((object) ['GetListingStatusResult' => 'Active']);
        $client = $this->pp($soap);

        $this->assertSame(['GetListingStatusResult' => 'Active'], $client->getListingStatus('55'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function p24(): Property24ApiClient
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid(), 'p24_agency_id' => '29159', 'p24_username' => 'u', 'p24_password' => 'p']);

        return new Property24ApiClient($agency);
    }

    private function pp(\SoapClient $soap): PrivatePropertySoapClient
    {
        $client = new PrivatePropertySoapClient(new PrivatePropertyTokenService());
        $ref = new \ReflectionProperty(PrivatePropertySoapClient::class, 'client');
        $ref->setAccessible(true);
        $ref->setValue($client, $soap);

        return $client;
    }

    /** @return array{0:int,1:int,2:User} */
    private function world(): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return [$agency, $branch, User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => 'agent'])];
    }

    private function property(int $agency, int $branch, User $agent): Property
    {
        $p = Property::create([
            'agency_id' => $agency, 'branch_id' => $branch, 'agent_id' => $agent->id, 'title' => 'Test House',
            'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
        ]);
        DB::table('properties')->where('id', $p->id)->update(['compliance_snapshot_at' => now()]);

        return $p->fresh();
    }

    private function connect(User $user): AgentSocialAccount
    {
        return AgentSocialAccount::create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id, 'platform' => 'facebook',
            'platform_page_id' => '1000' . $user->id, 'platform_page_name' => 'Test Page',
            'access_token' => 'EAABsecrettokenvalue1234567890', 'token_expires_at' => now()->addDays(30), 'is_active' => true,
        ]);
    }

    private function insertPost(int $agency, User $user, Property $property): int
    {
        $path = "marketing-scheduled/{$agency}/{$user->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return (int) DB::table('property_marketing_posts')->insertGetId([
            'agency_id' => $agency, 'property_id' => $property->id, 'user_id' => $user->id, 'platform' => 'facebook',
            'ad_copy' => 'Lovely home for sale', 'image_urls' => json_encode([Storage::disk('public')->url($path)]),
            'status' => 'scheduled', 'scheduled_for' => '2026-10-20 07:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<Response> $publish */
    private function fakeMeta(array $publish): void
    {
        $this->metaRequests = [];
        $this->metaQueue = new MockHandler($publish);
        $stack = HandlerStack::create($this->metaQueue);
        $stack->push(Middleware::mapRequest(function ($request) {
            $this->metaRequests[] = (string) $request->getUri();

            return $request;
        }));
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService(new Client(['handler' => $stack])));
        $this->app->instance(MetaOAuthService::class, new MetaOAuthService(new Client(['handler' => HandlerStack::create(new MockHandler([]))])));
    }
}
