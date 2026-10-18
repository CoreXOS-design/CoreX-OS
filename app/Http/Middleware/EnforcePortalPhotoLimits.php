<?php

namespace App\Http\Middleware;

use App\Models\ClientUser;
use App\Models\RentalPortalSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md §22 — the agency's fault-photo limits (count and size per photo) applied to the portal
 * forms that carry photos. The page checks the same numbers before sending; this is the server saying no when somebody
 * (or an old page) sends more. Plain-language 422, nothing stored.
 */
class EnforcePortalPhotoLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();
        $files = $request->file('photos');

        if (!$client instanceof ClientUser || !$files) {
            return $next($request);
        }

        $files = is_array($files) ? $files : [$files];
        $agencyId = $client->current_agency_id ? (int) $client->current_agency_id : null;
        $maxCount = RentalPortalSetting::faultPhotoMaxCountFor($agencyId);
        $maxMb = RentalPortalSetting::faultPhotoMaxMbFor($agencyId);

        if (count($files) > $maxCount) {
            $msg = "You can add up to {$maxCount} " . ($maxCount === 1 ? 'photo' : 'photos') . ' — please remove ' . (count($files) - $maxCount) . '.';

            return response()->json(['message' => $msg, 'errors' => ['photos' => [$msg]]], 422);
        }

        foreach ($files as $file) {
            if ($file && $file->isValid() && $file->getSize() > $maxMb * 1024 * 1024) {
                $msg = "One of the photos is larger than {$maxMb} MB — please choose a smaller one.";

                return response()->json(['message' => $msg, 'errors' => ['photos' => [$msg]]], 422);
            }
        }

        return $next($request);
    }
}
