<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-149 — authenticate inbound WAHA webhooks. NEVER accept an unauthenticated
 * POST (a spoofed webhook would inject fabricated messages into the compliance
 * archive).
 *
 * Two accepted mechanisms, both keyed off the SAME configured secret
 * (`communications.waha.webhook_secret`):
 *   1. HMAC (WAHA-native, preferred — signs the body): WAHA sends
 *      `X-Webhook-Hmac` = hash_hmac(algo, rawBody, secret) with the algorithm in
 *      `X-Webhook-Hmac-Algorithm` (default sha512). Verified constant-time.
 *   2. Shared secret header (`X-Webhook-Secret`, or `Authorization: Bearer`) —
 *      for WAHA setups configured with a custom header instead of HMAC.
 *
 * FAIL CLOSED: if no secret is configured, or neither mechanism verifies, the
 * request is refused with 401 — it never reaches the ingestor.
 */
class VerifyWahaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('communications.waha.webhook_secret', '');
        if ($secret === '') {
            Log::warning('AT-149 WAHA webhook refused: no webhook secret configured (fail closed)');
            return response()->json(['error' => 'Webhook not configured'], 401);
        }

        if ($this->hmacVerifies($request, $secret) || $this->sharedSecretVerifies($request, $secret)) {
            return $next($request);
        }

        Log::warning('AT-149 WAHA webhook refused: authentication failed', [
            'ip'        => $request->ip(),
            'has_hmac'  => $request->hasHeader('X-Webhook-Hmac'),
        ]);

        return response()->json(['error' => 'Unauthenticated'], 401);
    }

    /** WAHA-native HMAC of the raw request body. */
    private function hmacVerifies(Request $request, string $secret): bool
    {
        $provided = (string) $request->header('X-Webhook-Hmac', '');
        if ($provided === '') {
            return false;
        }

        $algo = strtolower((string) ($request->header('X-Webhook-Hmac-Algorithm')
            ?: config('communications.waha.webhook_hmac_algo', 'sha512')));
        if (! in_array($algo, hash_hmac_algos(), true)) {
            return false;
        }

        $expected = hash_hmac($algo, $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }

    /** Shared secret carried in a custom header (X-Webhook-Secret or Bearer). */
    private function sharedSecretVerifies(Request $request, string $secret): bool
    {
        $candidate = (string) ($request->header('X-Webhook-Secret') ?: $request->bearerToken() ?: '');

        return $candidate !== '' && hash_equals($secret, $candidate);
    }
}
