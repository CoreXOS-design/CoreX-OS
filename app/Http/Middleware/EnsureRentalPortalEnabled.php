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
        $agencyId = $client?->current_agency_id;

        $enabled = $audience === 'landlord'
            ? RentalPortalSetting::landlordPortalEnabledFor($agencyId)
            : RentalPortalSetting::tenantPortalEnabledFor($agencyId);

        if (!$enabled) {
            return response()->json(['message' => 'This portal is not available for your agency.'], 403);
        }

        return $next($request);
    }
}
