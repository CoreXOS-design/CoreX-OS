<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\TrainingVideos\MakeTourAudioJob;
use App\Models\Agency;
use App\Services\TrainingVideos\Auto\NarratedTourGate;
use App\Services\TrainingVideos\Auto\TourAudio;
use App\Services\TrainingVideos\Auto\TrainingScript;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * AT-526 (spec .ai/specs/training-videos.md 9.15 / 9.23) - the narrated guided tour's data: GET /api/v1/training-tours/{screen} returns the screen's steps with the
 * done-condition, the target and the voice clip of each; GET /api/v1/training-tours/{screen}/audio/{n} streams one clip (private disk, range supported).
 * Session-authenticated; a 404 unless NarratedTourGate says this person may take this screen's tour. A clip not made yet comes back with audio_url null - the
 * player shows the words and never waits for sound.
 */
class TrainingToursController extends Controller
{
    public function __construct(
        private readonly NarratedTourGate $gate,
        private readonly TourAudio $audio,
        private readonly TrainingVoices $voices,
    ) {
    }

    public function show(Request $request, string $screen): JsonResponse
    {
        $script = $this->gate->script($request->user(), $screen);
        abort_if($script === null, 404);

        $agency = Agency::withoutGlobalScopes()->find($request->user()->agency_id);
        $voice = $this->voices->keyFor($agency);
        $servedVoice = $this->servedVoice($script, $voice);
        if (! $this->audio->ready($script, $voice)) {
            MakeTourAudioJob::ensure($script->key(), $voice); // once, in the background; the player carries on with text meanwhile
        }

        $url = fn (int $n): ?string => $this->audio->pathFor($script, $n, $servedVoice)
            ? route('api.v1.training-tours.audio', ['screen' => $script->key(), 'n' => $n, 'voice' => $servedVoice])
            : null;
        $lines = $this->audio->lines($script);
        $last = count($script->steps()) + 1;

        $steps = array_map(fn (array $s) => $s + ['audio_url' => $url($s['n'])], $script->tourSteps());

        return response()->json([
            'key' => $script->key(),
            'title' => $script->title(),
            'route' => $script->route(),
            'url' => $script->url(),
            'voice' => $servedVoice,
            'audio_ready' => $this->audio->ready($script, $servedVoice),
            'intro' => ['narration' => $script->intro(), 'audio_url' => isset($lines[0]) ? $url(0) : null],
            'outro' => ['narration' => $script->outro(), 'audio_url' => isset($lines[$last]) ? $url($last) : null],
            'steps' => array_values($steps),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function audio(Request $request, string $screen, int $n): BinaryFileResponse
    {
        $script = $this->gate->script($request->user(), $screen);
        abort_if($script === null, 404);

        $voice = (string) $request->query('voice', '');
        abort_unless(preg_match('/^[a-z0-9_]+$/', $voice) === 1 && $this->voices->find($voice) !== null, 404);
        $path = $this->audio->pathFor($script, $n, $voice);
        abort_if($path === null, 404);

        return response()->file($this->audio->absolute($path), ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** The agency's own voice when its clips are ready, else the platform default's - never silence just because a voice is new. */
    private function servedVoice(TrainingScript $script, string $voice): string
    {
        if ($this->audio->ready($script, $voice)) {
            return $voice;
        }
        $default = $this->voices->defaultKey();

        return $default !== $voice && $this->audio->ready($script, $default) ? $default : $voice;
    }
}
