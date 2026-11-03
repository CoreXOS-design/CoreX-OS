<?php

namespace App\Console\Commands;

use App\Jobs\TrainingVideos\GenerateTrainingVideoJob;
use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Auto\SourceHasher;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingScriptValidator;
use App\Services\TrainingVideos\Auto\TrainingVideoGenerator;
use Illuminate\Console\Command;

/** AT-525 - generate how-to videos from approved screen scripts (spec .ai/specs/training-videos.md section 9). */
class TrainingVideosGenerate extends Command
{
    protected $signature = 'training-videos:generate {key? : the screen key (omit with --outdated / --all)} {--sync : run now in this process instead of queueing} {--outdated : every approved script whose video is missing or out of date} {--check : only validate the script(s), record nothing}';

    protected $description = 'Make (or re-make) the auto-generated how-to video of a screen';

    public function handle(TrainingScriptRepository $scripts, TrainingScriptValidator $validator, TrainingVideoGenerator $generator, SourceHasher $hasher): int
    {
        $targets = [];
        if ($this->argument('key')) {
            $s = $scripts->find($this->argument('key'));
            if (! $s) {
                $this->error("No script '{$this->argument('key')}' in " . $scripts->dir());

                return self::FAILURE;
            }
            $targets[] = $s;
        } else {
            foreach ($scripts->all() as $s) {
                $v = TrainingVideo::where('source', 'generated')->where('screen_key', $s->key())->first();
                if ($s->approved() && (! $this->option('outdated') || ! $v || $v->status !== 'ready' || $hasher->isOutOfDate($v, $s))) {
                    $targets[] = $s;
                }
            }
        }
        $bad = 0;
        foreach ($targets as $s) {
            $p = $validator->problems($s);
            if ($p) {
                $bad++;
                $this->error("{$s->key()}:\n - " . implode("\n - ", $p));
                continue;
            }
            if ($this->option('check')) {
                $this->info("{$s->key()}: ok (about " . (int) round($validator->estimateSeconds($s)) . ' s)');
                continue;
            }
            if ($this->option('sync')) {
                $this->info("Making {$s->key()} ...");
                $v = $generator->generate($s);
                $this->info("  ready: {$v->durationLabel()}, {$v->sizeSummary()}, voice {$v->tts_voice}");
            } else {
                GenerateTrainingVideoJob::dispatch($s->key());
                $this->info("{$s->key()}: queued on the training-videos lane.");
            }
        }
        if (! $targets) {
            $this->line('Nothing to do.');
        }

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
