<?php

namespace App\Services\AgencyApi;

use App\Models\AgencyApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a presented website-API bearer token to its AgencyApiKey.
 *
 * Token format: "<key_prefix>.<secret>". We look the key up by its public
 * prefix, then constant-time verify the secret against the stored sha256 hash,
 * then confirm the key is active (not revoked / not expired). Returns null for
 * any failure so the guard yields a clean 401.
 *
 * Lookup runs before authentication, so Auth::user() is null and the
 * BelongsToAgency global scope is a no-op — the prefix lookup is unscoped,
 * which is correct (we don't yet know the tenant). Once the guard sets this
 * key as the principal, AgencyScope filters every subsequent query to the
 * key's agency.
 *
 * Diagnostics: set COREX_WEBSITE_API_AUTH_DEBUG=true to log the exact reason a
 * token is rejected (never the secret itself) — for debugging 401s in prod.
 *
 * Spec: .ai/specs/agency-public-api.md §3.4
 */
class AgencyApiKeyResolver
{
    public function resolveFromRequest(Request $request): ?AgencyApiKey
    {
        $bearer = $request->bearerToken();

        if (!$bearer) {
            return $this->reject('no_bearer_token', null);
        }

        return $this->resolve($bearer);
    }

    public function resolve(string $token): ?AgencyApiKey
    {
        if (!str_contains($token, '.')) {
            return $this->reject('malformed_token_no_dot', null);
        }

        [$prefix, $secret] = explode('.', $token, 2);
        if ($prefix === '' || $secret === '') {
            return $this->reject('malformed_token_empty_parts', $prefix);
        }

        $key = AgencyApiKey::query()->where('key_prefix', $prefix)->first();

        if (!$key) {
            return $this->reject('prefix_not_found', $prefix);
        }
        if (!$key->verifySecret($secret)) {
            return $this->reject('secret_mismatch', $prefix);
        }
        if ($key->isRevoked()) {
            return $this->reject('revoked', $prefix);
        }
        if ($key->isExpired()) {
            return $this->reject('expired', $prefix);
        }

        return $key;
    }

    /**
     * Log the rejection reason (when COREX_WEBSITE_API_AUTH_DEBUG is on) and
     * return null. The secret is NEVER logged — only the public prefix.
     */
    private function reject(string $reason, ?string $prefix): null
    {
        if (config('integrations.website_api_auth_debug')) {
            Log::warning('Website API auth rejected', [
                'reason'     => $reason,
                'key_prefix' => $prefix, // public part only — the secret is never logged
            ]);
        }

        return null;
    }
}
