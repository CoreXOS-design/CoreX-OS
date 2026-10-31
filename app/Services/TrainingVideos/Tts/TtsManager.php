<?php

namespace App\Services\TrainingVideos\Tts;

/**
 * AT-525 - picks the speaker. `training-videos.auto.tts.driver` = 'fake' (tests) or 'real'; for a real voice the catalogue says which engine speaks it (Kokoro or Piper;
 * a cloud engine is one more class and one more branch here - spec 9.18). If the chosen voice's engine is not installed on this server the LAST-RESORT fallback voice
 * speaks instead (logged); a runtime error is never swapped for another voice.
 */
class TtsManager
{
    public function provider(?string $voiceKey = null): TtsProvider
    {
        $driver = (string) config('training-videos.auto.tts.driver', 'piper');
        if ($driver === 'fake') {
            return new FakeTtsProvider();
        }
        if (! in_array($driver, ['piper', 'kokoro', 'real'], true)) {
            throw new \InvalidArgumentException('Unknown voice driver: ' . $driver);
        }
        $voices = app(TrainingVoices::class);
        $v = $voices->find($voiceKey ?: $voices->defaultKey()) ?? $voices->find($voices->defaultKey());
        if (! $v['installed'] && ($fb = $voices->find($voices->fallbackKey())) && $fb['installed'] && $fb['key'] !== $v['key']) {
            \Log::warning('Training voice engine not installed here - using the fallback voice', ['wanted' => $v['key'], 'using' => $fb['key']]);
            $v = $fb;
        }

        return match ($v['provider']) {
            'kokoro' => new KokoroTtsProvider($voices->kokoroConfig($v['key'])),
            default => new PiperTtsProvider($voices->piperConfig($v['key'])),
        };
    }
}
