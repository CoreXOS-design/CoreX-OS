<?php

namespace App\Services\TrainingVideos\Tts;

use App\Jobs\TrainingVideos\GenerateTrainingVideoJob;
use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Auto\TourAudio;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;

/**
 * AT-525 (spec 9.24) - changing the ONE system voice. It is rare: the system owner picks a voice, and every approved how-to video (and with it the narrated tour's clips - they
 * share the per-line clip cache) is queued to be made again in it, on the `training-videos` queue (nice 19). The old videos keep playing until each new one is ready. Lines whose
 * words and voice are unchanged are never spoken again.
 */
class SystemVoiceChange
{
    public function __construct(private readonly TrainingVoices $voices, private readonly TrainingScriptRepository $scripts, private readonly TourAudio $audio)
    {
    }

    /** Set the voice and queue the regeneration. @return int videos queued */
    public function change(string $voiceKey, ?int $byUserId): int
    {
        $this->voices->useAsSystem($voiceKey);

        return $this->queueAll($voiceKey, $byUserId);
    }

    /** Queue every approved screen that has no video in this voice yet (re-queuing a screen already made, or being made, in it would be wasted work). */
    public function queueAll(string $voiceKey, ?int $byUserId): int
    {
        $n = 0;
        foreach ($this->scripts->all() as $script) {
            if (! $script->approved()) {
                continue;
            }
            $done = TrainingVideo::where('source', TrainingVideo::SOURCE_GENERATED)->where('screen_key', $script->key())->where('voice_key', $voiceKey)
                ->whereIn('status', [TrainingVideo::STATUS_READY, TrainingVideo::STATUS_PROCESSING])->whereNotNull('file_name')->exists();
            if (! $done) {
                GenerateTrainingVideoJob::dispatch($script->key(), $byUserId, $voiceKey);
                $n++;
            }
        }

        return $n;
    }

    /** @return array{voice:string,total:int,ready:int,making:int,failed:int,percent:int,in_progress:bool} how far the change has got */
    public function progress(): array
    {
        $key = $this->voices->systemKey();
        $approved = collect($this->scripts->all())->filter(fn ($s) => $s->approved());
        $rows = TrainingVideo::where('source', TrainingVideo::SOURCE_GENERATED)->where('voice_key', $key)->get()->keyBy('screen_key');
        $ready = $failed = $making = 0;
        foreach ($approved as $s) {
            $r = $rows[$s->key()] ?? null;
            if ($r && $r->status === TrainingVideo::STATUS_READY && $r->file_name) {
                $ready++;
            } elseif ($r && $r->failure_reason !== null) {
                $failed++;
            } else {
                $making++;   // queued, or being made right now
            }
        }
        $total = $approved->count();

        return ['voice' => $key, 'total' => $total, 'ready' => $ready, 'making' => $making, 'failed' => $failed, 'percent' => $total ? (int) round(100 * $ready / $total) : 100, 'in_progress' => $making > 0];
    }
}
