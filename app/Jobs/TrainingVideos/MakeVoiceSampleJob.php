<?php

namespace App\Jobs\TrainingVideos;

use App\Services\TrainingVideos\Tts\VoiceSamples;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** AT-525 - make a voice's picker sample in the background (a CoreX / Chatterbox voice takes a minute or two on the CPU), once; the picker enables Play when it exists. */
class MakeVoiceSampleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1200;
    public int $tries = 1;

    public function __construct(public readonly string $voiceKey)
    {
        $this->onQueue((string) config('training-videos.queue', 'training-videos'));
    }

    public function handle(VoiceSamples $samples): void
    {
        $samples->ensure($this->voiceKey);
    }
}
