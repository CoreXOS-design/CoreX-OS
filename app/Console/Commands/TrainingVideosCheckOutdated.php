<?php

namespace App\Console\Commands;

use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Auto\SourceHasher;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use Illuminate\Console\Command;

/** AT-525 - list the generated videos whose script, tour or spec has changed since they were made. */
class TrainingVideosCheckOutdated extends Command
{
    protected $signature = 'training-videos:check-outdated';

    protected $description = 'List auto-generated how-to videos that are out of date (their script, tour or spec changed)';

    public function handle(TrainingScriptRepository $scripts, SourceHasher $hasher): int
    {
        $rows = [];
        foreach ($scripts->all() as $s) {
            $v = TrainingVideo::where('source', 'generated')->where('screen_key', $s->key())->first();
            $state = ! $v || $v->status !== 'ready' ? 'no video yet' : ($hasher->isOutOfDate($v, $s) ? 'OUT OF DATE' : 'current');
            $rows[] = [$s->key(), $s->approved() ? 'approved' : 'draft', $state, $v?->generated_at?->format('j M Y H:i') ?? '-'];
        }
        $this->table(['Screen', 'Script', 'Video', 'Made'], $rows);

        return in_array('OUT OF DATE', array_column($rows, 2), true) ? self::FAILURE : self::SUCCESS;
    }
}
