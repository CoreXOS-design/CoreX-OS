<?php

namespace App\Jobs\TrainingVideos;

use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingVideoGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * AT-525 - make (or re-make) one screen's how-to video, on the `training-videos` lane AT-509 already gave a worker (nice 19; a recording + voice + encode is a few
 * minutes of CPU). A failure keeps the old video playing and writes the reason on the row (TrainingVideoGenerator).
 */
class GenerateTrainingVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1500;
    public int $tries = 1;

    public function __construct(public readonly string $screenKey, public readonly ?int $byUserId = null)
    {
        $this->onQueue((string) config('training-videos.queue', 'training-videos'));
    }

    public function handle(TrainingScriptRepository $scripts, TrainingVideoGenerator $generator): void
    {
        $script = $scripts->find($this->screenKey);
        if (! $script) {
            throw new \RuntimeException("No training script '{$this->screenKey}'.");
        }
        $generator->generate($script, $this->byUserId);
    }
}
