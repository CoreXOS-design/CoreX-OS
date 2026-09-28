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
                return TourRegistry::canOpenRoute($tour['route'] ?? null, $user);
            })
            ->map(function ($tour) {
                // Resolve the launch URL safely. Routes that need parameters
                // (e.g. the contact-scoped outreach composer) can't be linked
                // generically — flag those so the UI explains where to go.
                $routeName = $tour['route'] ?? null;
                $external  = $tour['external_url'] ?? null;
                $url = null;
                $needsContext = false;

                $advancedUrl = null;
                $spot = [];
                $pick = false;

                if ($external) {
                    // External-link entry (e.g. Mobile app): the card is the whole
                    // feature — it leaves CoreX rather than driving a tour.
                    $url = $external;
                } elseif ($routeName && RouteFacade::has($routeName)) {
                    // Record pages (e.g. one property) launch via their pick list;
                    // the guide then starts itself on the record the agent opens.
                    $target = TourRegistry::launchTarget($tour, 'tour');
                    if ($target) {
                        $url  = $target['url'];
                        $pick = $target['pick'];
                    } else {
                        $needsContext = true; // route requires a bound model, no pick list
                    }

                    // Advanced Guide + Spot Help (spec: advanced-guiding.md). This page
                    // is itself behind the Guided Tours switch (feature:guided-tours).
                    if ($url) {
                        $advancedUrl = TourRegistry::launchTarget($tour, 'advanced')['url'] ?? null;
                        if (TourRegistry::supportsSpotHelp($tour)) {
                            foreach (TourRegistry::sections($tour) as $section) {
                                if ($spotTarget = TourRegistry::launchTarget($tour, 'spot', $section)) {
                                    $spot[] = ['name' => $section, 'url' => $spotTarget['url']];
                                }
                            }
                        }
                    }
                }

                return [
                    'key'          => $tour['key'],
                    'title'        => $tour['title'] ?? $tour['key'],
                    'description'  => $tour['description'] ?? null,
                    'steps'        => count($tour['steps'] ?? []),
                    'url'          => $url,
                    'advancedUrl'  => $advancedUrl,
                    'spot'         => $spot,
                    'pick'         => $pick,
                    'pickNote'     => $pick ? ($tour['pick_note'] ?? null) : null,
                    'needsContext' => $needsContext,
                    'external'     => (bool) $external,
                    // Host shown on the card so it's obvious the button leaves CoreX.
                    'externalHost' => $external ? (parse_url($external, PHP_URL_HOST) ?: null) : null,
                    'cta'          => $tour['cta'] ?? null,
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

    /** Display order of the directory's category sections. */
    private const GROUP_ORDER = [
        'Mobile',
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
            'mobile-app'        => 'Mobile',
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
