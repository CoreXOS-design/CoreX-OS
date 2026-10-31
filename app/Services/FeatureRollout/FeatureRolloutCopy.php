<?php

namespace App\Services\FeatureRollout;

use App\Models\FeatureRollout\FeatureRolloutFeatureCopy;
use App\Services\Features\AgencyFeatureService;

/**
 * The wording the rollout pop-ups show for a feature (spec agency-feature-rollout.md §19.3–19.4): a dev's
 * edit from Dev Settings → Feature wording & videos where there is one, otherwise the defaults — the
 * registry's one-line `explain` and the full explanation in config/feature-rollout-copy.php.
 * Platform-wide product copy, the same for every agency.
 */
class FeatureRolloutCopy
{
    /** @var array<string, FeatureRolloutFeatureCopy>|null */
    private ?array $edits = null;

    public function __construct(private readonly AgencyFeatureService $features)
    {
    }

    /**
     * @return array{key:string, label:string, summary:string, paragraphs:list<string>, video_url:?string,
     *               links:list<array{label:string,url:string}>, edited:bool}
     */
    public function for(string $key): array
    {
        $edit = $this->edits()[$key] ?? null;
        $summary = trim((string) ($edit?->summary ?? '')) ?: $this->defaultSummary($key);
        $description = trim((string) ($edit?->description ?? '')) ?: $this->defaultDescription($key);

        return [
            'key'        => $key,
            'label'      => (string) ($this->features->catalogue()[$key]['label'] ?? $key),
            'summary'    => $summary,
            'paragraphs' => self::paragraphs($description),
            'video_url'  => $edit?->video_url ?: null,
            'links'      => array_values(array_filter((array) ($edit?->links ?? []), fn ($l) => ! empty($l['label']) && ! empty($l['url']))),
            'edited'     => $edit !== null,
        ];
    }

    public function defaultSummary(string $key): string
    {
        return (string) ($this->features->catalogue()[$key]['explain'] ?? '');
    }

    /** The shipped full explanation; falls back to the one-liner so a feature is never blank. */
    public function defaultDescription(string $key): string
    {
        return (string) (config('feature-rollout-copy.descriptions.' . $key) ?: $this->defaultSummary($key));
    }

    /** @return list<string> blank-line separated paragraphs */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\R\s*\R/", trim($text)) ?: []), fn ($p) => $p !== ''));
    }

    /** Forget the per-request cache (after a save). */
    public function flush(): void
    {
        $this->edits = null;
    }

    /** @return array<string, FeatureRolloutFeatureCopy> */
    private function edits(): array
    {
        return $this->edits ??= FeatureRolloutFeatureCopy::all()->keyBy('feature_key')->all();
    }
}
