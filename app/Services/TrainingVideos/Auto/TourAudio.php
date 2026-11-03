<?php

namespace App\Services\TrainingVideos\Auto;

use App\Services\TrainingVideos\Tts\BatchTtsProvider;
use App\Services\TrainingVideos\Tts\TtsManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * AT-526 (spec .ai/specs/training-videos.md section 9.23) - the voice clips of the narrated guided tour: ONE mp3 per step, in one voice, made once and shared by
 * every agency on that voice. Clip 0 = the script's intro, 1..N = each step ("what to do" then "why", as one clip), N+1 = the outro.
 *
 * The file name carries a hash of the spoken words and the voice (`<n>-<hash8>.mp3`), so a clip is re-made ONLY when its words or its voice change - the same
 * out-of-date idea as the video's source hash. Files live on the private disk (training-videos.auto.disk, on the data volume) under
 * `training-videos-auto/tour-audio/<screen>/<voice>/` and are served only through an authenticated route; there is no per-play cost and no outside service.
 */
class TourAudio
{
    public function __construct(private readonly TtsManager $tts)
    {
    }

    /** The words of every clip, keyed by clip number. @return array<int,string> */
    public function lines(TrainingScript $s): array
    {
        $lines = [];
        if ($s->intro() !== '') {
            $lines[0] = $s->intro();
        }
        foreach ($s->steps() as $i => $step) {
            $lines[$i + 1] = TrainingScript::spoken($step);
        }
        if ($s->outro() !== '') {
            $lines[count($s->steps()) + 1] = $s->outro();
        }

        return array_filter($lines, fn ($t) => trim($t) !== '');
    }

    public function fileName(int $n, string $text, string $voiceKey): string
    {
        return $n . '-' . substr(sha1($text . '|' . $voiceKey), 0, 8) . '.mp3';
    }

    public function folder(string $screen, string $voiceKey): string
    {
        return trim((string) config('training-videos.auto.folder', 'training-videos-auto'), '/') . '/tour-audio/' . $screen . '/' . $voiceKey;
    }

    private function disk()
    {
        return Storage::disk((string) config('training-videos.auto.disk', 'local'));
    }

    /** The stored path of clip $n for these words and this voice, or null when it has not been made yet (or the words have changed since). */
    public function pathFor(TrainingScript $s, int $n, string $voiceKey): ?string
    {
        $text = $this->lines($s)[$n] ?? null;
        if ($text === null) {
            return null;
        }
        $path = $this->folder($s->key(), $voiceKey) . '/' . $this->fileName($n, $text, $voiceKey);

        return $this->disk()->exists($path) ? $path : null;
    }

    public function absolute(string $path): string
    {
        return $this->disk()->path($path);
    }

    /** Clip numbers that are missing or out of date for this voice. @return array<int,int> */
    public function missing(TrainingScript $s, string $voiceKey): array
    {
        return array_values(array_filter(array_keys($this->lines($s)), fn ($n) => $this->pathFor($s, $n, $voiceKey) === null));
    }

    /** Is every clip of the screen ready in this voice? */
    public function ready(TrainingScript $s, string $voiceKey): bool
    {
        return $this->missing($s, $voiceKey) === [];
    }

    /**
     * Make the missing / out-of-date clips (all of them with $force) and drop the stale files. Returns the clip numbers that were made.
     *
     * @return array<int,int>
     */
    public function make(TrainingScript $s, string $voiceKey, bool $force = false): array
    {
        $lines = $this->lines($s);
        $todo = $force ? array_keys($lines) : $this->missing($s, $voiceKey);
        $folder = $this->folder($s->key(), $voiceKey);
        $disk = $this->disk();
        $disk->makeDirectory($folder);

        if ($todo !== []) {
            $work = (string) config('training-videos.auto.work_dir', storage_path('app/training-videos-work')) . '/tour-audio-' . uniqid();
            File::ensureDirectoryExists($work);
            try {
                $provider = $this->tts->provider($voiceKey);
                $jobs = [];
                foreach ($todo as $n) {
                    $jobs[$n] = ['text' => $lines[$n], 'out' => $work . '/' . $n . '.wav'];
                }
                $seconds = [];
                if ($provider instanceof BatchTtsProvider) {
                    $res = $provider->synthesizeMany(array_values($jobs));
                    foreach (array_keys($jobs) as $k => $n) {
                        $seconds[$n] = (float) ($res[$k] ?? 0);
                    }
                } else {
                    foreach ($jobs as $n => $j) {
                        $seconds[$n] = $provider->synthesize($j['text'], $j['out']);
                    }
                }
                foreach ($jobs as $n => $j) {
                    $mp3 = $work . '/' . $n . '.mp3';
                    $this->toMp3($j['out'], $mp3);
                    $disk->putFileAs($folder, new \Illuminate\Http\File($mp3), $this->fileName($n, $lines[$n], $voiceKey));
                }
            } finally {
                File::deleteDirectory($work);
            }
        }

        // stale clips: a number whose words (or the script's length) changed
        $keep = [];
        foreach ($lines as $n => $text) {
            $keep[] = $this->fileName($n, $text, $voiceKey);
        }
        foreach ($disk->files($folder) as $f) {
            if (str_ends_with($f, '.mp3') && ! in_array(basename($f), $keep, true)) {
                $disk->delete($f);
            }
        }
        $disk->put($folder . '/manifest.json', json_encode([
            'screen' => $s->key(), 'voice' => $voiceKey, 'built_at' => now()->toIso8601String(),
            'clips' => array_map(fn ($n) => ['n' => $n, 'file' => $this->fileName($n, $lines[$n], $voiceKey), 'words' => str_word_count($lines[$n])], array_keys($lines)),
        ], JSON_PRETTY_PRINT));

        return $todo;
    }

    private function toMp3(string $wav, string $mp3): void
    {
        $p = new Process([(string) config('training-videos.ffmpeg', 'ffmpeg'), '-y', '-v', 'error', '-i', $wav, '-codec:a', 'libmp3lame', '-q:a', '4', '-ac', '1', $mp3]);
        $p->setTimeout(120);
        $p->run();
        if (! $p->isSuccessful() || ! is_file($mp3)) {
            throw new \RuntimeException('ffmpeg could not make the tour clip: ' . trim(substr($p->getErrorOutput(), -300)));
        }
    }
}
