<?php

namespace App\Jobs\TrainingVideos;

use App\Services\TrainingVideos\Auto\TourAudio;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** AT-526 - makes the missing voice clips of one screen's narrated tour in one voice (queue `training-videos`, never inside a web request). */
class MakeTourAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public string $screen, public string $voiceKey, public bool $force = false)
    {
        $this->onQueue((string) config('training-videos.queue'));
    }

    /** Ask for the clips once: a screen + voice already being made is not queued twice (the lock lapses after 30 minutes). */
    public static function ensure(string $screen, string $voiceKey): bool
    {
        if (! Cache::add('tour-audio-making:' . $screen . ':' . $voiceKey, 1, now()->addMinutes(30))) {
            return false;
        }
        static::dispatch($screen, $voiceKey);

        return true;
    }

    public function handle(TourAudio $audio, TrainingScriptRepository $scripts): void
    {
        try {
            $script = $scripts->find($this->screen);
            if ($script && $script->narrated()) {
                $audio->make($script, $this->voiceKey, $this->force);
            }
        } finally {
            Cache::forget('tour-audio-making:' . $this->screen . ':' . $this->voiceKey);
        }
    }
}
