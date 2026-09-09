<?php

declare(strict_types=1);

namespace App\Services\Communications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 2026-09-09 (cc5 review, real-attempt-honesty incident) — the timeout/
 * unreachable mail message used to name this server's outbound IP from a
 * config value someone set by hand (COMMUNICATIONS_OUTBOUND_PUBLIC_IP).
 * Correct on this one box today, by coincidence of who set it — but CoreX is
 * a product other agencies self-host, and a message that confidently states
 * a WRONG IP is worse than saying nothing: it sends an agency admin to their
 * mail host with a bad number and burns their credibility with that
 * provider. "This server's outbound address is X" must mean we checked, not
 * that someone once typed X into an .env file.
 *
 * Detects the real address the box's own outbound connections carry, via a
 * small set of independent public echo services (same class of check used
 * to diagnose the Afrihost block itself — see the banner-only diagnostic
 * from this incident). Cached, because this is not a per-message lookup a
 * mail-failure screen should pay for: the egress IP of a stable server does
 * not change minute to minute, and hammering a third-party echo service on
 * every failed Test Connection would be its own bad citizen behaviour.
 *
 * Honesty on failure is the whole point of this class: if every probe fails
 * (offline sandbox, egress firewall to these specific services, etc.),
 * detect() returns null and the CALLER must say "could not be determined" —
 * never fall back to guessing or reusing a stale/wrong value.
 */
class OutboundIpDetector
{
    private const CACHE_KEY = 'communications:outbound_public_ip';

    /** Egress IP on a stable server is effectively static — cache success generously. */
    private const SUCCESS_TTL_SECONDS = 86400;

    /** Don't hammer probes on repeated failure, but retry soon rather than staying dark for a full day. */
    private const FAILURE_TTL_SECONDS = 300;

    /**
     * Independent providers, plain-text response, no API key. Multiple
     * providers so one outage doesn't produce a false "could not determine".
     */
    private const PROBES = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com',
    ];

    /** @return string|null the real outbound public IP, or null if it genuinely could not be determined. */
    public function detect(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            // '' is the negative-cache sentinel — Cache::get() can't distinguish
            // "no such key" from "cached value is empty string" otherwise.
            return $cached === '' ? null : $cached;
        }

        foreach (self::PROBES as $url) {
            try {
                $response = Http::timeout(4)->get($url);
                $ip = trim((string) $response->body());
                if ($response->successful() && filter_var($ip, FILTER_VALIDATE_IP)) {
                    Cache::put(self::CACHE_KEY, $ip, self::SUCCESS_TTL_SECONDS);

                    return $ip;
                }
            } catch (\Throwable $e) {
                continue; // try the next independent probe
            }
        }

        Log::warning('OutboundIpDetector: could not determine this server\'s outbound public IP from any probe — every provider failed or returned something unparseable.');
        Cache::put(self::CACHE_KEY, '', self::FAILURE_TTL_SECONDS);

        return null;
    }

    /** For an admin action ("re-check now") or a deploy step that wants a fresh read, not the cached one. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
