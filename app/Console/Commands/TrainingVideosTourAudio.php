<?php

namespace App\Console\Commands;

use App\Services\TrainingVideos\Auto\TourAudio;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use Illuminate\Console\Command;

/** AT-526 - make (or list) the voice clips of the narrated guided tours. Only scripts marked `narrated` are touched. */
class TrainingVideosTourAudio extends Command
{
    protected $signature = 'training-videos:tour-audio {screen? : a screen key; omit with --all}
                            {--all : every narrated screen}
                            {--voice= : a voice key (default: the platform default voice)}
                            {--force : re-make every clip even if it is current}
                            {--check : only list what is missing, make nothing}';

    protected $description = 'Make the per-step voice clips of the narrated guided tours (one mp3 per step, per voice, regenerated when the words change)';

    public function handle(TrainingScriptRepository $scripts, TourAudio $audio, TrainingVoices $voices): int
    {
        $voice = (string) ($this->option('voice') ?: $voices->defaultKey());
        $list = $this->option('all')
            ? array_filter($scripts->all(), fn ($s) => $s->narrated())
            : array_filter([$scripts->find((string) $this->argument('screen'))]);
        if ($list === []) {
            $this->error('Name a narrated screen or use --all.');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($list as $s) {
            if (! $s->narrated()) {
                $this->warn($s->key() . ' is not marked narrated - skipped.');
                continue;
            }
            $missing = $audio->missing($s, $voice);
            $made = [];
            if (! $this->option('check') && ($missing !== [] || $this->option('force'))) {
                $made = $audio->make($s, $voice, (bool) $this->option('force'));
            }
            $rows[] = [$s->key(), $voice, count($audio->lines($s)), $this->option('check') ? count($missing) : count($made), $audio->ready($s, $voice) ? 'ready' : 'missing: ' . implode(',', $audio->missing($s, $voice))];
        }
        $this->table(['Screen', 'Voice', 'Clips', $this->option('check') ? 'Missing' : 'Made now', 'State'], $rows);

        return self::SUCCESS;
    }
}
