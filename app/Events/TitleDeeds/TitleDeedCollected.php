<?php

declare(strict_types=1);

namespace App\Events\TitleDeeds;

use App\Events\AbstractDomainEvent;

/**
 * .ai/specs/title-deed-register.md §8.4 — fires once a title deed has been handed over (after the handover transaction
 * committed). Subscribers tell the responsible agent and every owner. Carries ids, not models: the listener reloads.
 *
 * Slug: title_deed.collected
 */
final class TitleDeedCollected extends AbstractDomainEvent
{
    public function __construct(
        public readonly int $titleDeedId,
        public readonly int $receiptId,
        public readonly ?int $agencyIdValue,
        public readonly ?int $actorUserIdValue,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int
    {
        return $this->agencyIdValue;
    }

    public function actorUserId(): ?int
    {
        return $this->actorUserIdValue;
    }

    public function subject(): ?array
    {
        return [\App\Models\TitleDeeds\TitleDeed::class, $this->titleDeedId];
    }

    public function context(): array
    {
        return ['receipt_id' => $this->receiptId];
    }
}
