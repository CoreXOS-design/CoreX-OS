<?php

namespace App\Services\FeatureRollout;

use App\Services\Features\AgencyFeatureService;
use App\Support\Tours\TourRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Which feature does each guided tour belong to? (AT-478 tour gap report, spec §12.11.)
 *
 * Tours are keyed by the page they run on and the permission they need, not by feature, so a tour
 * is matched to a feature through the registry's own ownership data, in this order:
 *   0. the tour names its feature outright (`'feature' => '<key>'`) — for a feature whose screen is
 *      gated by another feature's permission or lives on another feature's page (Marketing on the
 *      property page, Public website on Edit Agency). Only a label for this report: it does not gate the
 *      page or the tour, so declaring it never changes who can open what.
 *   1. the tour's permission is owned by a feature (AgencyFeatureService::featureForPermission)
 *   2. the tour's route (or an also_on route) matches a feature's route_names glob
 *   3. the tour's route URI starts with one of a feature's route_prefixes
 * A tour that matches none (e.g. an external help link) is simply not counted for any feature.
 */
class FeatureTourCoverage
{
    public function __construct(private readonly AgencyFeatureService $features)
    {
    }

    /** @return array<string, list<array{key:string,title:string}>> feature key => its tours */
    public function toursByFeature(): array
    {
        $out = [];
        foreach (TourRegistry::all() as $key => $tour) {
            $feature = $this->featureFor($tour);
            if ($feature !== null) {
                $out[$feature][] = ['key' => (string) $key, 'title' => (string) ($tour['title'] ?? $key)];
            }
        }

        return $out;
    }

    public function featureFor(array $tour): ?string
    {
        if (! empty($tour['feature']) && is_string($tour['feature']) && array_key_exists($tour['feature'], $this->features->catalogue())) {
            return $tour['feature'];
        }

        if (! empty($tour['permission']) && is_string($tour['permission'])) {
            $owner = $this->features->featureForPermission($tour['permission']);
            if ($owner !== null) {
                return $owner;
            }
        }

        $routes = array_filter(array_merge([(string) ($tour['route'] ?? '')], (array) ($tour['also_on'] ?? [])));
        foreach ($routes as $name) {
            foreach ($this->features->catalogue() as $featureKey => $def) {
                foreach ((array) ($def['route_names'] ?? []) as $glob) {
                    if (Str::is($glob, $name)) {
                        return $featureKey;
                    }
                }
            }

            $uri = Route::has($name) ? ltrim((string) Route::getRoutes()->getByName($name)->uri(), '/') : null;
            if ($uri === null) {
                continue;
            }
            foreach ($this->features->catalogue() as $featureKey => $def) {
                foreach ((array) ($def['route_prefixes'] ?? []) as $prefix) {
                    $prefix = trim((string) $prefix, '/');
                    if ($prefix === '') {
                        continue;
                    }
                    // Prefixes are written without the app's own "corex/" segment (e.g. 'training' for
                    // corex/training), the same way FeatureRolloutUsage::recordVisit reads them.
                    foreach ([$prefix, 'corex/' . $prefix] as $p) {
                        if ($uri === $p || str_starts_with($uri, $p . '/')) {
                            return $featureKey;
                        }
                    }
                }
            }
        }

        return null;
    }
}
