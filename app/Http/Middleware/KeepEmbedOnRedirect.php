<?php

namespace App\Http\Middleware;

use App\Support\Embed;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Road to Sold pop-up (road-to-sold.md 22.9 / 22.23): a screen opened in the pop-up stays in the pop-up when it redirects.
 *
 * A redirect that goes to another screen of this site gets the pop-up's own parameters (embed, rts_prospect, rts_stage) added when the
 * address does not already carry them, so the next screen is chrome-less with its Done bar even in a browser that does not say it is
 * framed. It touches only the Location header of a same-site redirect, only while embed mode is on; it grants nothing.
 */
class KeepEmbedOnRedirect
{
    private const CARRY = ['embed', 'rts_prospect', 'rts_stage'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof RedirectResponse || ! Embed::on()) {
            return $response;
        }

        $target = (string) $response->getTargetUrl();
        $parts = parse_url($target);
        if ($parts === false || (isset($parts['host']) && $parts['host'] !== $request->getHost())) {
            return $response;
        }

        parse_str($parts['query'] ?? '', $query);
        if (isset($query['signature'])) {
            return $response; // a signed address would stop verifying if a parameter were added
        }
        $carry = ['embed' => '1'] + array_filter($request->only(['rts_prospect', 'rts_stage']), fn ($v) => is_string($v) && $v !== '');
        $add = array_diff_key($carry, $query);
        if ($add === []) {
            return $response;
        }

        $base = (isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : '') . ($parts['path'] ?? '');
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        $response->setTargetUrl($base . '?' . http_build_query($query + $add) . $fragment);

        return $response;
    }
}
