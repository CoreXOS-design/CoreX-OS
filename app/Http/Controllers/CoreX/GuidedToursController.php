<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Support\Tours\TourRegistry;
use Illuminate\Support\Facades\Route as RouteFacade;

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
            ->filter(fn ($tour) => TourRegistry::visibleTo($tour, $user))
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
                ];
            })
            ->sortBy('title')
            ->values();

        return view('corex.guided-tours.index', ['tours' => $tours]);
    }
}
