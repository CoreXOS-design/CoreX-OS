<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationUrlSnapshot;
use App\Models\User;
use App\Services\Presentations\UrlSnapshotService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $presentationId;

    protected function setUp(): void
    {
        parent::setUp();
        $user   = User::factory()->create();
        $branch = Branch::create(["name" => "Test", "code" => "TST"]);
        $presentation = Presentation::create([
            "branch_id"          => $branch->id,
            "created_by_user_id" => $user->id,
            "title"              => "Test",
            "status"             => "draft",
            "currency"           => "ZAR",
        ]);
        $this->presentationId = $presentation->id;
    }

    private function makeService(array $mockResponses): UrlSnapshotService
    {
        $mock    = new MockHandler($mockResponses);
        $handler = HandlerStack::create($mock);
        $client  = new Client(['handler' => $handler]);
        return new UrlSnapshotService($client);
    }

    // ── Successful fetch ──────────────────────────────────────────────────────

    public function test_successful_fetch_stores_snapshot(): void
    {
        $html    = '<html><body>Test page</body></html>';
        $service = $this->makeService([new Response(200, [], $html)]);

        $snapshot = $service->storeSnapshot($this->presentationId, 'https://example.com', 'article');

        $this->assertInstanceOf(PresentationUrlSnapshot::class, $snapshot);
        $this->assertSame(200, $snapshot->http_status);
        $this->assertSame($html, $snapshot->snapshot_html);
    }

    public function test_content_hash_is_sha256_of_body(): void
    {
        $html    = '<html><body>Hello</body></html>';
        $service = $this->makeService([new Response(200, [], $html)]);

        $snapshot = $service->storeSnapshot($this->presentationId, 'https://example.com', 'other');

        $this->assertSame(hash('sha256', $html), $snapshot->content_hash);
    }

    public function test_fetched_at_is_set(): void
    {
        $service  = $this->makeService([new Response(200, [], 'body')]);
        $snapshot = $service->storeSnapshot($this->presentationId, 'https://example.com', 'other');

        $this->assertNotNull($snapshot->fetched_at);
    }

    // ── Failed fetch ──────────────────────────────────────────────────────────

    public function test_failed_fetch_still_stores_row(): void
    {
        $exception = new ConnectException('timeout', new GuzzleRequest('GET', 'https://example.com'));
        $service   = $this->makeService([$exception]);

        $snapshot = $service->storeSnapshot($this->presentationId, 'https://example.com', 'other');

        $this->assertInstanceOf(PresentationUrlSnapshot::class, $snapshot);
        $this->assertNull($snapshot->http_status);
        $this->assertNull($snapshot->snapshot_html);
        $this->assertNull($snapshot->content_hash);
    }

    // ── Source type validation ────────────────────────────────────────────────

    public function test_invalid_source_type_throws(): void
    {
        $service = $this->makeService([]);
        $this->expectException(\InvalidArgumentException::class);
        $service->storeSnapshot($this->presentationId, 'https://example.com', 'invalid_type');
    }

    public function test_all_allowed_source_types_are_accepted(): void
    {
        foreach (UrlSnapshotService::ALLOWED_SOURCE_TYPES as $type) {
            $service  = $this->makeService([new Response(200, [], 'body')]);
            $snapshot = $service->storeSnapshot($this->presentationId, 'https://example.com', $type);
            $this->assertSame($type, $snapshot->source_type);
        }
    }

    // ── Fields stored correctly ───────────────────────────────────────────────

    public function test_presentation_id_stored_correctly(): void
    {
        $service  = $this->makeService([new Response(200, [], 'body')]);
        $snapshot = $service->storeSnapshot($this->presentationId, 'https://example.com', 'other');

        $this->assertSame($this->presentationId, $snapshot->presentation_id);
    }

    public function test_url_stored_correctly(): void
    {
        $url     = 'https://example.com/property/123';
        $service = $this->makeService([new Response(200, [], 'body')]);
        $snapshot = $service->storeSnapshot($this->presentationId, $url, 'p24_listing');

        $this->assertSame($url, $snapshot->url);
    }

    // ── Headless strict mode (no Guzzle fallback) ─────────────────────────────

    public function test_headless_flag_on_and_service_down_stores_unreachable(): void
    {
        // Enable flag, point service URL to a port nothing listens on
        config([
            'features.portal_headless_fetch_v1' => true,
            'services.portal_fetch.url'         => 'http://127.0.0.1:19999',
        ]);

        $service  = $this->makeService([]); // Guzzle mock is unused because headless path runs its own client
        $snapshot = $service->storeSnapshot($this->presentationId, 'https://www.property24.com/test', 'p24_listing');

        $this->assertSame('headless_service_unreachable', $snapshot->blocked_reason);
        $this->assertSame(0, $snapshot->http_status);
        $this->assertNull($snapshot->snapshot_html);
    }

    public function test_headless_flag_off_uses_guzzle_normally(): void
    {
        config(['features.portal_headless_fetch_v1' => false]);

        // Body must be >= 500 bytes to avoid the small-body retry path
        $html    = '<html><body>' . str_repeat('x', 600) . '</body></html>';
        $service = $this->makeService([new Response(200, ['Content-Type' => 'text/html'], $html)]);
        $snapshot = $service->storeSnapshot($this->presentationId, 'https://www.property24.com/test', 'p24_listing');

        $this->assertSame(200, $snapshot->http_status);
        $this->assertSame($html, $snapshot->snapshot_html);
        $this->assertNull($snapshot->blocked_reason);
    }
}
