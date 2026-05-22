<?php

declare(strict_types=1);

namespace App\Services\Compliance\Rcr;

/**
 * Phase 9d — one result per question attempted by EvidenceGatheringService.
 *
 * Discriminate via $populated: true means we wrote an answer; false means
 * either source not implemented OR no data available (see $error).
 *
 * $data is the raw evidence the service pulled; it gets stored on
 * rcr_answers.auto_population_source_data alongside a timestamp so the
 * compliance officer can audit "what was pulled when".
 */
final class AutoPopulationResult
{
    public function __construct(
        public readonly int $questionId,
        public readonly string $source,
        public readonly bool $populated,
        public readonly mixed $value = null,
        public readonly array $data = [],
        public readonly ?string $error = null,
        public readonly bool $skipped = false,
        public readonly ?string $skippedReason = null,
    ) {}

    public function toLogArray(): array
    {
        return [
            'question_id' => $this->questionId,
            'source'      => $this->source,
            'populated'   => $this->populated,
            'value'       => $this->value,
            'data'        => $this->data,
            'error'       => $this->error,
            'skipped'     => $this->skipped,
            'skipped_reason' => $this->skippedReason,
            'pulled_at'   => now()->toIso8601String(),
        ];
    }
}
