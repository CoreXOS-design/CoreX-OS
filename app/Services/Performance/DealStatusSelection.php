<?php

namespace App\Services\Performance;

use Illuminate\Http\Request;

/**
 * Agency Performance & ROI — which deal statuses the report is counting.
 *
 * One value, read once from the URL (`?status[]=pending&status[]=granted…`), handed to
 * every deal-based figure on the page (tiles, headline, deal cards, branch/agent
 * columns, drilldown, print) and to BOTH the main and the comparison period, so the
 * ticks can never drive only part of the page and every delta compares like with like.
 *
 * Absent from the URL = the default: Pending + Granted + Registered. Declined deals
 * are not commission the agency earned, so they are left out unless ticked.
 * `status[]=none` is the explicit "nothing ticked" selection (a report of zero deals).
 *
 * It only ever filters rows already inside the viewer's own / branch / agency cohort —
 * it is applied after the cohort is resolved and can never widen it.
 *
 * See .ai/specs/at366-period-comparison.md §Deal-status ticks.
 */
final class DealStatusSelection
{
    public const BUCKETS = ['pending', 'granted', 'registered', 'declined'];
    public const DEFAULT = ['pending', 'granted', 'registered'];

    /** @param string[] $buckets */
    private function __construct(private readonly array $buckets) {}

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    public static function all(): self
    {
        return new self(self::BUCKETS);
    }

    /** @param string[] $buckets unknown names are ignored; order follows BUCKETS */
    public static function of(array $buckets): self
    {
        return new self(array_values(array_intersect(self::BUCKETS, $buckets)));
    }

    /**
     * Absent `status` key (or nothing usable in it) = the default. `all` = every bucket,
     * `none` = no bucket. A single string is accepted as well as the array form.
     */
    public static function fromRequest(Request $request): self
    {
        if (!$request->query->has('status')) {
            return self::default();
        }

        $raw = array_map(fn ($v) => strtolower(trim((string) $v)), (array) $request->query('status'));

        if (in_array('all', $raw, true)) {
            return self::all();
        }

        $picked = array_values(array_intersect(self::BUCKETS, $raw));
        if ($picked !== []) {
            return new self($picked);
        }

        return in_array('none', $raw, true) ? new self([]) : self::default();
    }

    public function has(string $bucket): bool
    {
        return in_array($bucket, $this->buckets, true);
    }

    /** @return string[] */
    public function buckets(): array
    {
        return $this->buckets;
    }

    public function isEverything(): bool
    {
        return count($this->buckets) === count(self::BUCKETS);
    }

    public function isDefault(): bool
    {
        return $this->buckets === self::DEFAULT;
    }

    /** Query-string fragment that reproduces this selection ([] when it is the default, which needs none). */
    public function toQuery(): array
    {
        if ($this->isDefault()) {
            return [];
        }

        return ['status' => $this->buckets === [] ? ['none'] : $this->buckets];
    }

    /** "Pending, Granted, Registered" — for the one line that tells the reader what is being counted. */
    public function label(): string
    {
        if ($this->buckets === []) {
            return 'no deals (every status unticked)';
        }

        return implode(', ', array_map('ucfirst', $this->buckets));
    }
}
