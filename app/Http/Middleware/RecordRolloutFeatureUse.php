<?php

namespace App\Http\Middleware;

use App\Services\FeatureRollout\FeatureRolloutUsage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-478 §12.6–12.8 — note the first time a person opens a feature their agency's gradual rollout switched
 * on, so the admin and the CoreX team can see whether what was released is being used.
 *
 * Global (after EnforceFeatureRoutes) for the same reason that one is: a route list to remember goes stale.
 * Cheap by design: page loads (GET) by a signed-in user only; one cached lookup for an agency with no
 * rollout; at most one write per person per feature ever (memoised in the session). It can never break a
 * page — any failure is logged and the request carries on.
 */
class RecordRolloutFeatureUse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user !== null && $request->isMethod('GET') && ! $request->expectsJson() && $response->getStatusCode() < 400) {
            try {
                app(FeatureRolloutUsage::class)->recordVisit($user, $request->route()?->getName(), $request->path());
            } catch (\Throwable $e) {
                Log::warning('Feature rollout usage not recorded', ['error' => $e->getMessage()]);
            }
        }

        return $response;
    }
}
