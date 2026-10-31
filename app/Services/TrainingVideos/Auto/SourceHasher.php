<?php

namespace App\Services\TrainingVideos\Auto;

use App\Models\TrainingVideo;
use App\Support\Tours\TourRegistry;

/**
 * AT-525 - "is this video out of date?". A video remembers a hash of everything it was made from: the script file, the driver.js tour it explains and the
 * spec files its "why" came from (plus the voice). When any of them changes, the stored hash no longer matches and the library shows "Out of date -
 * regenerate". Same rule when a feature is built or changed: touch the tour / script / spec and the video is flagged by itself.
 */
class SourceHasher
{
    public function hash(TrainingScript $s): string
    {
        $parts = [(string) @file_get_contents($s->path ?: '/dev/null'), json_encode($s->data)];
        if ($s->tourKey() !== null) {
            $parts[] = json_encode(TourRegistry::find($s->tourKey()));
        }
        foreach ($s->specs() as $spec) {
            $parts[] = $spec . ':' . (string) @file_get_contents(base_path($spec));
        }
        $parts[] = 'voice:' . (string) config('training-videos.auto.tts.piper.voice');

        return sha1(implode("\n--\n", $parts));
    }

    public function isOutOfDate(TrainingVideo $video, ?TrainingScript $script): bool
    {
        return $script !== null && $video->source_hash !== null && ! hash_equals((string) $video->source_hash, $this->hash($script));
    }
}
