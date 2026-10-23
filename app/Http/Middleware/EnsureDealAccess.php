<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\AuthorizesDealAccess;
use App\Http\Controllers\Concerns\AuthorizesDealV2Access;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The deal-belongs-to-you check for EVERY Deal Register (deals-dr2.*) route that names a deal.
 *
 * Why a middleware and not a line in each action: route-model binding only applies the agency scope,
 * never the within-agency own / branch / all scope (Deal::scopeVisibleTo is a local scope), so a user
 * holding a deal permission could open or change ANY deal in their agency by typing its id. The old
 * register guarded each action by hand (AuthorizesDealAccess); the Deal Register guarded only some, and
 * a hand-written line per action is how the rest were missed. Applied once to the route group it covers
 * every action that takes a {deal} (or the {dealV2} twin) today and any added later (AT-454 step 6).
 *
 * Breadth: a read (GET / HEAD) is checked at the viewer's data scope; a write pins an assistant to the
 * assigned agent's own deals (same rule as the old register: an assistant SEES what the agent sees,
 * only EDITS the agent's own). A route with no deal in it passes straight through.
 */
class EnsureDealAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $forEdit = ! $request->isMethodSafe();

        if ($route && $route->hasParameter('deal')) {
            $deal = $route->parameter('deal');
            if (! $deal instanceof Deal) {
                $deal = Deal::query()->findOrFail($deal);
            }
            $this->checker()->check($deal, $forEdit);
        } elseif ($route && $route->hasParameter('dealV2')) {
            $twin = $route->parameter('dealV2');
            if (! $twin instanceof DealV2) {
                $twin = DealV2::query()->findOrFail($twin);
            }
            // A twin made from a register deal is judged by that deal, so the register and the pipeline
            // can never disagree about who owns it; a twin with no register deal by its own agent columns.
            $legacy = $twin->legacy_deal_id ? Deal::query()->find($twin->legacy_deal_id) : null;
            if ($legacy) {
                $this->checker()->check($legacy, $forEdit);
            } else {
                $this->twinChecker()->check($twin, $forEdit);
            }
        }

        return $next($request);
    }

    private function checker(): object
    {
        return new class {
            use AuthorizesDealAccess;

            public function check(Deal $deal, bool $forEdit): void
            {
                $this->authorizeDeal($deal, $forEdit);
            }
        };
    }

    private function twinChecker(): object
    {
        return new class {
            use AuthorizesDealV2Access;

            public function check(DealV2 $deal, bool $forEdit): void
            {
                $this->authorizeDealV2($deal, $forEdit);
            }
        };
    }
}
