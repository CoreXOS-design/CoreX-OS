<?php

declare(strict_types=1);

namespace App\Support\Net;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The one door for CoreX to POST to an agency-entered webhook address (SSRF guard). Spec: agency-public-api.md §6.4.
 *
 * Built on SafeFeedUrl (the same checks the article feeds use): http/https only, no user:password, and
 * the host must resolve to PUBLIC addresses only — loopback, private (RFC 1918), link-local
 * (incl. 169.254.169.254), CGNAT, multicast, reserved and the IPv6 equivalents are refused.
 *
 * Unlike feeds, ANY valid port is accepted: an agency's site receiver may legitimately listen on a custom
 * port (HFC's does). The public-address checks and the IP pin are what keep the post off CoreX's own
 * network; the feeds' 80/443-only rule silently failed every delivery to such a site from 2026-10-21
 * (website prices stopped updating on Refresh).
 *
 * It is used at three places so no route to the address is left unguarded: when the address is saved
 * (validation), when the "Test connection" button posts to it, and when the queued webhook delivery
 * posts to it. At send time the connection is pinned to the IP that was just checked (no second DNS
 * lookup that could be swapped for an internal address) and redirects are NOT followed, so a public
 * address cannot bounce the request to an internal one.
 */
class SafeWebhookUrl
{
    /** A plain-words problem with the address, or null when it is fine to post to. */
    public function error(string $url): ?string
    {
        $check = app(SafeFeedUrl::class)->inspect($url, allowedPorts: null);

        return $check['ok'] ? null : $this->reword((string) $check['error']);
    }

    /**
     * POST a body to the webhook address, signed by the caller's headers.
     *
     * @param array<string, string> $headers
     * @throws UnsafeWebhookUrlException when the address is refused (nothing is sent)
     */
    public function post(string $url, string $body, array $headers, int $timeoutSeconds = 10): Response
    {
        $check = app(SafeFeedUrl::class)->inspect($url, allowedPorts: null);
        if (! $check['ok']) {
            $why = $this->reword((string) $check['error']);

            throw new UnsafeWebhookUrlException($why, permanent: ! str_starts_with($why, 'We could not find'));
        }

        $options = [];
        if (! $check['literal']) {
            // Connect to exactly the address that was just checked.
            $options['curl'] = [CURLOPT_RESOLVE => ["{$check['host']}:{$check['port']}:{$check['ips'][0]}"]];
        }

        return Http::withHeaders($headers)
            ->timeout($timeoutSeconds)
            ->connectTimeout(min($timeoutSeconds, 10))
            ->withoutRedirecting()
            ->withOptions($options)
            ->withBody($body, 'application/json')
            ->post($url);
    }

    private function reword(string $message): string
    {
        return str_replace(
            ['cannot be used as a feed.', 'feeds that need a login are not supported.'],
            ['cannot be used as a webhook address.', 'webhook addresses that need a login in the link are not supported.'],
            $message,
        );
    }
}
