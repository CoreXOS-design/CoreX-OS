<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Jobs\TrainingVideos\GenerateTrainingVideoJob;
use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Auto\SourceHasher;
use App\Services\TrainingVideos\Auto\TrainingVideoResolver;
use App\Services\TrainingVideos\Auto\TrainingScript;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use App\Services\TrainingVideos\Tts\VoiceSamples;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9) - the Training library: every auto-generated how-to video a signed-in user may watch, a player page, the
 * authenticated file routes (the files are on the private disk) and the Generate / Regenerate button for people who hold training_videos.generate.
 */
class TrainingLibraryController extends Controller
{
    public function __construct(private readonly TrainingScriptRepository $scripts, private readonly SourceHasher $hasher, private readonly TrainingVoices $voices, private readonly VoiceSamples $samples, private readonly TrainingVideoResolver $resolver)
    {
    }

    private function agencyOf($user): ?\App\Models\Agency
    {
        return \App\Models\Agency::withoutGlobalScopes()->find($user->agency_id);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('training_videos.view'), 403);
        $canGenerate = $user->hasPermission('training_videos.generate');
        $currentVoice = $this->voices->keyFor($this->agencyOf($user));
        $videos = $this->resolver->allFor($currentVoice);   // one video per screen, in this agency's voice when it exists, else the platform default's (spec 9.20)

        $rows = [];
        foreach ($this->scripts->all() as $s) {
            if (! $this->mayWatch($s, $user)) {
                continue;
            }
            $v = $videos->get($s->key());
            if (! $v && ! ($canGenerate && $s->approved())) {
                continue;   // a viewer only sees videos that exist; a generator also sees approved scripts waiting for their first video
            }
            $rows[] = ['script' => $s, 'video' => $v, 'outOfDate' => $v ? $this->hasher->isOutOfDate($v, $s) : false];
        }

        $voiceRows = [];
        $defaultVoice = $this->voices->defaultKey();
        if ($canGenerate) {
            foreach ($this->voices->listed() as $key => $v) {
                $has = $v['installed'] && $this->samples->exists($key);
                if ($v['installed'] && ! $has && $v['provider'] === 'chatterbox' && \Illuminate\Support\Facades\Cache::add('tv-sample-queued:' . $key, 1, 3600)) {
                    \App\Jobs\TrainingVideos\MakeVoiceSampleJob::dispatch($key);   // a CoreX voice's sample takes a minute or two: made in the background, once
                }
                $voiceRows[] = $v + ['current' => $key === $currentVoice, 'sample' => $has, 'platform_default' => $key === $defaultVoice];
            }
        }
        $inMyVoice = collect($this->scripts->all())->filter(fn ($sc) => $sc->approved() && $this->resolver->exists($sc->key(), $currentVoice))->count();

        return view('corex.training-library.index', ['rows' => $rows, 'canGenerate' => $canGenerate, 'voiceRows' => $voiceRows, 'currentVoice' => $currentVoice, 'defaultVoice' => $defaultVoice, 'inMyVoice' => $inMyVoice, 'isSystemOwner' => $user->isOwnerRole(), 'voices' => $this->voices,
            'sampleText' => (string) config('training-videos.auto.sample_text'), 'approvedCount' => collect($this->scripts->all())->filter(fn ($s) => $s->approved())->count()]);
    }

    public function show(Request $request, string $screenKey)
    {
        [$script, $video] = $this->resolve($request, $screenKey);
        $related = collect($script->related())->map(fn ($k) => [$this->scripts->find($k), $this->resolver->forScreen($k, $this->voices->keyFor($this->agencyOf($request->user())))])
            ->filter(fn ($r) => $r[0] && $r[1] && $this->mayWatch($r[0], $request->user()))->values();

        return view('corex.training-library.show', [
            'script' => $script, 'video' => $video, 'related' => $related, 'outOfDate' => $this->hasher->isOutOfDate($video, $script),
            'canGenerate' => $request->user()->hasPermission('training_videos.generate'),
        ]);
    }

    /** mp4 / poster / captions: authenticated, range requests supported (BinaryFileResponse). */
    public function file(Request $request, string $screenKey, string $kind)
    {
        [, $video] = $this->resolve($request, $screenKey);
        abort_unless(in_array($kind, ['mp4', 'jpg', 'vtt'], true), 404);
        $path = (string) config('training-videos.auto.folder') . '/' . $video->file_name . '.' . $kind;
        $disk = Storage::disk((string) config('training-videos.auto.disk', 'local'));
        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), ['Content-Type' => ['mp4' => 'video/mp4', 'jpg' => 'image/jpeg', 'vtt' => 'text/vtt'][$kind], 'Cache-Control' => 'private, max-age=300']);
    }

    public function generate(Request $request, string $screenKey)
    {
        abort_unless($request->user()->hasPermission('training_videos.generate'), 403);
        $script = $this->scripts->find($screenKey);
        abort_unless($script, 404);
        if (! $script->approved()) {
            return back()->withErrors(['training' => 'This script has not been approved yet.']);
        }
        GenerateTrainingVideoJob::dispatch($script->key(), $request->user()->id, $this->voices->keyFor($this->agencyOf($request->user())));

        return back()->with('success', 'The video is being made - it takes a few minutes and appears here when it is ready. The old video keeps playing meanwhile.');
    }

    /** One voice's sample (the same two sentences for every voice), made on first ask if it is not there yet. Only people who can choose the voice may hear the picker. */
    public function voiceSample(Request $request, string $key)
    {
        abort_unless($request->user()->hasPermission('training_videos.generate'), 403);
        abort_unless($this->voices->find($key), 404);
        // a CoreX voice's sample takes a minute or two: never made inside a web request (the picker queues it and enables Play when it exists)
        abort_unless($this->samples->exists($key) || $this->voices->find($key)['provider'] !== 'chatterbox', 404);
        $path = $this->samples->ensure($key);
        abort_unless($path, 404);

        return response()->file(Storage::disk((string) config('training-videos.auto.disk', 'local'))->path($path), ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /** "Use this voice": saved as the agency's training-video voice (agencies.training_video_voice). */
    public function chooseVoice(Request $request)
    {
        abort_unless($request->user()->hasPermission('training_videos.generate'), 403);
        $data = $request->validate(['voice' => ['required', 'string', 'max:40']]);
        $agency = $this->agencyOf($request->user());
        abort_unless($agency && $this->voices->find($data['voice']), 404);
        $this->voices->choose($agency, $data['voice']);

        // Audio is made ONCE per voice and shared: this voice's set is queued only for the screens that do not have a video in it yet (another agency may already have made it).
        $queued = 0;
        foreach ($this->scripts->all() as $script) {
            if ($script->approved() && ! $this->resolver->exists($script->key(), $data['voice'])) {
                GenerateTrainingVideoJob::dispatch($script->key(), $request->user()->id, $data['voice']);
                $queued++;
            }
        }

        return back()->with('success', 'Voice saved: ' . $this->voices->find($data['voice'])['label'] . '. ' . ($queued > 0
            ? $queued . ' video' . ($queued === 1 ? ' is' : 's are') . ' being made in this voice (a few minutes each). Until they are ready you keep watching the current ones.'
            : 'Every video already exists in this voice, so there is nothing to wait for.'));
    }

    /** Queue every approved script again, in the agency's chosen voice. */
    public function regenerateAll(Request $request)
    {
        abort_unless($request->user()->hasPermission('training_videos.generate'), 403);
        $key = $this->voices->keyFor($this->agencyOf($request->user()));
        $n = 0;
        foreach ($this->scripts->all() as $script) {
            if ($script->approved()) {
                GenerateTrainingVideoJob::dispatch($script->key(), $request->user()->id, $key);
                $n++;
            }
        }

        return back()->with('success', $n . ' video' . ($n === 1 ? ' is' : 's are') . ' being made again in the ' . $this->voices->find($key)['label'] . ' voice. Each takes a few minutes and appears here when it is ready; the old ones keep playing meanwhile.');
    }

    /** @return array{0: TrainingScript, 1: TrainingVideo} */
    private function resolve(Request $request, string $screenKey): array
    {
        abort_unless($request->user()->hasPermission('training_videos.view'), 403);
        $script = $this->scripts->find($screenKey);
        abort_unless($script && $this->mayWatch($script, $request->user()), 404);
        $video = $this->resolver->forScreen($screenKey, $this->voices->keyFor($this->agencyOf($request->user())));
        abort_unless($video && $video->file_name, 404);

        return [$script, $video];
    }

    private function mayWatch(TrainingScript $s, $user): bool
    {
        return $s->audience() === [] || in_array((string) $user->role, $s->audience(), true) || $user->isOwnerRole();
    }
}
