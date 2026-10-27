<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\TrainingVideos\CompressTrainingVideoJob;
use App\Models\TrainingVideo;
use App\Models\TrainingVideoGroup;
use App\Services\TrainingVideos\ChunkedUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Training Videos — the owner's admin surface for the public /training-videos page.
 *
 * Spec: .ai/specs/training-videos.md §4.1
 *
 * ══ OWNER-ONLY. NO PERMISSION KEY. ══
 *
 * CoreX's own public content. A permission key is grantable, so an agency admin could
 * be handed the power to publish on CoreX's public page. `owner_only` has no
 * delegation path. Enforced at three layers: route middleware, assertOwner() in every
 * action, and the sidebar's owner-gated block. Same reasoning as WebinarController.
 */
class TrainingVideoController extends Controller
{
    private const SORTS = [
        'title'   => 'training_videos.title',
        'group'   => 'group_name',
        'created' => 'training_videos.created_at',
        'updated' => 'training_videos.updated_at',
    ];

    public function __construct(private readonly ChunkedUploadService $uploads)
    {
    }

    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    /** GET /corex/admin/integrations/training-videos */
    public function index(Request $request)
    {
        $this->assertOwner();

        $status = in_array($request->query('status'), ['active', 'archived', 'all'], true) ? $request->query('status') : 'active';
        $sort   = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'created';
        $dir    = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $q      = trim((string) $request->query('q', ''));
        $group  = (string) $request->query('group', '');
        $source = in_array($request->query('source'), [TrainingVideo::SOURCE_YOUTUBE, TrainingVideo::SOURCE_UPLOAD], true) ? $request->query('source') : '';

        $query = TrainingVideo::query()
            ->with('group')
            ->leftJoin('training_video_groups', 'training_video_groups.id', '=', 'training_videos.training_video_group_id')
            ->select('training_videos.*', 'training_video_groups.name as group_name');

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('training_videos.title', 'like', $like)
                  ->orWhere('training_videos.description', 'like', $like)
                  ->orWhere('training_video_groups.name', 'like', $like);
            });
        }

        if ($source !== '') {
            $query->where('training_videos.source', $source);
        }

        if ($group === 'none') {
            $query->whereNull('training_videos.training_video_group_id');
        } elseif (ctype_digit($group)) {
            $query->where('training_videos.training_video_group_id', (int) $group);
        }

        if ($from = $this->date($request->query('from'))) {
            $query->whereDate('training_videos.created_at', '>=', $from);
        }
        if ($to = $this->date($request->query('to'))) {
            $query->whereDate('training_videos.created_at', '<=', $to);
        }

        $videos = $query
            ->orderBy(self::SORTS[$sort], $dir)
            ->orderByDesc('training_videos.id')
            ->paginate(25)
            ->withQueryString();

        $groups   = TrainingVideoGroup::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']);
        $filtered = $q !== '' || $status !== 'active' || $group !== '' || $source !== '' || $request->filled('from') || $request->filled('to');
        $hasAny   = TrainingVideo::withTrashed()->exists();

        return view('admin.training-videos.index', compact('videos', 'groups', 'status', 'sort', 'dir', 'q', 'group', 'source', 'filtered', 'hasAny'));
    }

    /** GET /corex/admin/integrations/training-videos/create */
    public function create()
    {
        $this->assertOwner();

        return view('admin.training-videos.create', ['groups' => $this->selectableGroups(null)]);
    }

    /** POST /corex/admin/integrations/training-videos */
    public function store(Request $request)
    {
        $this->assertOwner();

        $data = $this->validated($request, null);

        if ($data['source'] === TrainingVideo::SOURCE_UPLOAD) {
            // The file is already on the server (the pieces were stitched and checked); this save
            // creates the record and hands it to the video worker. It stays off the public page
            // until the worker marks it Ready (§8.4).
            $uploadId = $data['upload_id'];
            $meta     = $this->uploads->completedUpload($request->user(), $uploadId);
            unset($data['upload_id']);

            $video = TrainingVideo::create($data + [
                'status'                => TrainingVideo::STATUS_PROCESSING,
                'pending_upload_id'     => $uploadId,
                'original_size_bytes'   => $meta['size'],
                'processing_started_at' => now(),
                'created_by'            => Auth::id(),
                'updated_by'            => Auth::id(),
            ]);
            CompressTrainingVideoJob::dispatch($video->id);

            return redirect()
                ->route('admin.training-videos.index')
                ->with('status', 'Upload received. CoreX is shrinking the video now — it appears on the public page when it shows Ready.');
        }

        TrainingVideo::create($data + [
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return redirect()
            ->route('admin.training-videos.index')
            ->with('status', 'Video added. It is now on the public Training Videos page.');
    }

    /** GET /corex/admin/integrations/training-videos/{video}/edit */
    public function edit(TrainingVideo $video)
    {
        $this->assertOwner();

        return view('admin.training-videos.edit', [
            'video'  => $video,
            'groups' => $this->selectableGroups($video),
        ]);
    }

    /** PUT /corex/admin/integrations/training-videos/{video} */
    public function update(Request $request, TrainingVideo $video)
    {
        $this->assertOwner();

        $data     = $this->validated($request, $video);
        $uploadId = $data['upload_id'] ?? null;
        unset($data['upload_id']);

        if ($uploadId !== null) {
            // Replace file (U9): the old copy keeps playing until the new one is Ready. Only a
            // video that never produced a file (still processing / failed) goes back to Processing.
            $this->uploads->completedUpload($request->user(), $uploadId);
            $data += [
                'pending_upload_id'     => $uploadId,
                'failure_reason'        => null,
                'processing_started_at' => now(),
            ];
            if (! $video->file_name) {
                $data['status'] = TrainingVideo::STATUS_PROCESSING;
            }
        }

        $video->update($data + ['updated_by' => Auth::id()]);

        if ($uploadId !== null) {
            CompressTrainingVideoJob::dispatch($video->id);

            return redirect()
                ->route('admin.training-videos.index')
                ->with('status', 'Upload received. The current video keeps playing until the new one has been shrunk and shows Ready.');
        }

        return redirect()
            ->route('admin.training-videos.index')
            ->with('status', 'Video updated.');
    }

    /** POST /corex/admin/integrations/training-videos/{video}/retry — re-queue a failed shrink (U6). */
    public function retry(TrainingVideo $video)
    {
        $this->assertOwner();

        if (! $video->canRetry()) {
            return back()->withErrors(['retry' => 'Nothing to retry for this video.']);
        }
        if (! $this->uploads->exists($video->pending_upload_id)) {
            return back()->withErrors(['retry' => 'The uploaded file is no longer on the server. Open the video and upload it again.']);
        }

        $video->forceFill([
            'status'                => $video->file_name ? TrainingVideo::STATUS_READY : TrainingVideo::STATUS_PROCESSING,
            'failure_reason'        => null,
            'processing_started_at' => now(),
            'updated_by'            => Auth::id(),
        ])->save();
        CompressTrainingVideoJob::dispatch($video->id);

        return back()->with('status', 'Trying again. The video is being shrunk.');
    }

    /** DELETE /corex/admin/integrations/training-videos/{video} — ARCHIVES (non-negotiable #1). */
    public function destroy(TrainingVideo $video)
    {
        $this->assertOwner();

        $video->delete();

        return back()->with('status', 'Video archived. It is no longer on the public page — restore it any time from Archived.');
    }

    /** POST /corex/admin/integrations/training-videos/{video}/restore */
    public function restore(TrainingVideo $video)
    {
        $this->assertOwner();

        $video->restore();

        return back()->with('status', 'Video restored.');
    }

    /**
     * Active groups, plus the video's own group if that one is archived — so editing
     * a video never silently moves it out of its group (spec D10).
     */
    private function selectableGroups(?TrainingVideo $video)
    {
        return TrainingVideoGroup::withTrashed()
            ->where(function ($q) use ($video) {
                $q->whereNull('deleted_at');
                if ($video?->training_video_group_id) {
                    $q->orWhere('id', $video->training_video_group_id);
                }
            })
            ->orderBy('name')
            ->get();
    }

    private function validated(Request $request, ?TrainingVideo $video): array
    {
        $allowedGroupIds = $this->selectableGroups($video)->pluck('id')->all();

        // An existing video keeps its kind; a new one is whichever the owner chose.
        $source = $video?->source
            ?? ($request->input('source') === TrainingVideo::SOURCE_UPLOAD ? TrainingVideo::SOURCE_UPLOAD : TrainingVideo::SOURCE_YOUTUBE);

        $rules = [
            'title'                   => ['required', 'string', 'max:200'],
            'training_video_group_id' => ['nullable', 'integer', Rule::in($allowedGroupIds)],
            'description'             => ['nullable', 'string', 'max:5000'],
        ];

        if ($source === TrainingVideo::SOURCE_UPLOAD) {
            // Required when adding; optional when editing (= keep the current file, or replace it).
            $rules['upload_id'] = [$video ? 'nullable' : 'required', 'string', function ($attr, $value, $fail) {
                if (! $this->uploads->isValidId($value)) {
                    $fail('The video has not finished uploading.');
                }
            }];
        } else {
            $rules['youtube_url'] = ['required', 'string', 'max:500', function ($attr, $value, $fail) {
                if (TrainingVideo::extractYoutubeId((string) $value) === null) {
                    $fail("That doesn't look like a YouTube link. Paste the link from the Share button on YouTube.");
                }
            }];
        }

        $data = $request->validate($rules, [
            'title.required'             => 'Give the video a title.',
            'youtube_url.required'       => 'Paste the YouTube link for this video.',
            'upload_id.required'         => 'Choose a video file and wait for the upload to reach 100% before saving.',
            'training_video_group_id.in' => 'That group is not available. Pick another group or "No group".',
        ]);

        // This screen posts the whole form, so an absent/empty field means "cleared"
        // (No group / no description) — never "leave the old value".
        $data['training_video_group_id'] = $data['training_video_group_id'] ?? null;
        $data['description']             = $data['description'] ?? null;
        $data['source']                  = $source;

        if ($source === TrainingVideo::SOURCE_YOUTUBE) {
            $data['youtube_url']      = trim($data['youtube_url']);
            $data['youtube_video_id'] = TrainingVideo::extractYoutubeId($data['youtube_url']);
        }

        return $data;
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
