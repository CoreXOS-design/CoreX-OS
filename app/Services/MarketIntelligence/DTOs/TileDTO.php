<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\DTOs;

/**
 * One row in the "This Week" hero block on the MIC Work tab.
 *
 * Deterministic for now (Phase D2). The same DTO shape will carry AI-narrated
 * sentences in Phase E1 — the only field that flips is `sentence`. Everything
 * else (id, emoji, number, urgency, action_label, action_url) stays in the
 * deterministic builder so the AI never invents counts or destinations.
 *
 * Spec: .ai/specs/mic-complete-spec.md §6.1.
 */
final class TileDTO
{
    public function __construct(
        public readonly string $id,            // 'matches' | 'expiring' | 'stale_sellers' | 'pocket' | 'new_listings'
        public readonly string $emoji,
        public readonly string $sentence,      // deterministic template; AI replaces in E1
        public readonly int $number,           // headline count — drives "show only if > 0"
        public readonly string $urgency,       // 'red' | 'orange' | 'blue' | 'green' | 'neutral'
        public readonly string $actionLabel,
        public readonly string $actionUrl,
    ) {}

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'emoji'        => $this->emoji,
            'sentence'     => $this->sentence,
            'number'       => $this->number,
            'urgency'      => $this->urgency,
            'action_label' => $this->actionLabel,
            'action_url'   => $this->actionUrl,
        ];
    }
}
