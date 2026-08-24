<?php

declare(strict_types=1);

namespace App\Services\Deals;

use App\Models\Deal;
use App\Models\Property;
use App\Support\Geocoding\AddressNormaliser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3i — match deals to properties structurally.
 *
 * Strategy (see linkDeal()):
 *   1. Normalise the deal's property_address via Phase 3f AddressNormaliser.
 *   2. Build a list of candidate properties in the same agency:
 *      - Exact normalised match → score 100, confidence='exact'
 *      - Token-subset / substring match → 70-85, confidence='high'
 *      - Levenshtein <= 3 of normalised → 50-65, confidence='medium'
 *      - Suburb match + street name fragment → 30-45, confidence='low'
 *   3. Date proximity boost (±20 points): deal registration_date within
 *      730 days (24mo) of property created_at OR last_activity_at.
 *   4. Resolution:
 *      a) 1 candidate, confidence=exact|high     → link directly
 *      b) 1 candidate, confidence=medium         → link AND queue for review
 *      c) 1 candidate, confidence=low            → queue, no link
 *      d) >1 candidate                           → queue, no link
 *      e) 0 candidates                           → log, no link, no queue
 *
 * Idempotent: re-calling linkDeal on an already-linked deal is a no-op.
 * Bulk operation (backfillAll) chunks and yields every 100 rows.
 *
 * Notes on date proximity:
 *   We don't require strict overlap — property records may have been
 *   captured well after the original sale date (Tracked Property →
 *   Property promotion path). Within ±24mo is a "this property existed
 *   in CoreX around when the deal happened" signal, not "they overlap".
 */
final class DealPropertyLinkService
{
    public const MAX_CANDIDATES_KEPT = 5;
    public const MIN_SCORE_AUTO_LINK = 70;
    public const MIN_SCORE_REVIEW    = 30;

    /**
     * Link a single deal. Returns the decision record.
     *
     * @return array{
     *   linked: bool,
     *   property_id?: int|null,
     *   confidence?: string|null,
     *   source?: string|null,
     *   candidates?: array<int, array<string, mixed>>,
     *   queued_for_review: bool,  // always false since 2026-08-24 -- the Deal Link
     *                             // Review admin screen it fed was removed (zero rows,
     *                             // ever, in its whole lifetime). Kept in the return
     *                             // shape rather than removed from every branch below,
     *                             // to avoid a wider diff for a field nothing reads
     *                             // anymore -- see backfillAll()'s candidates-based
     *                             // check instead of this flag.
     *   already_linked?: bool,
     *   reason?: string,
     * }
     */
    public function linkDeal(Deal $deal): array
    {
        if ($deal->property_id) {
            return [
                'linked' => true,
                'property_id' => (int) $deal->property_id,
                'queued_for_review' => false,
                'already_linked' => true,
            ];
        }

        $rawAddress = trim((string) $deal->property_address);
        if ($rawAddress === '') {
            return [
                'linked' => false,
                'queued_for_review' => false,
                'reason' => 'deal_has_no_address',
            ];
        }

        $candidates = $this->findCandidates($deal);

        if ($candidates->isEmpty()) {
            return [
                'linked' => false,
                'queued_for_review' => false,
                'reason' => 'no_candidates',
                'candidates' => [],
            ];
        }

        // Single-candidate paths.
        if ($candidates->count() === 1) {
            $top = $candidates->first();
            $confidence = $this->confidenceForScore((int) $top['score']);

            if (in_array($confidence, ['exact', 'high'], true)) {
                $source = $top['date_match'] ? 'auto_address_date_match' : 'auto_address_match';
                $this->applyLink($deal, (int) $top['property_id'], $source, $confidence);
                return [
                    'linked' => true,
                    'property_id' => (int) $top['property_id'],
                    'confidence' => $confidence,
                    'source'    => $source,
                    'candidates' => [$top],
                    'queued_for_review' => false,
                ];
            }
            if ($confidence === 'medium') {
                $this->applyLink($deal, (int) $top['property_id'], 'auto_address_match', $confidence);
                return [
                    'linked' => true,
                    'property_id' => (int) $top['property_id'],
                    'confidence' => $confidence,
                    'source'    => 'auto_address_match',
                    'candidates' => [$top],
                    'queued_for_review' => false,
                ];
            }
            // low confidence — not auto-linked. The review-queue this used to
            // feed (Deal Link Review) was removed 2026-08-24: zero rows, ever,
            // in its lifetime, and zero deals ever linked via the admin review
            // action it existed to drive (link_reviewed_at null on all 154
            // deals at removal time). No replacement human-review path exists
            // — a low-confidence deal now simply stays unlinked, same
            // observable outcome as before (nobody was resolving these via
            // the queue either), just without pretending a review step will
            // happen.
            return [
                'linked' => false,
                'confidence' => $confidence,
                'candidates' => [$top],
                'queued_for_review' => false,
            ];
        }

        // Multi-candidate — never auto-link (ambiguous). Same reasoning as
        // the low-confidence branch above: no review-queue destination
        // exists anymore, so this stays unlinked rather than queued.
        return [
            'linked' => false,
            'candidates' => $candidates->all(),
            'queued_for_review' => false,
        ];
    }

    /**
     * Bulk backfill across an agency. Idempotent.
     *
     * @return array{
     *   total: int, linked_exact: int, linked_high: int,
     *   linked_with_review_flag: int, queued_for_review: int,
     *   no_candidates: int, already_linked: int,
     * }
     */
    public function backfillAll(?int $agencyId = null, bool $dryRun = false, ?int $limit = null): array
    {
        $summary = [
            'total' => 0,
            'linked_exact' => 0,
            'linked_high' => 0,
            'linked_with_review_flag' => 0,
            'queued_for_review' => 0,
            'no_candidates' => 0,
            'already_linked' => 0,
        ];

        $query = Deal::withoutGlobalScopes()
            ->whereNull('property_id')
            ->whereNotNull('property_address');
        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $query->orderBy('id')->chunkById(100, function ($deals) use (&$summary, $dryRun) {
            foreach ($deals as $deal) {
                $summary['total']++;

                if ($dryRun) {
                    $candidates = $this->findCandidates($deal);
                    if ($candidates->isEmpty()) {
                        $summary['no_candidates']++;
                    } elseif ($candidates->count() === 1) {
                        $conf = $this->confidenceForScore((int) $candidates->first()['score']);
                        if ($conf === 'exact') $summary['linked_exact']++;
                        elseif ($conf === 'high') $summary['linked_high']++;
                        elseif ($conf === 'medium') $summary['linked_with_review_flag']++;
                        else $summary['queued_for_review']++;
                    } else {
                        $summary['queued_for_review']++;
                    }
                    continue;
                }

                $result = $this->linkDeal($deal);
                if (!empty($result['already_linked'])) {
                    $summary['already_linked']++;
                    continue;
                }
                if ($result['linked'] ?? false) {
                    $conf = $result['confidence'] ?? '';
                    if ($conf === 'exact') $summary['linked_exact']++;
                    elseif ($conf === 'high') $summary['linked_high']++;
                    elseif ($conf === 'medium') $summary['linked_with_review_flag']++;
                    else $summary['linked_high']++; // shouldn't happen — defensive
                } elseif (!empty($result['candidates'])) {
                    // Had candidates but wasn't confident enough to auto-link (low
                    // confidence or multi-candidate/ambiguous). Was distinguished via
                    // the now-removed queued_for_review flag (Deal Link Review removed
                    // 2026-08-24) -- checking for a non-empty candidate list instead
                    // keeps this bucket honest rather than silently collapsing into
                    // "no candidates found", which linkDeal() never actually returned
                    // for these deals.
                    $summary['queued_for_review']++;
                } else {
                    $summary['no_candidates']++;
                }
            }
            usleep(50_000); // 50ms throttle between chunks
        });

        return $summary;
    }

    /**
     * Populate sale_price + sale_date from the legacy property_value /
     * registration_date columns. Idempotent — never overwrites a populated
     * sale_price.
     *
     * @return array{touched: int, sale_price_filled: int, sale_date_filled: int}
     */
    public function populateSaleColumns(?int $agencyId = null, bool $dryRun = false): array
    {
        $summary = ['touched' => 0, 'sale_price_filled' => 0, 'sale_date_filled' => 0];

        $query = Deal::withoutGlobalScopes()
            ->where(function ($q) {
                $q->whereNull('sale_price')->orWhereNull('sale_date');
            });
        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }

        $query->orderBy('id')->chunkById(100, function ($deals) use (&$summary, $dryRun) {
            foreach ($deals as $deal) {
                $needsPrice = $deal->sale_price === null && (float) $deal->property_value > 0;
                $needsDate  = $deal->sale_date === null && $deal->registration_date !== null;
                if (!$needsPrice && !$needsDate) {
                    continue;
                }
                $summary['touched']++;
                if ($dryRun) {
                    if ($needsPrice) $summary['sale_price_filled']++;
                    if ($needsDate)  $summary['sale_date_filled']++;
                    continue;
                }
                $updates = [];
                if ($needsPrice) {
                    $updates['sale_price'] = (int) round((float) $deal->property_value);
                    $summary['sale_price_filled']++;
                }
                if ($needsDate) {
                    $updates['sale_date'] = $deal->registration_date;
                    $summary['sale_date_filled']++;
                }
                $deal->forceFill($updates)->save();
            }
        });

        return $summary;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Resolve a deal to a SCORED candidate set. Score 0-120.
     *
     * @return Collection<int, array{property_id:int, score:int, confidence:string, address:string, suburb:string|null, date_match:bool}>
     */
    private function findCandidates(Deal $deal): Collection
    {
        $rawAddress = (string) $deal->property_address;
        // Heuristic suburb extraction from the deal address — last comma chunk
        // is usually the suburb in HFC's data.
        $parts = array_map('trim', explode(',', $rawAddress));
        $candidateSuburb = count($parts) > 1 ? end($parts) : null;
        $normalisedDeal = AddressNormaliser::normalise($rawAddress, $candidateSuburb);
        if ($normalisedDeal === '') {
            return collect();
        }

        // Pull all properties in the same agency. Counts are small (<5000 in
        // practice) — in-memory scoring is fast and gives flexibility a
        // SQL-only query can't.
        $properties = Property::withoutGlobalScopes()
            ->where('agency_id', $deal->agency_id)
            ->get(['id', 'address', 'suburb', 'created_at', 'last_activity_at']);

        $dealDate = $deal->registration_date ?? $deal->deal_date;
        $candidates = [];

        foreach ($properties as $prop) {
            $rawPropAddress = trim((string) $prop->address);
            if ($rawPropAddress === '') {
                // Properties with no street address can't be candidates —
                // their normaliser output would be just the suburb, which
                // would substring-match any deal in that suburb.
                continue;
            }
            $propNorm = AddressNormaliser::normalise($rawPropAddress, $prop->suburb);
            if ($propNorm === '') {
                continue;
            }

            $score = $this->scorePair($normalisedDeal, $propNorm);
            if ($score < self::MIN_SCORE_REVIEW) {
                continue;
            }

            $dateMatch = false;
            if ($dealDate) {
                $anchor = $prop->last_activity_at ?? $prop->created_at;
                if ($anchor) {
                    $deltaDays = abs(\Carbon\Carbon::parse($anchor)->diffInDays(\Carbon\Carbon::parse($dealDate)));
                    if ($deltaDays <= 730) {
                        $score += 15;
                        $dateMatch = true;
                    }
                }
            }

            $candidates[] = [
                'property_id' => (int) $prop->id,
                'score'       => $score,
                'confidence'  => $this->confidenceForScore($score),
                'address'     => (string) $prop->address,
                'suburb'      => $prop->suburb,
                'date_match'  => $dateMatch,
            ];
        }

        // Sort by score desc, keep top N.
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        return collect(array_slice($candidates, 0, self::MAX_CANDIDATES_KEPT));
    }

    /**
     * Score similarity between two normalised addresses. 0-100 base.
     *
     *   Exact equal              → 100
     *   One contains the other   → 80
     *   Token Jaccard >= 0.7     → 70
     *   Levenshtein <= 3 (short) → 65
     *   Levenshtein <= 6 (long)  → 50
     *   Shared suburb + street   → 30-45 sliding
     *   else                     → 0 (filtered out)
     */
    private function scorePair(string $a, string $b): int
    {
        if ($a === $b) {
            return 100;
        }
        if ($a !== '' && $b !== '' && (str_contains($b, $a) || str_contains($a, $b))) {
            // Shorter inside longer — strong signal but not exact.
            return 80;
        }

        $aTokens = array_filter(explode(' ', $a));
        $bTokens = array_filter(explode(' ', $b));
        $inter = array_intersect($aTokens, $bTokens);
        $union = array_unique(array_merge($aTokens, $bTokens));
        $jaccard = count($union) > 0 ? count($inter) / count($union) : 0;
        if ($jaccard >= 0.7) {
            return 70 + (int) round(($jaccard - 0.7) * 30);
        }

        if (mb_strlen($a) <= 40 && mb_strlen($b) <= 40) {
            $lev = levenshtein($a, $b);
            if ($lev <= 3) return 65;
            if ($lev <= 6) return 50;
        }

        // Last-chance: shared suburb + at least one street token.
        if ($jaccard >= 0.35) {
            return 30 + (int) round($jaccard * 30);
        }

        return 0;
    }

    private function confidenceForScore(int $score): string
    {
        if ($score >= 95) return 'exact';
        if ($score >= 75) return 'high';
        if ($score >= 55) return 'medium';
        return 'low';
    }

    private function applyLink(Deal $deal, int $propertyId, string $source, string $confidence): void
    {
        DB::transaction(function () use ($deal, $propertyId, $source, $confidence) {
            $deal->forceFill([
                'property_id'     => $propertyId,
                'link_source'     => $source,
                'link_confidence' => $confidence,
            ])->save();
        });
    }

}
