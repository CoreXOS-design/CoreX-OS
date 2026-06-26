<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Support\Tours\TourRegistry;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

/**
 * Guided Tours directory (AT-41) — the agent's self-serve training index.
 *
 * Lists every registered interactive tour the current user can access (gated by
 * TourRegistry::visibleTo + the tour's own route). Each card links to the screen
 * the tour runs on with ?tour=<key>, which force-starts the tour on arrival
 * (see layouts/partials/tour-engine.blade.php) even if it's been seen before.
 *
 * This replaces "call Johan/Andre to show me how" — agents train themselves.
 * Spec: .ai/specs/whatsapp-outreach-summary.md sibling; tour engine in
 * App\Support\Tours\TourRegistry.
 */
class GuidedToursController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        $tours = collect(TourRegistry::all())
            ->filter(function ($tour) use ($user) {
                // First honour any explicit `permission` the tour declares.
                if (! TourRegistry::visibleTo($tour, $user)) {
                    return false;
                }

                // System Owners see every tour (mirrors TourRegistry::visibleTo).
                if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
                    return true;
                }

                // Then enforce the tour route's OWN access gate. The directory
                // is the one place a tour is listed without first passing
                // through its route's middleware, so a tour whose screen the
                // user can't open (e.g. the Deal Register for an agent without
                // deals_v2.view) must not appear here either.
                return $this->userCanAccessRoute($tour['route'] ?? null, $user);
            })
            ->map(function ($tour) {
                // Resolve the launch URL safely. Routes that need parameters
                // (e.g. the contact-scoped outreach composer) can't be linked
                // generically — flag those so the UI explains where to go.
                $routeName = $tour['route'] ?? null;
                $url = null;
                $needsContext = false;

                if ($routeName && RouteFacade::has($routeName)) {
                    try {
                        $url = route($routeName, [], false) . '?tour=' . urlencode($tour['key']);
                    } catch (\Throwable $e) {
                        $needsContext = true; // route requires a bound model
                    }
                }

                return [
                    'key'          => $tour['key'],
                    'title'        => $tour['title'] ?? $tour['key'],
                    'description'  => $tour['description'] ?? null,
                    'steps'        => count($tour['steps'] ?? []),
                    'url'          => $url,
                    'needsContext' => $needsContext,
                    'group'        => static::groupFor($tour['key']),
                ];
            })
            ->sortBy('title')
            ->groupBy('group')
            // Stable, human order for the section headings.
            ->sortBy(fn ($rows, $group) => array_search($group, static::GROUP_ORDER, true) === false
                ? 999
                : array_search($group, static::GROUP_ORDER, true));

        return view('corex.guided-tours.index', ['groups' => $tours]);
    }

    /**
     * Does this user satisfy every `permission:<key>` middleware on the tour's
     * route? That middleware (App\Http\Middleware\CheckPermission) is CoreX's
     * standard route gate — it aborts 403 unless $user->hasPermission($key).
     * We replay the same check so the directory never lists a tour for a screen
     * the user would be bounced out of. A tour with no resolvable route, or a
     * route carrying no permission middleware, is treated as accessible (the
     * route is its own gate, and there is none to fail).
     */
    private function userCanAccessRoute(?string $routeName, $user): bool
    {
        if (! $routeName || ! RouteFacade::has($routeName)) {
            // No (or unresolvable) route — nothing to gate on. Visibility is
            // then governed solely by the tour's explicit `permission` key,
            // already checked above.
            return true;
        }

        $route = RouteFacade::getRoutes()->getByName($routeName);
        if (! $route) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! Str::startsWith($middleware, 'permission:')) {
                continue;
            }

            // 'permission:deals_v2.view' → 'deals_v2.view' (CheckPermission
            // takes a single key; ignore any trailing args defensively).
            $key = Str::before(Str::after($middleware, 'permission:'), ',');

            if (! (method_exists($user, 'hasPermission') && $user->hasPermission($key) === true)) {
                return false;
            }
        }

        return true;
    }

    /** Display order of the directory's category sections. */
    private const GROUP_ORDER = [
        'Dashboard & Command Centre',
        'Real Estate',
        'Market Intelligence',
        'Presentations',
        'Deals',
        'Rentals',
        'Compliance',
        'Documents & E-Sign',
        'Communication',
        'My Portal',
        'Earnings',
        'Agency Tracker',
        'Tools & Calculators',
        'Training & AI',
        'Other',
    ];

    /**
     * Map a tour key to its directory category. New AT-41 packs use namespaced
     * key prefixes; the hand-authored core tours are mapped explicitly. Keeps
     * the directory grouped without a per-tour 'group' field to maintain.
     */
    private static function groupFor(string $key): string
    {
        static $core = [
            'contact-capture'   => 'Real Estate',
            'property-capture'   => 'Real Estate',
            'buyer-pipeline'     => 'Real Estate',
            'mic-work'           => 'Market Intelligence',
            'presentation-create' => 'Presentations',
            'deals-register'     => 'Deals',
            'fica-capture'       => 'Compliance',
            'documents-library'  => 'Documents & E-Sign',
            'esign-wizard'       => 'Documents & E-Sign',
            'outreach-composer'  => 'Communication',
            'outreach-summary'   => 'Communication',
            'calendar'           => 'Dashboard & Command Centre',
            'tasks'              => 'Dashboard & Command Centre',
            'feedback-reports'   => 'Dashboard & Command Centre',
        ];
        if (isset($core[$key])) {
            return $core[$key];
        }

        $prefixes = [
            'cc-'     => 'Dashboard & Command Centre',
            're-'     => 'Real Estate',
            'mic-'    => 'Market Intelligence',
            'pres-'   => 'Presentations',
            'deals-'  => 'Deals',
            'rent-'   => 'Rentals',
            'comp-'   => 'Compliance',
            'dp-'     => 'Documents & E-Sign',
            'docs-'   => 'Documents & E-Sign',
            'comms-'  => 'Communication',
            'portal-' => 'My Portal',
            'earn-'   => 'Earnings',
            'at-'     => 'Agency Tracker',
            'tools-'  => 'Tools & Calculators',
            'calc-'   => 'Tools & Calculators',
            'train-'  => 'Training & AI',
            'ai-'     => 'Training & AI',
            'misc-'   => 'Other',
        ];
        foreach ($prefixes as $prefix => $group) {
            if (str_starts_with($key, $prefix)) {
                return $group;
            }
        }

        return 'Other';
    }
}
