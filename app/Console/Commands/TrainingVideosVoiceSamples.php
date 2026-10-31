<?php

namespace App\Console\Commands;

use App\Services\TrainingVideos\Tts\TrainingVoices;
use App\Services\TrainingVideos\Tts\VoiceSamples;
use Illuminate\Console\Command;

/** AT-525 - make the voice-picker samples (the same two sentences in every voice) and print each one's length, so a voice that fails or runs oddly short/long shows up. */
class TrainingVideosVoiceSamples extends Command
{
    protected $signature = 'training-videos:voice-samples {--force : make them again even if they exist}';
    protected $description = 'Make the Training library voice-picker samples (same two sentences, every voice).';

    public function handle(VoiceSamples $samples, TrainingVoices $voices): int
    {
        $bad = 0;
        foreach ($voices->listed() as $key => $v) {
            if (! $v['installed']) {
                $this->warn("{$key}: model {$v['model']} is not installed");
                $bad++;
                continue;
            }
            if ($this->option('force') && $samples->exists($key)) {
                \Storage::disk((string) config('training-videos.auto.disk', 'local'))->delete($samples->path($key));
            }
            $t = microtime(true);
            $path = $samples->ensure($key);
            if (! $path) {
                $this->error("{$key}: FAILED");
                $bad++;
                continue;
            }
            $abs = \Storage::disk((string) config('training-videos.auto.disk', 'local'))->path($path);
            $secs = trim((string) shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($abs)));
            $this->line(sprintf('%-9s %-34s %5.1fs audio, made in %.1fs, %d KB', $key, $v['provider'] . ':' . $v['model'] . ($v['speaker'] !== null ? '#' . $v['speaker'] : ''), (float) $secs, microtime(true) - $t, round(filesize($abs) / 1024)));
        }

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
