<?php

namespace App\Services\TrainingVideos\Auto;

use App\Models\Agency;
use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Tts\TrainingVoices;

/**
 * AT-525 (spec section 9.20) - which generated video an agency watches for a screen. Audio is made ONCE per (screen, voice) and shared by every agency on that voice:
 * the agency's own voice first (agencies.training_video_voice), else the platform default's, else an older video made before voices were per video, else any ready one.
 * So an agency that has just switched voice keeps watching the default's video until its own is ready.
 */
class TrainingVideoResolver
{
    public function __construct(private readonly TrainingVoices $voices)
    {
    }

    public function voiceKeyFor(?Agency $agency): string
    {
        return $this->voices->keyFor($agency);
    }

    /** The ready video to play, or null. */
    public function forScreen(string $screenKey, ?string $voiceKey): ?TrainingVideo
    {
        $ready = TrainingVideo::where('source', TrainingVideo::SOURCE_GENERATED)->where('screen_key', $screenKey)->where('status', TrainingVideo::STATUS_READY)->whereNotNull('file_name')->get();
        foreach ([$voiceKey, $this->voices->defaultKey()] as $k) {
            if ($k && ($v = $ready->firstWhere('voice_key', $k))) {
                return $v;
            }
        }

        return $ready->firstWhere('voice_key', null) ?? $ready->first();
    }

    /** Every ready generated video, one per screen, as that agency would be shown them. @return \Illuminate\Support\Collection<string,TrainingVideo> keyed by screen_key */
    public function allFor(?string $voiceKey)
    {
        $by = TrainingVideo::where('source', TrainingVideo::SOURCE_GENERATED)->where('status', TrainingVideo::STATUS_READY)->whereNotNull('file_name')->get()->groupBy('screen_key');

        return $by->map(function ($group) use ($voiceKey) {
            foreach ([$voiceKey, $this->voices->defaultKey()] as $k) {
                if ($k && ($v = $group->firstWhere('voice_key', $k))) {
                    return $v;
                }
            }

            return $group->firstWhere('voice_key', null) ?? $group->first();
        });
    }

    /** Is a video for this exact (screen, voice) already made or being made? (Then nothing is queued again - audio is made once per voice.) */
    public function exists(string $screenKey, string $voiceKey): bool
    {
        return TrainingVideo::where('source', TrainingVideo::SOURCE_GENERATED)->where('screen_key', $screenKey)->where('voice_key', $voiceKey)
            ->whereIn('status', [TrainingVideo::STATUS_READY, TrainingVideo::STATUS_PROCESSING])->exists();
    }
}
