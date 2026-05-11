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
}
