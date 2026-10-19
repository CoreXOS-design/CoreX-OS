<?php

namespace App\Http\Middleware;

use App\Models\ClientUser;
use App\Models\RentalPortalSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md §7 — AT-445. `tenant`/`landlord`
 * portal access is an independent agency on/off switch, default on. Runs
 * after auth:sanctum + client.ability, so $request->user() is always a
 * ClientUser here.
 */
class EnsureRentalPortalEnabled
{
    public function handle(Request $request, Closure $next, string $audience): Response
    {
        /** @var ClientUser $client */
        $client = $request->user();
        $agencyId = $client?->activeAgencyId();

        // The on/off switch belongs to an AGENCY, so it can only be judged once the agency is known. With none chosen yet the person is asked to choose
        // (the same answer every portal call gives) instead of passing on the built-in default and meeting the switch only on a later call
        // (audit 2026-10-19 D7). A login that is on exactly one agency, or locked to one, is adopted into it first - exactly as the call itself would.
        if (! $agencyId && $client) {
            $auth = app(\App\Services\ClientAuthService::class);
            $agencyId = $auth->defaultAgencyIdFor($client);
            if ($agencyId) {
                $client->chooseAgency($agencyId);
            }
        }
        if (! $agencyId) {
            // on no agency at all (the agency has not given this person portal access - audit D1) say so; on several, ask them to choose
            if ($client && app(\App\Services\ClientAuthService::class)->agenciesFor($client) === []) {
                return response()->json(['message' => 'Your agency has not given you portal access yet. Please ask your agent to set it up for you.', 'code' => 'no_portal_access'], 403);
            }

            return response()->json(['message' => 'Select an agency first.'], 409);
        }

        $enabled = $audience === 'landlord'
            ? RentalPortalSetting::landlordPortalEnabledFor($agencyId)
            : RentalPortalSetting::tenantPortalEnabledFor($agencyId);

        if (!$enabled) {
            return response()->json(['message' => 'This portal is not available for your agency.'], 403);
        }

        return $next($request);
    }
}
