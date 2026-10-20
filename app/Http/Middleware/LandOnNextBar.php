<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rentals-faults-work-orders.md §22.15 / rental-work-orders.md §17.40 (AT-464) — "after an action, land on the Next bar".
 *
 * One mechanism for the whole class: when a posted action (POST/PUT/PATCH/DELETE) answers with a redirect back to the fault report,
 * work order or job card screen, flash `land_on_next`. The layout's scroll script (layouts/_scroll-restore) then puts the page on the
 * element marked `data-land-on-next` - the screen's top status / Next bar - and briefly highlights it, INSTEAD of restoring the
 * old scroll position (which left Johan at the bottom of the fault report, looking at the form he had just used).
 *
 * Deliberately NOT flashed when the action's own result needs a different place:
 *   - the redirect carries a #fragment (an explicit anchor wins), or asks for the raise-work-order form (`create_work_order`);
 *   - it carries errors or an `error` message (a refused action or a failed validation: the page shows why, where it belongs);
 *   - the controller already flashed an explicit focus (`jc_focus_line` / `jc_focus_task`), or set `land_on_next` itself
 *     (`->with('land_on_next', false)` opts one redirect out).
 * No controller needs to change: a new action on any of the three screens gets this for free.
 */
class LandOnNextBar
{
    private const SCREEN = '#^/corex/rental-(?:fault-reports|work-orders|job-cards)/\d+/?$#';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || ! $response instanceof RedirectResponse || ! $request->hasSession()) {
            return $response;
        }

        $target = parse_url($response->getTargetUrl());
        if (! is_array($target) || isset($target['fragment']) || ! preg_match(self::SCREEN, (string) ($target['path'] ?? ''))) {
            return $response;
        }
        parse_str((string) ($target['query'] ?? ''), $query);
        if (isset($query['create_work_order'])) {
            return $response;
        }

        $session = $request->session();
        $errors = $session->get('errors');
        if (($errors && method_exists($errors, 'any') && $errors->any()) || $session->has('error') || $session->has('land_on_next')) {
            return $response;
        }
        foreach (array_keys($session->all()) as $key) {
            if (is_string($key) && str_starts_with($key, 'jc_focus')) {
                return $response;
            }
        }

        $session->flash('land_on_next', true);

        return $response;
    }
}
