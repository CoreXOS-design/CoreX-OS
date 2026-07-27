<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Exceptions\AI\SsrfBlockedException;
use App\Models\AI\EllieReferenceSource;
use App\Models\User;
use App\Services\AI\EllieReferenceSourceFetchService;
use App\Services\AI\EmbeddingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SSRF-guard coverage for the ONLY code path that ever fetches an Ellie
 * reference source over the network. These are the tests BUILD_STANDARD calls
 * "the important coverage" for this feature (.ai/specs/ellie-reference-sources.md
 * §12) — a mistake here is a real vulnerability, not a UX bug.
 *
 * DNS resolution is stubbed via a Mockery partial mock on the protected
 * resolveSafePublicIp() rather than depending on real, network-reachable DNS
 * inside the test run. The literal-IP tests (private/loopback/link-local)
 * exercise the REAL guard, unmocked, because filter_var() on a literal IP
 * never touches the network at all — that is the most security-critical path
 * and it is tested for real, not through a stub.
 *
 * Spec: .ai/specs/ellie-reference-sources.md §6, §11.
 */
final class EllieReferenceSourceFetchServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── Literal-IP guards (no mocking — the real guard, no network) ─────────

    public function test_loopback_ip_is_rejected(): void
    {
        $service = app(EllieReferenceSourceFetchService::class);

        $this->assertNotNull($service->validateAddable('http://127.0.0.1/rates'));
    }

    public function test_private_rfc1918_ip_is_rejected(): void
    {
        $service = app(EllieReferenceSourceFetchService::class);

        $this->assertNotNull($service->validateAddable('http://10.0.0.5/rates'));
        $this->assertNotNull($service->validateAddable('http://192.168.1.1/rates'));
        $this->assertNotNull($service->validateAddable('http://172.16.0.1/rates'));
    }

    public function test_link_local_metadata_ip_is_rejected(): void
    {
        $service = app(EllieReferenceSourceFetchService::class);

        // 169.254.169.254 — the cloud-provider instance metadata address. The
        // single most consequential SSRF target there is; must never pass.
        $this->assertNotNull($service->validateAddable('http://169.254.169.254/latest/meta-data/'));
    }

    public function test_ipv6_loopback_is_rejected(): void
    {
        $service = app(EllieReferenceSourceFetchService::class);

        $this->assertNotNull($service->validateAddable('http://[::1]/rates'));
    }

    public function test_localhost_hostname_is_rejected(): void
    {
        $service = app(EllieReferenceSourceFetchService::class);

        // Resolves via /etc/hosts, not a network DNS query — safe to run for real.
        $this->assertNotNull($service->validateAddable('http://localhost/rates'));
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $service = app(EllieReferenceSourceFetchService::class);

        $this->assertNotNull($service->validateAddable('file:///etc/passwd'));
        $this->assertNotNull($service->validateAddable('ftp://example.test/rates'));
    }

    public function test_a_normal_public_url_shape_passes_the_add_time_guard(): void
    {
        $service = app(EllieReferenceSourceFetchService::class);

        // validateAddable() does a real DNS resolution (see the class docblock
        // on resolveSafePublicIp for why it must), so this needs a hostname that
        // actually resolves — example.com is IANA-reserved specifically for
        // this and is guaranteed stable, unlike example.test (RFC 2606
        // "for documentation", deliberately non-resolving). No page fetch
        // happens here — validateAddable() only resolves, it never GETs.
        $this->assertNull($service->validateAddable('https://example.com/rates'));
    }

    // ── Fetch pipeline (DNS resolution stubbed, HTTP layer faked) ───────────

    public function test_redirect_to_a_private_target_is_refused(): void
    {
        Http::fake([
            'example.test/start' => Http::response('', 302, ['Location' => 'https://internal.test/secret']),
        ]);

        $service = $this->partialMockWithResolver([
            'example.test' => '203.0.113.10',
        ], blockedHosts: ['internal.test']);

        $source = $this->makeSource('https://example.test/start');
        $service->refresh($source);
        $source->refresh();

        $this->assertSame(EllieReferenceSource::STATUS_ERROR, $source->last_fetch_status);
        $this->assertSame(0, $source->chunks()->count());
    }

    public function test_oversized_response_is_refused(): void
    {
        Http::fake([
            'example.test/*' => Http::response(str_repeat('a', 6 * 1024 * 1024), 200, ['Content-Type' => 'text/html']),
        ]);

        $service = $this->partialMockWithResolver(['example.test' => '203.0.113.10']);

        $source = $this->makeSource('https://example.test/big');
        $service->refresh($source);
        $source->refresh();

        $this->assertSame(EllieReferenceSource::STATUS_ERROR, $source->last_fetch_status);
        $this->assertStringContainsString('size cap', (string) $source->fetch_error);
    }

    public function test_wrong_content_type_is_refused(): void
    {
        Http::fake([
            'example.test/*' => Http::response('%PDF-1.4 binary', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $service = $this->partialMockWithResolver(['example.test' => '203.0.113.10']);

        $source = $this->makeSource('https://example.test/doc');
        $service->refresh($source);
        $source->refresh();

        $this->assertSame(EllieReferenceSource::STATUS_ERROR, $source->last_fetch_status);
        $this->assertStringContainsString('content type', (string) $source->fetch_error);
    }

    public function test_a_successful_fetch_indexes_chunks_and_marks_ok(): void
    {
        $this->fakePageAndEmbeddings(
            'https://example.test/rates',
            '<html><head><title>Prime Rate Today</title></head><body>'
            . '<nav>skip me</nav>'
            . '<p>' . str_repeat('The current prime lending rate is 11.75 percent. ', 20) . '</p>'
            . '</body></html>'
        );

        $service = $this->partialMockWithResolver(['example.test' => '203.0.113.10']);

        $source = $this->makeSource('https://example.test/rates');
        $service->refresh($source);
        $source->refresh();

        $this->assertSame(EllieReferenceSource::STATUS_OK, $source->last_fetch_status);
        $this->assertGreaterThan(0, $source->chunks()->count());
        $this->assertSame('Prime Rate Today', $source->title);
        $this->assertTrue($source->chunks()->first()->has_embedding);
        $this->assertStringNotContainsString('skip me', $source->chunks()->first()->content);
    }

    public function test_unchanged_content_skips_re_embedding_on_refresh(): void
    {
        $html = '<html><head><title>Prime Rate</title></head><body><p>'
            . str_repeat('Stable content that never changes. ', 20) . '</p></body></html>';

        $this->fakePageAndEmbeddings('https://example.test/rates', $html);

        $service = $this->partialMockWithResolver(['example.test' => '203.0.113.10']);

        $source = $this->makeSource('https://example.test/rates');
        $service->refresh($source);
        $source->refresh();
        $firstChunkId = $source->chunks()->first()->id;

        Http::assertSentCount(2); // 1 page fetch + 1 embed call

        // Second refresh, identical content — must NOT re-hit the embed endpoint.
        $service->refresh($source);
        $source->refresh();

        Http::assertSentCount(3); // +1 page fetch only, no second embed call
        $this->assertSame($firstChunkId, $source->chunks()->first()->id);
    }

    public function test_a_failed_refresh_keeps_previously_indexed_chunks(): void
    {
        $html = '<html><head><title>Prime Rate</title></head><body><p>'
            . str_repeat('Content that will later become unreachable. ', 20) . '</p></body></html>';

        // A sequence, not two separate Http::fake() calls for the same pattern —
        // Http::fake() stubs are checked in REGISTRATION order and the first
        // match wins, so a second fake() for an already-faked pattern is
        // silently ignored rather than overriding it. A sequence is the
        // correct tool for "first call succeeds, second call fails".
        Http::fakeSequence('example.test/*')
            ->push($html, 200, ['Content-Type' => 'text/html'])
            ->push('Server Error', 500);
        Http::fake(['*/embed' => Http::response(['embeddings' => [array_fill(0, 384, 0.01)]], 200)]);

        $service = $this->partialMockWithResolver(['example.test' => '203.0.113.10']);

        $source = $this->makeSource('https://example.test/rates');
        $service->refresh($source);
        $source->refresh();
        $this->assertSame(EllieReferenceSource::STATUS_OK, $source->last_fetch_status);
        $chunkCountAfterSuccess = $source->chunks()->count();
        $this->assertGreaterThan(0, $chunkCountAfterSuccess);

        // Second refresh hits the sequence's next (500) response.
        $service->refresh($source);
        $source->refresh();

        $this->assertSame(EllieReferenceSource::STATUS_ERROR, $source->last_fetch_status);
        $this->assertSame($chunkCountAfterSuccess, $source->chunks()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function fakePageAndEmbeddings(string $url, string $html): void
    {
        Http::fake([
            $this->pattern($url) => Http::response($html, 200, ['Content-Type' => 'text/html']),
            '*/embed' => Http::response([
                'embeddings' => [array_fill(0, 384, 0.01)],
            ], 200),
        ]);
    }

    private function pattern(string $url): string
    {
        return preg_replace('#^https?://#', '', $url) . '*';
    }

    /**
     * A Mockery partial mock of the fetch service whose resolveSafePublicIp()
     * is stubbed per-host, so tests control DNS resolution deterministically
     * instead of depending on real network DNS.
     *
     * @param array<string, string> $resolves host => IP to return
     * @param array<int, string> $blockedHosts hosts that should throw as if
     *   they resolved to a private/reserved address
     */
    private function partialMockWithResolver(array $resolves, array $blockedHosts = []): EllieReferenceSourceFetchService
    {
        $mock = \Mockery::mock(EllieReferenceSourceFetchService::class, [app(EmbeddingService::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('resolveSafePublicIp')
            ->andReturnUsing(function (string $host) use ($resolves, $blockedHosts) {
                if (in_array($host, $blockedHosts, true)) {
                    throw new SsrfBlockedException("Host '{$host}' resolves only to a private, loopback or reserved address — refused.");
                }

                return $resolves[$host] ?? throw new SsrfBlockedException("Unexpected host in test: {$host}");
            });

        return $mock;
    }

    private function makeSource(string $url): EllieReferenceSource
    {
        $agencyId = (int) \Illuminate\Support\Facades\DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . \Illuminate\Support\Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::factory()->create(['agency_id' => $agencyId, 'role' => 'super_admin']);

        return EllieReferenceSource::create([
            'url' => $url,
            'added_by_user_id' => $user->id,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }
}
