<?php

namespace App\Jobs;

use App\Services\Minion\MinionCaptureRunner;
use App\Support\Minion\MinionAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// AT-284 — "run now" from the setup page runs off the queue (Puppeteer + polite pacing take minutes).
class MinionRunAreaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public int $agencyId,
        public ?string $town = null,
        public ?int $suburbId = null,
        public ?int $userId = null,
    ) {}

    public function handle(MinionCaptureRunner $runner): void
    {
        $runs = $this->suburbId
            ? [$runner->captureSuburb($this->agencyId, $this->suburbId, 'manual', $this->userId)]
            : $runner->captureTown($this->agencyId, (string) $this->town, 'manual', $this->userId);

        if (array_filter($runs, fn ($r) => $r->status === 'failed')) {
            MinionAlerts::failures($this->agencyId, $runs);
        }
    }
}
