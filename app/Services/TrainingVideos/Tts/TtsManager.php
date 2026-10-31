<?php

namespace App\Services\TrainingVideos\Tts;

/** AT-525 - picks the voice named in config training-videos.auto.tts.driver. Add a paid provider here (one branch) and set TRAINING_TTS_DRIVER. */
class TtsManager
{
    public function provider(): TtsProvider
    {
        return match ((string) config('training-videos.auto.tts.driver', 'piper')) {
            'fake' => new FakeTtsProvider(),
            'piper' => new PiperTtsProvider((array) config('training-videos.auto.tts.piper')),
            default => throw new \InvalidArgumentException('Unknown voice driver: ' . config('training-videos.auto.tts.driver')),
        };
    }
}
