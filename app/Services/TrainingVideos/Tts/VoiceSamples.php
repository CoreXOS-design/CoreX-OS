<?php

namespace App\Services\TrainingVideos\Tts;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * AT-525 (spec section 9.12) - the short sample every voice speaks in the Training library's voice picker: the SAME two plain sentences for every voice
 * (config training-videos.auto.sample_text), kept as small mp3s on the private disk beside the videos. A sample is made once and re-made only when the sentences change.
 */
class VoiceSamples
{
    public function __construct(private readonly TtsManager $tts, private readonly TrainingVoices $voices)
    {
    }

    public function path(string $key): string
    {
        return rtrim((string) config('training-videos.auto.folder'), '/') . '/voice-samples/' . $key . '-' . substr(sha1((string) config('training-videos.auto.sample_text')), 0, 8) . '.mp3';
    }

    private function disk()
    {
        return Storage::disk((string) config('training-videos.auto.disk', 'local'));
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($this->path($key));
    }

    /** Make the sample if it is not there; returns the storage path, or null when the voice cannot speak (model missing, driver down). */
    public function ensure(string $key): ?string
    {
        if (! $this->voices->find($key)) {
            return null;
        }
        if ($this->exists($key)) {
            return $this->path($key);
        }
        $work = rtrim((string) config('training-videos.auto.work_dir'), '/') . '/sample-' . $key . '-' . uniqid();
        File::ensureDirectoryExists($work);
        try {
            $wav = $work . '/s.wav';
            $this->tts->provider($key)->synthesize((string) config('training-videos.auto.sample_text'), $wav);
            $mp3 = $work . '/s.mp3';
            $p = new Process(['ffmpeg', '-y', '-v', 'error', '-i', $wav, '-ac', '1', '-b:a', '64k', $mp3]);
            $p->setTimeout(60);
            $p->run();
            $src = $p->isSuccessful() && is_file($mp3) ? $mp3 : null;
            if ($src === null) {
                return null;
            }
            $this->disk()->put($this->path($key), (string) file_get_contents($src));

            return $this->path($key);
        } catch (\Throwable $e) {
            \Log::warning('Training voice sample failed', ['voice' => $key, 'error' => $e->getMessage()]);

            return null;
        } finally {
            File::deleteDirectory($work);
        }
    }
}
