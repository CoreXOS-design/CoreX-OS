<?php

namespace App\Http\Middleware;

use App\Services\Activity\ActivityPointsService;
use App\Services\RoadToSold\Scope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-454 v2 phase 3 - the old daily-activity screens (capture, summaries, definitions setup) are replaced by Road to Sold for an agency
 * that has been cut over (road-to-sold.md section 7.7): the old address answers with a redirect to the new home and the old route names
 * keep resolving, so no link in CoreX breaks. An agency that has NOT been cut over keeps the old screens exactly as they were, and a person
 * who cannot open Road to Sold is not sent to a page that refuses them - so the redirect is 302, not 301 (the same address means
 * different things for different agencies and people, so a browser must never remember it).
 *
 * Usage: ->middleware('daily.moved:capture|summary|team|setup')
 */
class DailyActivityMovedToRoadToSold
{
    public function handle(Request $request, Closure $next, string $kind = 'capture'): Response
    {
        $user = $request->user();
        $agencyId = $user ? (int) ($user->effectiveAgencyId() ?: 0) : 0;

        if ($agencyId <= 0 || ! ActivityPointsService::usesCompletions($agencyId)
            || ! $user->hasPermission('access_road_to_sold') || ! Scope::callListOn($user)) {
            return $next($request);
        }
        // The Auto tab (when an automatic credit is earned) is still configured on the old Setup screen.
        if ($kind === 'setup' && $request->query('tab') === 'auto') {
            return $next($request);
        }

        $target = match ($kind) {
            'summary' => route('road-to-sold.index', ['view' => 'captures']),
            'team'    => route('road-to-sold.index', ['view' => 'team']),
            'setup'   => $user->hasPermission('road_to_sold.manage_recipe') && \Illuminate\Support\Facades\Route::has('road-to-sold.settings.recipe.index')
                ? route('road-to-sold.settings.recipe.index') : route('road-to-sold.index'),
            default   => route('road-to-sold.index'),
        };

        return redirect($target)->with('status', 'Daily activity now lives on Road to Sold.');
    }
}
