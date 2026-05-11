<?php

namespace App\Services\Compliance;

use Carbon\Carbon;

class ReadinessReport
{
    public function __construct(
        public bool $ready,
        public ?Carbon $snapshotAt,
        public array $blockedBy,
        public array $nextActions,
        public array $checklist,
    ) {}

    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'snapshot_at' => $this->snapshotAt?->toIso8601String(),
            'blocked_by' => $this->blockedBy,
            'checklist' => $this->checklist,
        ];
    }
}
