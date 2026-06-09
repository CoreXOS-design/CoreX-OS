<?php

namespace App\Services\AI;

use App\Models\Property;
use App\Models\PropertyImageAnalysis;

/**
 * Turns raw AI image analyses (flat token vocabulary) into suggestions
 * expressed in the WEB property workspace's vocabulary — spaces
 * (config('property-spaces')['all_space_types']) and global feature
 * categories (theProperty / security / connectivity / sustainability).
 *
 * The vision model emits the canonical flat tokens
 * (ContactMatchController::FEATURE_OPTIONS + SPACE_TYPES). Some of those
 * "features" are actually SPACES on the web (pool, garden, garage, study,
 * granny_flat) — those become space suggestions. The rest map to a specific
 * web feature label in a specific category. Tokens with no clean web
 * equivalent (sea_view, generic "security") are intentionally dropped so we
 * never write a label outside the web vocabulary.
 *
 * Used by the property workspace "AI photo suggestions" modal.
 * Spec: .ai/specs/property-image-recognition.md
 */
class PropertyAiSuggestionService
{
    /**
     * AI feature token => web target.
     *   ['space' => 'Pool']                              → a space suggestion
     *   ['feature' => ['category' => 'x', 'label' => 'Y']] → a feature suggestion
     * Tokens absent from this map (sea_view, security) are dropped.
     *
     * Feature labels are verified against the web _FEATURE_CATEGORIES lists in
     * resources/views/corex/properties/show.blade.php — keep them in sync.
     */
    public const TOKEN_MAP = [
        'pool'             => ['space' => 'Pool'],
        'garden'           => ['space' => 'Garden'],
        'garage'           => ['space' => 'Garage'],
        'study'            => ['space' => 'Study'],
        'granny_flat'      => ['space' => 'Flatlet'],
        'furnished'        => ['feature' => ['category' => 'theProperty', 'label' => 'Furnished']],
        'pet_friendly'     => ['feature' => ['category' => 'theProperty', 'label' => 'Pet Friendly']],
        'air_conditioning' => ['feature' => ['category' => 'theProperty', 'label' => 'Air Conditioned']],
        'balcony'          => ['feature' => ['category' => 'theProperty', 'label' => 'Balcony']],
        'fibre'            => ['feature' => ['category' => 'connectivity', 'label' => 'Fibre']],
        'solar'            => ['feature' => ['category' => 'sustainability', 'label' => 'Solar Panel']],
        'borehole'         => ['feature' => ['category' => 'sustainability', 'label' => 'Borehole']],
        // Intentionally unmapped (no clean web vocabulary equivalent): sea_view, security.
    ];

    /**
     * Build the suggestion payload for a property's completed, not-yet-reviewed
     * analyses. Aggregates max confidence per item and dedupes.
     *
     * @return array{hasSuggestions:bool, spaces:array<int,array{type:string,confidence:float}>, features:array<int,array{label:string,category:string,confidence:float}>}
     */
    public function forProperty(Property $property): array
    {
        $empty = ['hasSuggestions' => false, 'spaces' => [], 'features' => []];

        if (! $property->exists) {
            return $empty;
        }

        $rows = PropertyImageAnalysis::query()
            ->where('property_id', $property->id)
            ->where('status', 'complete')
            ->whereNull('reviewed_at')
            ->get(['detected_features', 'detected_spaces']);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $validSpaceTypes = (array) config('property-spaces.all_space_types', []);

        $spaceConf   = [];   // type  => max confidence
        $featureConf = [];   // label => [category, max confidence]

        $noteSpace = function (string $type, float $conf) use (&$spaceConf, $validSpaceTypes): void {
            if ($type === '' || ! in_array($type, $validSpaceTypes, true)) {
                return;
            }
            $spaceConf[$type] = max($spaceConf[$type] ?? 0, $conf);
        };
        $noteFeature = function (string $label, string $category, float $conf) use (&$featureConf): void {
            if ($label === '') {
                return;
            }
            if (! isset($featureConf[$label]) || $conf > $featureConf[$label]['confidence']) {
                $featureConf[$label] = ['category' => $category, 'confidence' => $conf];
            }
        };

        foreach ($rows as $row) {
            // Detected spaces already use the web space-type vocabulary.
            foreach ((array) $row->detected_spaces as $s) {
                $noteSpace((string) ($s['token'] ?? ''), (float) ($s['confidence'] ?? 0));
            }

            // Detected features: map each token to a space or a feature.
            foreach ((array) $row->detected_features as $f) {
                $token = (string) ($f['token'] ?? '');
                $conf  = (float) ($f['confidence'] ?? 0);
                $map   = self::TOKEN_MAP[$token] ?? null;
                if ($map === null) {
                    continue;
                }
                if (isset($map['space'])) {
                    $noteSpace($map['space'], $conf);
                } else {
                    $noteFeature($map['feature']['label'], $map['feature']['category'], $conf);
                }
            }
        }

        $spaces = [];
        foreach ($spaceConf as $type => $conf) {
            $spaces[] = ['type' => $type, 'confidence' => round($conf, 2)];
        }
        usort($spaces, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        $features = [];
        foreach ($featureConf as $label => $info) {
            $features[] = ['label' => $label, 'category' => $info['category'], 'confidence' => round($info['confidence'], 2)];
        }
        usort($features, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return [
            'hasSuggestions' => ! empty($spaces) || ! empty($features),
            'spaces'         => $spaces,
            'features'       => $features,
        ];
    }

    /**
     * Mark every completed, not-yet-reviewed analysis for the property as
     * reviewed, so the suggestions modal stops re-appearing. Returns the
     * number of rows stamped.
     */
    public function markReviewed(Property $property): int
    {
        if (! $property->exists) {
            return 0;
        }

        return PropertyImageAnalysis::query()
            ->where('property_id', $property->id)
            ->where('status', 'complete')
            ->whereNull('reviewed_at')
            ->update(['reviewed_at' => now()]);
    }
}
