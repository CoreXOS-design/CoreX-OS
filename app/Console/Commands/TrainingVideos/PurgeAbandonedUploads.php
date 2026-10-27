<?php

namespace App\Console\Commands\TrainingVideos;

use App\Models\TrainingVideo;
use App\Services\TrainingVideos\ChunkedUploadService;
use Illuminate\Console\Command;

/**
 * Removes pieces of training-video uploads that were never finished or never saved.
 *
 * Spec: .ai/specs/training-videos.md §8 U11. These are temporary files, not records — no
 * video row exists for them until the owner saves the form. An upload a video is still
 * waiting on (being shrunk, or failed and awaiting "Try again") is never touched.
 */
class PurgeAbandonedUploads extends Command
{
    protected $signature = 'training-videos:purge-abandoned-uploads';

    protected $description = 'Delete unfinished training-video upload pieces older than the configured age';

    public function handle(ChunkedUploadService $uploads): int
    {
        $keep = TrainingVideo::withTrashed()
            ->whereNotNull('pending_upload_id')
            ->pluck('pending_upload_id')
            ->all();

        $removed = $uploads->purgeAbandoned((int) config('training-videos.abandoned_after_hours'), $keep);

        $this->info("Removed {$removed} abandoned upload(s).");

        return self::SUCCESS;
    }
}
