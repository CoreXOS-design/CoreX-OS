<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Score;

use Carbon\CarbonInterface;

/** One file's opportunity score (contract 11.1): 0-100, its five parts, and the top two reasons. Viewer-neutral. */
final class ScoreResult
{
    /**
     * @param  array{seller:int,buyer:int,pocket:int,known:int,reachable:int}  $parts  each 0-100
     * @param  list<array{code:string,label:string}>  $reasons  at most two, strongest first
     */
    public function __construct(
        public readonly float $score,
        public readonly array $parts,
        public readonly array $reasons,
        public readonly CarbonInterface $computedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['score' => $this->score, 'parts' => $this->parts, 'reasons' => $this->reasons, 'computed_at' => $this->computedAt->toIso8601String()];
    }
}
