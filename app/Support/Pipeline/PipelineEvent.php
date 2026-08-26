<?php

namespace App\Support\Pipeline;

use Carbon\CarbonInterface;

/**
 * Pipeline Dashboard Phase 1 — the normalized event DTO. One shape for every source (comments now;
 * email + WhatsApp later) so the activity lane renders a single stream. This is the read-model event
 * "model" (decision 1: a normalizer, NOT a physical table). New sources produce this DTO without any
 * change to the contract. Spec: .ai/specs/pipeline-dashboard.md §3.3
 */
final class PipelineEvent
{
    public const SCOPE_DEAL = 'deal';
    public const SCOPE_STEP = 'step';

    public function __construct(
        /** comment | email | whatsapp | … (the source kind) */
        public readonly string $type,
        public readonly CarbonInterface $occurredAt,
        /** self::SCOPE_DEAL | self::SCOPE_STEP */
        public readonly string $scope,
        /** the deal_step_instances.id when scope=step, else null */
        public readonly ?int $stepId,
        /** inbound | outbound | null (comments have no direction; comms do) */
        public readonly ?string $direction,
        public readonly ?int $authorId,
        public readonly ?string $authorName,
        public readonly string $body,
        /** provenance — the origin table + row (never rendered; for auditing/dedup) */
        public readonly string $sourceType,
        public readonly int $sourceId,
    ) {
    }

    public function isStepScoped(): bool
    {
        return $this->scope === self::SCOPE_STEP && $this->stepId !== null;
    }

    /** A stable, source-qualified key (dedup / DOM key). */
    public function key(): string
    {
        return $this->sourceType . ':' . $this->sourceId;
    }

    /** @return array<string,mixed> a plain payload for a JSON/timeline consumer. */
    public function toArray(): array
    {
        return [
            'key'         => $this->key(),
            'type'        => $this->type,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'scope'       => $this->scope,
            'step_id'     => $this->stepId,
            'direction'   => $this->direction,
            'author_id'   => $this->authorId,
            'author_name' => $this->authorName,
            'body'        => $this->body,
        ];
    }
}
