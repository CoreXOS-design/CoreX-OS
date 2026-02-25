<?php

namespace App\Services\Articles;

use App\Models\ArticlePool;
use App\Models\Presentation;
use App\Support\SuburbMapper;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ArticleMatcherService
{
    /**
     * Suggest the most relevant articles from the pool for a presentation.
     *
     * Uses a 3-tier matching hierarchy:
     *   1. Town/City level (+8) — suburb mapped to parent town, matches all siblings
     *   2. Region level (+5) — South Coast, KZN, Hibiscus Coast, etc.
     *   3. National/financial (+3) — interest rates, bond, property market (always relevant)
     *
     * Excludes articles already attached to this presentation (matched by URL).
     * Excludes articles older than 6 months.
     *
     * @return Collection<int, ArticlePool>  Scored and ranked, with a `match_score` attribute
     */
    public function suggest(Presentation $presentation, int $limit = 5): Collection
    {
        $cutoff = Carbon::now()->subMonths(6);

        // URLs already attached to this presentation — exclude them
        $attachedUrls = $presentation->articles()->pluck('url')->toArray();

        $query = ArticlePool::where(function ($q) use ($cutoff) {
            $q->where('published_at', '>=', $cutoff)
              ->orWhereNull('published_at');
        });

        $articles = $query->get();

        // Extract presentation context for matching
        $suburb       = mb_strtolower(trim($presentation->suburb ?? ''));
        $propertyType = mb_strtolower(trim($presentation->property_type ?? ''));

        // Expand suburb to town-level siblings for matching
        $townSuburbs = [];
        $townName    = null;
        if ($suburb !== '') {
            $townName = SuburbMapper::townFor($suburb);
            if ($townName !== null) {
                $townSuburbs = array_map('mb_strtolower', SuburbMapper::suburbsInTown($townName));
                // Also add the town name itself as a matchable term
                $townSuburbs[] = mb_strtolower($townName);
            }
        }

        // Score each article
        $scored = $articles->map(function (ArticlePool $article) use ($suburb, $propertyType, $attachedUrls, $townSuburbs, $townName) {
            // Skip already-attached
            if (in_array($article->url, $attachedUrls, true)) {
                return null;
            }

            $score = $this->scoreArticle($article, $suburb, $propertyType, $townSuburbs, $townName);

            if ($score <= 0) {
                return null;
            }

            $article->setAttribute('match_score', $score);

            return $article;
        })
        ->filter()
        ->sortByDesc('match_score')
        ->take($limit)
        ->values();

        return $scored;
    }

    /**
     * Score a single article against presentation context.
     *
     * 3-tier hierarchy:
     *   Tier 1: Town/City match (+8) — article tags a suburb in the same town
     *   Tier 2: Region match (+5) — article tags a KZN/South Coast region
     *   Tier 3: National/financial (+3) — interest rates, property market, etc.
     *
     * Additional bonuses:
     *   Exact suburb match: +10 (on top of town match)
     *   Property type match: +3
     *   Recency: +1 to +3
     */
    private function scoreArticle(
        ArticlePool $article,
        string $suburb,
        string $propertyType,
        array $townSuburbs,
        ?string $townName,
    ): int {
        $tags  = $article->tags_json ?? [];
        $score = 0;

        // Tier 1: Town/City level match (+8)
        // Check if article mentions any suburb in the same town area
        $hasTownMatch = false;
        $hasExactSuburbMatch = false;

        if (!empty($tags['suburbs'])) {
            foreach ($tags['suburbs'] as $tagSuburb) {
                $tagLower = mb_strtolower($tagSuburb);

                // Exact suburb match
                if ($suburb !== '' && $tagLower === $suburb) {
                    $hasExactSuburbMatch = true;
                    $hasTownMatch = true;
                    break;
                }

                // Town-level match (article mentions a sibling suburb)
                if (!empty($townSuburbs) && in_array($tagLower, $townSuburbs, true)) {
                    $hasTownMatch = true;
                }
            }
        }

        // Also check if article mentions the town name in title/snippet via towns tag
        if (!$hasTownMatch && !empty($tags['towns'])) {
            foreach ($tags['towns'] as $tagTown) {
                if ($townName !== null && mb_strtolower($tagTown) === mb_strtolower($townName)) {
                    $hasTownMatch = true;
                    break;
                }
            }
        }

        if ($hasExactSuburbMatch) {
            $score += 10; // Exact suburb match (strongest signal)
        } elseif ($hasTownMatch) {
            $score += 8;  // Town-level match
        }

        // Tier 2: Region match (+5)
        if (!empty($tags['regions'])) {
            $score += 5;
        }

        // Tier 3: National/financial topic (+3) — always relevant
        if (!empty($tags['topics'])) {
            $score += 3;
        }

        // Property type match (+3)
        if ($propertyType !== '' && !empty($tags['property_types'])) {
            foreach ($tags['property_types'] as $tagType) {
                if (mb_strtolower($tagType) === $propertyType) {
                    $score += 3;
                    break;
                }
            }
        }

        // Recency bonus
        if ($article->published_at) {
            $daysAgo = $article->published_at->diffInDays(now());
            if ($daysAgo <= 7) {
                $score += 3;
            } elseif ($daysAgo <= 30) {
                $score += 2;
            } elseif ($daysAgo <= 90) {
                $score += 1;
            }
        }

        return $score;
    }
}
