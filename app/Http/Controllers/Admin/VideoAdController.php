<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoAd;
use App\Services\AdStudio\AdBrand;
use App\Services\AdStudio\AdScriptRepository;
use App\Services\AdStudio\AdScriptValidator;
use App\Services\AdStudio\AdSnapshotBuilder;
use App\Services\AdStudio\VideoAdService;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * AT-527 Ad Studio - CoreX owners menu -> Marketing -> Video ads (spec .ai/specs/training-videos.md 9.21). System Owner only: owner_only middleware on the routes
 * AND assertOwner() in every action AND the sidebar's owner block (same three layers as the Training Videos admin: no permission key by design, a key could be
 * granted to an agency admin, who must not make CoreX's own marketing).
 */
class VideoAdController extends Controller
{
    private const SORTS = ['created' => 'created_at', 'name' => 'ad_number', 'length' => 'length_seconds', 'status' => 'status'];
    private const PER_PAGE = 25;

    public function __construct(
        private readonly VideoAdService $service,
        private readonly AdScriptRepository $ads,
        private readonly TrainingScriptRepository $training,
        private readonly AdSnapshotBuilder $snapshots,
        private readonly AdScriptValidator $validator,
        private readonly TrainingVoices $voices,
    ) {
    }

    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    public function index(Request $request)
    {
        $this->assertOwner();
        $status = in_array($request->query('status'), ['active', 'archived', 'all', 'ready', 'failed'], true) ? $request->query('status') : 'active';
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? $request->query('sort') : 'created';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $q = VideoAd::query()->with('generator:id,name');
        if ($status === 'archived') {
            $q->onlyTrashed();
        } elseif ($status === 'all') {
            $q->withTrashed();
        } elseif (in_array($status, ['ready', 'failed'], true)) {
            $q->where('status', $status);
        }
        if ($feature = $request->query('feature')) {
            $q->where('feature_key', $feature);
        }
        if ($format = $request->query('format')) {
            $q->whereJsonContains('formats', $format);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('created_at', '<=', $to);
        }
        $q->search($request->query('q'));
        $q->orderBy(self::SORTS[$sort], $dir)->orderByDesc('id');

        $page = $q->paginate(self::PER_PAGE)->withQueryString();
        foreach ($page->items() as $ad) {
            $this->service->sync($ad);
        }

        return view('admin.video-ads.index', [
            'page' => $page,
            'byFeature' => collect($page->items())->groupBy('feature_key'),
            'features' => $this->ads->all(),
            'filters' => ['q' => $request->query('q'), 'feature' => $request->query('feature'), 'status' => $status, 'format' => $request->query('format'),
                'from' => $request->query('from'), 'to' => $request->query('to')],
            'sort' => $sort, 'dir' => $dir,
        ]);
    }

    public function create(Request $request, string $feature)
    {
        $this->assertOwner();
        $script = $this->ads->find($feature);
        abort_unless($script, 404);
        $variant = array_key_exists((string) $request->query('variant'), $script->questions()) ? $request->query('variant') : array_key_first($script->questions());

        return $this->editor($script, $this->service->starterDraft($script, (string) $variant), 'New ad');
    }

    public function duplicate(VideoAd $ad)
    {
        $this->assertOwner();
        $script = $this->ads->find($ad->feature_key);
        abort_unless($script, 404);

        return $this->editor($script, $this->snapshots->toDraft((array) $ad->snapshot), 'New ad (copy of ' . $ad->name . ')');
    }

    private function editor($script, array $draft, string $heading)
    {
        $training = $this->training->find($script->screen());
        $steps = [];
        foreach ($training ? $training->steps() : [] as $i => $s) {
            $steps[] = ['index' => $i, 'label' => 'Step ' . ($i + 1) . ': ' . mb_substr((string) ($s['narration'] ?? ''), 0, 70), 'selector' => (string) ($s['selector'] ?? '')];
        }
        $old = old('draft');
        $draft = is_string($old) && ($j = json_decode($old, true)) ? $j : $draft;
        $voiceOptions = [];
        foreach ($this->voices->listed() as $key => $v) {
            $voiceOptions[] = ['key' => $key, 'label' => ($v['label'] ?? $key) . (isset($v['about']) ? ' - ' . $v['about'] : '')];
        }
        $brand = AdBrand::current();

        return view('admin.video-ads.editor', [
            'script' => $script, 'draft' => $draft, 'steps' => $steps, 'heading' => $heading, 'brand' => $brand, 'voiceOptions' => $voiceOptions,
            'questions' => $script->questions(), 'formats' => (array) config('ad-studio.formats'), 'lengths' => (array) config('ad-studio.lengths'),
            'limits' => (array) config('ad-studio.limits'), 'check' => $this->validator->check($draft),
            'nextName' => 'Ad ' . VideoAd::nextNumber($script->key()),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertOwner();
        $raw = json_decode((string) $request->input('draft'), true);
        abort_unless(is_array($raw), 422, 'The ad could not be read.');
        $draft = $this->service->normalise($raw);

        try {
            $ad = $this->service->generate($draft, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withInput(['draft' => json_encode($draft)])->withErrors($e->errors());
        }

        return redirect()->route('admin.video-ads.index', ['feature' => $ad->feature_key])
            ->with('status', $ad->name . ' is being made. You can leave this page - it shows here when it is ready.');
    }

    public function show(VideoAd $ad)
    {
        $this->assertOwner();
        $this->service->sync($ad);

        return view('admin.video-ads.show', ['ad' => $ad, 'formats' => (array) config('ad-studio.formats')]);
    }

    public function file(VideoAd $ad, string $format, string $kind)
    {
        $this->assertOwner();

        return $this->stream($ad, $format, $kind, false);
    }

    public function download(VideoAd $ad, string $format)
    {
        $this->assertOwner();

        return $this->stream($ad, $format, 'mp4', true);
    }

    private function stream(VideoAd $ad, string $format, string $kind, bool $attachment): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['mp4', 'jpg'], true), 404);
        $fmt = str_replace('x', ':', $format);
        $entry = $ad->readyFormats()[$fmt] ?? null;
        abort_unless($ad->isReady() && $entry, 404);
        $path = (string) ($entry[$kind === 'mp4' ? 'mp4' : 'poster'] ?? '');
        $real = $path !== '' ? realpath($path) : false;
        $root = realpath(storage_path('app'));
        // Only files the renderer wrote under the app's storage (private disk, data volume) are ever served.
        abort_unless($real && $root && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real), 404);

        if ($attachment) {
            $name = 'corex-' . $ad->feature_key . '-' . strtolower(str_replace(' ', '-', $ad->name)) . '-' . $format . '.mp4';

            return response()->download($real, $name, ['Content-Type' => 'video/mp4']);
        }

        return response()->file($real, ['Content-Type' => $kind === 'mp4' ? 'video/mp4' : 'image/jpeg']);
    }

    public function retry(VideoAd $ad)
    {
        $this->assertOwner();
        abort_unless($ad->status === VideoAd::STATUS_FAILED, 409, 'Only a failed ad can be tried again.');
        $this->service->retry($ad);

        return back()->with('status', $ad->name . ' is being made again.');
    }

    public function destroy(VideoAd $ad)
    {
        $this->assertOwner();
        $ad->delete();   // archive - the row and its files stay (non-negotiable #1)

        return back()->with('status', $ad->name . ' archived.');
    }

    public function restore(VideoAd $ad)
    {
        $this->assertOwner();
        $ad->restore();

        return back()->with('status', $ad->name . ' restored.');
    }
}
