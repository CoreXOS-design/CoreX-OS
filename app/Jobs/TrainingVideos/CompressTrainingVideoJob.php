<?php

namespace App\Jobs\TrainingVideos;

use App\Models\TrainingVideo;
use App\Services\TrainingVideos\ChunkedUploadService;
use App\Services\TrainingVideos\UnreadableVideoException;
use App\Services\TrainingVideos\VideoCompressor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Shrinks an uploaded training video in the background and publishes it.
 *
 * Spec: .ai/specs/training-videos.md §8 U1, U2, U5, U6, U9.
 *
 * Runs on its own `training-videos` queue (own low-priority worker) so a long compress can
 * never hold up e-mail, matching or anything an agent is waiting on.
 *
 * - Success: the compressed copy is written, the row flips to Ready, the original upload is
 *   discarded (U2) and — when this replaced an earlier file — the old copy is removed (U9).
 * - Failure: the row says why in plain words; the uploaded original is KEPT so "Try again"
 *   needs no re-upload. For a replacement the old file simply keeps playing.
 */
class CompressTrainingVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;

    public const GENERIC_FAILURE = 'CoreX could not shrink this video. Try again, or upload a different file.';
    public const MISSING_FILE    = 'The uploaded file is no longer on the server. Upload it again.';

    public function __construct(public int $videoId)
    {
        $this->onQueue((string) config('training-videos.queue'));
    }

    public function handle(VideoCompressor $compressor, ChunkedUploadService $uploads): void
    {
        $video = TrainingVideo::withTrashed()->find($this->videoId);
        if (! $video || ! $video->pending_upload_id) {
            return;
        }

        $uploadId = $video->pending_upload_id;
        if (! $uploads->exists($uploadId)) {
            $this->markFailed($video, self::MISSING_FILE);

            return;
        }

        $source = $uploads->assembledPath($uploadId);
        $name   = bin2hex(random_bytes(16));
        $mp4    = Storage::disk('public')->path($video->relativePath('mp4', $name));
        $jpg    = Storage::disk('public')->path($video->relativePath('jpg', $name));

        try {
            $probe = $compressor->probe($source);
            File::ensureDirectoryExists(dirname($mp4));
            $compressor->compress($source, $mp4, $jpg, $probe);

            $oldName = $video->file_name;
            $video->forceFill([
                'file_name'             => $name,
                'status'                => TrainingVideo::STATUS_READY,
                'failure_reason'        => null,
                'original_size_bytes'   => filesize($source),
                'size_bytes'            => filesize($mp4),
                'duration_seconds'      => $probe['duration'],
                'width'                 => $probe['width'],
                'height'                => $probe['height'],
                'pending_upload_id'     => null,
                'processing_started_at' => null,
            ])->save();

            // Archived while it was being shrunk: the new copy belongs in private storage.
            if ($video->trashed()) {
                $video->moveFiles(from: 'public', to: 'local');
            }

            TrainingVideo::deleteFiles($oldName);   // U9 — the old copy goes only now
            $uploads->delete($uploadId);            // U2 — the original is discarded
        } catch (UnreadableVideoException $e) {
            $this->cleanup($mp4, $jpg);
            $this->markFailed($video, $e->getMessage());
        } catch (Throwable $e) {
            $this->cleanup($mp4, $jpg);
            Log::channel('single')->error('Training video compression failed', [
                'video_id' => $video->id, 'error' => $e->getMessage(),
            ]);
            $this->markFailed($video, self::GENERIC_FAILURE);
        }
    }

    /** Timeout / worker kill: the handle() catch never runs, so say so here. */
    public function failed(Throwable $e): void
    {
        $video = TrainingVideo::withTrashed()->find($this->videoId);
        if ($video && $video->pending_upload_id) {
            $this->markFailed($video, self::GENERIC_FAILURE);
        }
    }

    /**
     * New upload: Failed, off the public page. Replacement: the old file keeps playing and
     * only the reason is recorded (U9). The uploaded original stays so "Try again" works.
     */
    private function markFailed(TrainingVideo $video, string $reason): void
    {
        $video->forceFill([
            'status'                => $video->file_name ? TrainingVideo::STATUS_READY : TrainingVideo::STATUS_FAILED,
            'failure_reason'        => mb_substr($reason, 0, 255),
            'processing_started_at' => null,
        ])->save();
    }

    private function cleanup(string ...$paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }
}
