<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationLink;
use Illuminate\Support\Collection;

/**
 * Phase 3e F — surface "auto-link" suggestions for a presentation.
 *
 * The seller-facing report is more persuasive when each competing listing
 * cited in the active competition table has a verified URL behind it.
 * Agents normally attach those URLs manually; this resolver scans the
 * hydrated active_listings + portal_captures and proposes URLs the agent
 * could one-click attach as PresentationLinks.
 *
 * Read-only: returns suggestions, never writes. The UI layer is
 * responsible for showing the prompt and persisting accepted suggestions
 * as `competitor_listing` PresentationLink rows.
 *
 * Threshold: only returns suggestions when there are ≥3 competitor
 * listings (below that the seller's report has bigger gaps to fill than
 * link evidence).
 */
final class SimilarActiveListingsResolver
{
    public const MIN_COMPETITORS = 3;

    /**
     * @return array{
     *   eligible: bool,
     *   reason: ?string,
     *   suggestions: array<int, array{
     *     url: string,
     *     suburb: ?string,
     *     property_type: ?string,
     *     beds: ?int,
     *     list_price: ?int,
     *     extent_m2: ?int,
     *     source: string,
     *     score: float,
     *   }>,
     * }
     */
    public function suggestFor(Presentation $presentation): array
    {
        $active = $presentation->activeListings()->get();

        if ($active->count() < self::MIN_COMPETITORS) {
            return [
                'eligible'   => false,
                'reason'     => 'fewer than ' . self::MIN_COMPETITORS . ' competitor listings',
                'suggestions' => [],
            ];
        }

        // Pull URLs already attached so we don't suggest duplicates.
        $existingUrls = PresentationLink::where('presentation_id', $presentation->id)
            ->pluck('url')
            ->map(fn ($u) => $this->normaliseUrl((string) $u))
            ->filter()
            ->all();

        $candidates = collect();
        foreach ($active as $listing) {
            $raw = is_string($listing->raw_row_json)
                ? (json_decode($listing->raw_row_json, true) ?: [])
                : ((array) ($listing->raw_row_json ?? []));

            $url = $this->pickUrl($raw);
            if ($url === null) continue;

            $normalised = $this->normaliseUrl($url);
            if ($normalised === null || in_array($normalised, $existingUrls, true)) continue;

            $candidates->push([
                'url'           => $url,
                'suburb'        => $listing->suburb ?? ($raw['suburb'] ?? null),
                'property_type' => $listing->property_type ?? ($raw['property_type'] ?? null),
                'beds'          => $listing->beds ?? ($raw['beds'] ?? null),
                'list_price'    => $listing->list_price_inc ?? ($raw['list_price'] ?? null),
                'extent_m2'     => $listing->size_m2 ?? ($raw['extent_m2'] ?? null),
                'source'        => $raw['source'] ?? ($listing->extraction_method ?? 'unknown'),
                'score'         => $this->confidence($listing, $raw),
            ]);
        }

        // Highest-confidence suggestions first.
        $ranked = $candidates
            ->sortByDesc('score')
            ->values()
            ->unique('url')
            ->values()
            ->all();

        return [
            'eligible'    => !empty($ranked),
            'reason'      => empty($ranked) ? 'no usable URLs on active listings' : null,
            'suggestions' => $ranked,
        ];
    }

    private function pickUrl(array $raw): ?string
    {
        foreach (['url', 'source_url', 'listing_url'] as $k) {
            $v = $raw[$k] ?? null;
            if (is_string($v) && filter_var($v, FILTER_VALIDATE_URL)) {
                return $v;
            }
        }
        return null;
    }

    private function normaliseUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['host'])) return null;
        $host = mb_strtolower($parsed['host']);
        $path = rtrim($parsed['path'] ?? '', '/');
        return $host . $path;
    }

    /**
     * Confidence in [0,1] — biased toward listings with more fielded data
     * (price + extent + beds) and a richer source (portal > upload).
     */
    private function confidence(mixed $listing, array $raw): float
    {
        $score = 0.5;
        if (!empty($listing->list_price_inc) || !empty($raw['list_price'])) $score += 0.15;
        if (!empty($listing->size_m2) || !empty($raw['extent_m2']))         $score += 0.1;
        if (!empty($listing->beds) || !empty($raw['beds']))                 $score += 0.05;
        $src = $raw['source'] ?? ($listing->extraction_method ?? '');
        if (str_contains((string) $src, 'portal'))                          $score += 0.15;
        if (str_contains((string) $src, 'upload'))                          $score += 0.05;
        return min(1.0, $score);
    }
}
