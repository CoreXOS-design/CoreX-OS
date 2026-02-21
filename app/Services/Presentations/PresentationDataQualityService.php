<?php

namespace App\Services\Presentations;

use App\Models\PresentationActiveListing;

/**
 * Evaluates overall dataset quality for a presentation's active listings.
 *
 * Deterministic only. No AI. No external I/O.
 */
class PresentationDataQualityService
{
    /**
     * Evaluate dataset quality across all active listings for a presentation.
     *
     * @return array{
     *   avg_merge_confidence: float|null,
     *   avg_data_quality_score: float|null,
     *   low_confidence_percentage: float|null,
     *   conflict_listing_count: int,
     *   overall_grade: string|null
     * }
     */
    public function evaluate(int $presentationId): array
    {
        $listings = PresentationActiveListing::where('presentation_id', $presentationId)
            ->where('is_active', true)
            ->get();

        if ($listings->isEmpty()) {
            return [
                'avg_merge_confidence'     => null,
                'avg_data_quality_score'   => null,
                'low_confidence_percentage' => null,
                'conflict_listing_count'   => 0,
                'overall_grade'            => null,
            ];
        }

        $count = $listings->count();

        // ── Average merge confidence ────────────────────────────────────
        $confidenceValues = $listings
            ->filter(fn ($l) => $l->merge_confidence !== null)
            ->pluck('merge_confidence');

        $avgMergeConfidence = $confidenceValues->isNotEmpty()
            ? round($confidenceValues->avg(), 2)
            : null;

        // ── Average data quality score ──────────────────────────────────
        $qualityValues = $listings
            ->filter(fn ($l) => $l->data_quality_score !== null)
            ->pluck('data_quality_score');

        $avgDataQualityScore = $qualityValues->isNotEmpty()
            ? round($qualityValues->avg(), 2)
            : null;

        // ── Low confidence percentage (merge_confidence < 60) ───────────
        $lowConfidenceCount = $confidenceValues->filter(fn ($v) => $v < 60)->count();
        $lowConfidencePercentage = $confidenceValues->isNotEmpty()
            ? round($lowConfidenceCount / $confidenceValues->count() * 100, 2)
            : null;

        // ── Conflict listing count (any conflict flag is true) ──────────
        $conflictListingCount = $listings->filter(function ($l) {
            $flags = $l->conflict_flags_json;
            if (!is_array($flags)) {
                return false;
            }
            foreach ($flags as $flag) {
                if ($flag === true) {
                    return true;
                }
            }
            return false;
        })->count();

        // ── Overall grade ───────────────────────────────────────────────
        $overallGrade = $this->computeGrade($avgDataQualityScore);

        return [
            'avg_merge_confidence'     => $avgMergeConfidence,
            'avg_data_quality_score'   => $avgDataQualityScore,
            'low_confidence_percentage' => $lowConfidencePercentage,
            'conflict_listing_count'   => $conflictListingCount,
            'overall_grade'            => $overallGrade,
        ];
    }

    /**
     * Map avg data quality score to a letter grade.
     *
     * A >= 85, B >= 70, C >= 50, D < 50
     */
    public function computeGrade(?float $avgScore): ?string
    {
        if ($avgScore === null) {
            return null;
        }

        return match (true) {
            $avgScore >= 85 => 'A',
            $avgScore >= 70 => 'B',
            $avgScore >= 50 => 'C',
            default         => 'D',
        };
    }
}
