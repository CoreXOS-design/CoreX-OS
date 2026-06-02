<?php

namespace App\Services\AgencyApi;

use App\Models\AgencyApiKey;
use Illuminate\Http\Request;

/**
 * Resolves a presented website-API bearer token to its AgencyApiKey.
 *
 * Token format: "<key_prefix>.<secret>". We look the key up by its public
 * prefix, then constant-time verify the secret against the stored hash, then
 * confirm the key is active (not revoked / not expired). Returns null for any
 * failure so the guard yields a clean 401.
 *
 * Lookup runs before authentication, so Auth::user() is null and the
 * BelongsToAgency global scope is a no-op — the prefix lookup is unscoped,
 * which is correct (we don't yet know the tenant). Once the guard sets this
 * key as the principal, AgencyScope filters every subsequent query to the
 * key's agency.
 *
 * Spec: .ai/specs/agency-public-api.md §3.4
 */
class AgencyApiKeyResolver
{
    public function resolveFromRequest(Request $request): ?AgencyApiKey
    {
        $bearer = $request->bearerToken();

        return $bearer ? $this->resolve($bearer) : null;
    }

    public function resolve(string $token): ?AgencyApiKey
    {
        if (!str_contains($token, '.')) {
            return null;
        }

        [$prefix, $secret] = explode('.', $token, 2);
        if ($prefix === '' || $secret === '') {
            return null;
        }

        $key = AgencyApiKey::query()->where('key_prefix', $prefix)->first();

        if (!$key || !$key->verifySecret($secret) || !$key->isActive()) {
            return null;
        }

        return $key;
    }
}
