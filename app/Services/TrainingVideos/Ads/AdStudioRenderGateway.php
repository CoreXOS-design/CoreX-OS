<?php

namespace App\Services\TrainingVideos\Ads;

use App\Services\AdStudio\AdRenderGateway;
use Illuminate\Support\Facades\Storage;

/**
 * AT-525 / AT-527 (spec 9.16.2 point 1) - the adapter that connects Ad Studio's AdRenderGateway to the renderer (AdRenderer). `queue()` turns a refused request into ONE plain
 * sentence (the gateway's contract); `status()` renames the renderer's fields to the gateway's and gives the files as ABSOLUTE paths, which Ad Studio streams through its own
 * owner-only routes.
 */
final class AdStudioRenderGateway implements AdRenderGateway
{
    public function __construct(private readonly AdRenderer $renderer)
    {
    }

    public function queue(array $request): string
    {
        try {
            return $this->renderer->queue($request);
        } catch (AdRequestInvalid $e) {
            $first = $e->problems[0] ?? 'The ad could not be checked.';
            $more = count($e->problems) - 1;

            throw new \RuntimeException($more > 0 ? $first . ' (and ' . $more . ' more thing' . ($more === 1 ? '' : 's') . ' to fix)' : $first);
        }
    }

    public function status(string $batchId): array
    {
        $s = $this->renderer->status($batchId);
        $disk = Storage::disk((string) config('training-videos.auto.disk', 'local'));
        $files = [];
        foreach ($s['files'] as $format => $f) {
            $files[$format] = ['mp4' => $disk->path((string) $f['mp4']), 'poster' => $disk->path((string) $f['poster']), 'seconds' => $f['seconds'], 'width' => $f['width'], 'height' => $f['height'], 'size' => $f['size']];
        }

        return ['state' => $s['status'], 'percent' => $s['percent'], 'stage' => $s['stage'], 'failure' => $s['failure_reason'], 'files' => $files];
    }
}
