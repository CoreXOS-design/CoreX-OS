<?php

declare(strict_types=1);

namespace Tests\Unit\Communications;

use App\Services\Communications\OutboundIpDetector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2026-09-09 (cc5 review, real-attempt-honesty incident) — replaces a
 * hand-set config value the timeout/unreachable mail message used to name
 * confidently, correct on this one box by coincidence and wrong on any other
 * CoreX install with no way to tell the difference. These tests never make a
 * real network call — Http::fake() throughout — and prove: a successful
 * probe is trusted and cached, a failed probe honestly returns null instead
 * of guessing, multiple providers give real redundancy, and the negative
 * cache doesn't hammer a down provider on every call.
 */
final class OutboundIpDetectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('communications:outbound_public_ip');
    }

    public function test_a_successful_probe_returns_and_caches_the_ip(): void
    {
        Http::fake(['api.ipify.org*' => Http::response('91.99.130.85')]);

        $ip = (new OutboundIpDetector())->detect();

        $this->assertSame('91.99.130.85', $ip);
        $this->assertSame('91.99.130.85', Cache::get('communications:outbound_public_ip'), 'a successful detection must be cached');
    }

    public function test_a_cached_value_is_returned_without_a_second_http_call(): void
    {
        Cache::put('communications:outbound_public_ip', '10.0.0.1', 3600);
        Http::fake(); // any real request here would fail the assertion below

        $ip = (new OutboundIpDetector())->detect();

        $this->assertSame('10.0.0.1', $ip);
        Http::assertNothingSent();
    }

    public function test_falls_through_to_the_next_provider_when_the_first_fails(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response('', 500),
            'ifconfig.me/*' => Http::response('91.99.130.85'),
        ]);

        $ip = (new OutboundIpDetector())->detect();

        $this->assertSame('91.99.130.85', $ip);
    }

    /** The whole point of this class: a wrong confident IP is worse than none. */
    public function test_when_every_provider_fails_it_honestly_returns_null_not_a_guess(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response('', 500),
            'ifconfig.me/*' => Http::response('', 500),
            'icanhazip.com*' => Http::response('', 500),
        ]);

        $ip = (new OutboundIpDetector())->detect();

        $this->assertNull($ip);
    }

    /** A provider returning something that isn't an IP at all (an error page, HTML) must not be trusted as one. */
    public function test_a_non_ip_response_is_rejected_not_trusted(): void
    {
        Http::fake([
            'api.ipify.org*' => Http::response('<html>rate limited</html>'),
            'ifconfig.me/*' => Http::response('91.99.130.85'),
            'icanhazip.com*' => Http::response(''),
        ]);

        $ip = (new OutboundIpDetector())->detect();

        $this->assertSame('91.99.130.85', $ip, 'must skip the garbage response and use the next real provider');
    }

    public function test_a_total_failure_is_negatively_cached_so_it_does_not_hammer_every_call(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        (new OutboundIpDetector())->detect();

        Http::fake(); // if detect() re-probed now, ANY request would fail this
        $ip = (new OutboundIpDetector())->detect();

        $this->assertNull($ip);
        Http::assertNothingSent();
    }
}
