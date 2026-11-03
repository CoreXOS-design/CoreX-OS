<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VideoAd;
use App\Models\VideoAdPost;
use App\Services\AdStudio\VideoAdPostService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AT-527 (spec .ai/specs/training-videos.md 9.22) - "Post now" and "Schedule" for a video ad, and the CoreX Scheduled posts list (Marketing -> Scheduled posts).
 * System Owner only. Facebook only. Dry run whenever external publishing is off (QA1); the first real post is Johan's own click on a server where it is on.
 */
class VideoAdPostController extends Controller
{
    private const SORTS = ['when' => 'scheduled_for', 'status' => 'status', 'created' => 'created_at'];
    private const TZ = 'Africa/Johannesburg';

    public function __construct(private readonly VideoAdPostService $posts)
    {
    }

    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    public function index(Request $request)
    {
        $this->assertOwner();
        $status = in_array($request->query('status'), ['all', 'scheduled', 'published', 'failed', 'cancelled'], true) ? $request->query('status') : 'all';
        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? $request->query('sort') : 'when';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $q = VideoAdPost::query()->with('ad');
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($from = $request->query('from')) {
            $q->whereDate('scheduled_for', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('scheduled_for', '<=', $to);
        }
        if ($term = trim((string) $request->query('q'))) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $q->where(fn ($w) => $w->where('message', 'like', $like)->orWhereHas('ad', fn ($a) => $a->withTrashed()->where('name', 'like', $like)));
        }
        $q->orderBy(self::SORTS[$sort], $dir)->orderByDesc('id');

        return view('admin.video-ads.posts', [
            'page' => $q->paginate(25)->withQueryString(), 'filters' => ['q' => $request->query('q'), 'status' => $status, 'from' => $request->query('from'), 'to' => $request->query('to')],
            'sort' => $sort, 'dir' => $dir, 'dryRun' => $this->posts->isDryRun(), 'account' => $this->posts->account(), 'tz' => self::TZ,
        ]);
    }

    public function postNow(Request $request, VideoAd $ad)
    {
        $this->assertOwner();
        $format = (string) ($request->input('format') ?: $this->posts->defaultFormat($ad));
        try {
            $post = $this->posts->postNow($ad, $format, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        }

        return back()->with('status', $this->posts->isDryRun()
            ? $ad->name . ' (' . $format . '): dry run - nothing was sent to Facebook. See Scheduled posts for what would have been posted.'
            : $ad->name . ' (' . $format . ') is being posted to the CoreX Facebook page.');
    }

    public function schedule(Request $request, VideoAd $ad)
    {
        $this->assertOwner();
        $data = $request->validate(['format' => ['required', 'string', 'max:8'], 'scheduled_at' => ['required', 'date']]);
        try {
            $when = Carbon::parse($data['scheduled_at'], self::TZ)->utc();
            $this->posts->schedule($ad, $data['format'], $when, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['post' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.video-ads.posts')->with('status', $ad->name . ' is scheduled for ' . $when->copy()->setTimezone(self::TZ)->format('j M Y H:i') . '.' . ($this->posts->isDryRun() ? ' (Dry run: nothing will really be posted on this server.)' : ''));
    }

    public function cancel(VideoAdPost $post)
    {
        $this->assertOwner();

        return back()->with('status', $this->posts->cancel($post) ? 'The scheduled post was cancelled.' : 'That post is no longer waiting.');
    }

    public function reschedule(Request $request, VideoAdPost $post)
    {
        $this->assertOwner();
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        try {
            $this->posts->reschedule($post, Carbon::parse($data['scheduled_at'], self::TZ)->utc());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['post' => $e->getMessage()]);
        }

        return back()->with('status', 'The post was moved.');
    }
}
