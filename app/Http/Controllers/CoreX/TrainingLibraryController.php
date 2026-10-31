<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Jobs\TrainingVideos\GenerateTrainingVideoJob;
use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Auto\SourceHasher;
use App\Services\TrainingVideos\Auto\TrainingScript;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9) - the Training library: every auto-generated how-to video a signed-in user may watch, a player page, the
 * authenticated file routes (the files are on the private disk) and the Generate / Regenerate button for people who hold training_videos.generate.
 */
class TrainingLibraryController extends Controller
{
    public function __construct(private readonly TrainingScriptRepository $scripts, private readonly SourceHasher $hasher)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('training_videos.view'), 403);
        $canGenerate = $user->hasPermission('training_videos.generate');
        $videos = TrainingVideo::where('source', TrainingVideo::SOURCE_GENERATED)->where('status', TrainingVideo::STATUS_READY)->get()->keyBy('screen_key');

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

        return view('corex.training-library.index', ['rows' => $rows, 'canGenerate' => $canGenerate]);
    }

    public function show(Request $request, string $screenKey)
    {
        [$script, $video] = $this->resolve($request, $screenKey);
        $related = collect($script->related())->map(fn ($k) => [$this->scripts->find($k), TrainingVideo::where('source', 'generated')->where('screen_key', $k)->where('status', 'ready')->first()])
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
        GenerateTrainingVideoJob::dispatch($script->key(), $request->user()->id);

        return back()->with('success', 'The video is being made - it takes a few minutes and appears here when it is ready. The old video keeps playing meanwhile.');
    }

    /** @return array{0: TrainingScript, 1: TrainingVideo} */
    private function resolve(Request $request, string $screenKey): array
    {
        abort_unless($request->user()->hasPermission('training_videos.view'), 403);
        $script = $this->scripts->find($screenKey);
        abort_unless($script && $this->mayWatch($script, $request->user()), 404);
        $video = TrainingVideo::where('source', TrainingVideo::SOURCE_GENERATED)->where('screen_key', $screenKey)->where('status', TrainingVideo::STATUS_READY)->first();
        abort_unless($video && $video->file_name, 404);

        return [$script, $video];
    }

    private function mayWatch(TrainingScript $s, $user): bool
    {
        return $s->audience() === [] || in_array((string) $user->role, $s->audience(), true) || $user->isOwnerRole();
    }
}
