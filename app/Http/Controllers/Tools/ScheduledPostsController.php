<?php

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Models\PropertyMarketingPost;
use App\Models\User;
use App\Services\Marketing\ScheduleException;
use App\Services\Marketing\ScheduledPostImages;
use App\Services\Marketing\ScheduledPostService;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Scheduled Posts (Tools → Ad Manager → Scheduled Posts, AT-456 Part D, spec §D.4).
 *
 * Posts are made from the Ad Manager's generated ads ("Schedule" on the results step); this screen lists
 * them and lets the agent edit the text or time while a post is still waiting, cancel it, or put a
 * cancelled one back on the schedule. Nothing is ever hard-deleted — cancelling keeps the row.
 *
 * Gate (routes): access_ad_manager + ad_manager.schedule_posts + feature:ad-manager + agency.required.
 * Scoping is in the query (PropertyMarketingPost::visibleTo → the user's Properties data scope, on top of
 * AgencyScope): a post outside it — another agency's, another branch's — is a 404 by id, not just unlinked.
 */
class ScheduledPostsController extends Controller
{
    private const PER_PAGE = 15;

    /** `sort` query value => [kind, default direction]. */
    private const SORTS = ['scheduled', 'status', 'agent', 'created'];

    private const STATUSES = ['scheduled', 'publishing', 'published', 'failed', 'cancelled'];

    public function __construct(
        private readonly ScheduledPostService $posts,
        private readonly ScheduledPostImages $images,
    ) {}

    // ── list ────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $user = $request->user();
        $this->authorizeUse($user);

        $q      = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '';
        $platform = in_array($request->query('platform'), array_keys(ScheduledPostService::PLATFORMS), true) ? (string) $request->query('platform') : '';
        $agentId = (int) $request->query('agent', 0);
        $sort    = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'scheduled';
        $dir     = $request->query('dir') === 'desc' ? 'desc' : ($request->query('dir') === 'asc' ? 'asc' : ($sort === 'created' ? 'desc' : 'asc'));
        $from    = $this->validDate($request->query('from'));
        $to      = $this->validDate($request->query('to'));
        $tz      = $this->posts->timezone();

        // Only posts made through the scheduler (they carry a scheduled time).
        $base  = PropertyMarketingPost::query()->visibleTo($user)->whereNotNull('scheduled_for');
        $query = (clone $base)->with(['property', 'user:id,name']);

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('ad_copy', 'like', $like)
                    ->orWhereHas('property', fn ($p) => $p->where(fn ($x) => $x
                        ->where('title', 'like', $like)
                        ->orWhere('address', 'like', $like)
                        ->orWhere('street_name', 'like', $like)
                        ->orWhere('suburb', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('external_id', 'like', $like)
                        ->orWhere('source_reference', 'like', $like)))
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
            });
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($platform !== '') {
            $query->where('platform', $platform);
        }
        if ($agentId > 0) {
            $query->where('user_id', $agentId);
        }
        // Date range is in the agency's local days, converted to UTC for the stored column.
        if ($from) {
            $query->where('scheduled_for', '>=', Carbon::parse($from . ' 00:00:00', $tz)->utc()->format('Y-m-d H:i:s'));
        }
        if ($to) {
            $query->where('scheduled_for', '<=', Carbon::parse($to . ' 23:59:59', $tz)->utc()->format('Y-m-d H:i:s'));
        }

        match ($sort) {
            'status'  => $query->orderBy('status', $dir),
            'agent'   => $query->orderBy(User::query()->withoutGlobalScopes()->select('name')
                ->whereColumn('users.id', 'property_marketing_posts.user_id')->limit(1), $dir),
            'created' => $query->orderBy('property_marketing_posts.created_at', $dir),
            default   => $query->orderBy('scheduled_for', $dir),
        };
        $query->orderBy('property_marketing_posts.id', $dir === 'asc' ? 'asc' : 'desc');

        $rows = $query->paginate(self::PER_PAGE)->withQueryString();

        // Agent filter only where the scope lets the user see more than themselves.
        $scope  = PermissionService::getDataScope($user, 'properties');
        $agents = in_array($scope, ['all', 'branch'], true)
            ? User::query()->whereIn('id', (clone $base)->select('user_id'))->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('tools.ad-manager.scheduled', [
            'rows'        => $rows,
            'noneAtAll'   => ! (clone $base)->exists(),
            'agents'      => $agents,
            'statuses'    => self::STATUSES,
            'platforms'   => ScheduledPostService::PLATFORMS,
            'tz'          => $tz,
            'tzLabel'     => Carbon::now($tz)->format('T') . ' (' . $tz . ')',
            'connections' => $this->posts->connections($user),
            'connectHint' => $this->posts->connectHint(),
            'filters'     => [
                'q' => $q, 'status' => $status, 'platform' => $platform, 'agent' => $agentId,
                'sort' => $sort, 'dir' => $dir, 'from' => $from, 'to' => $to,
            ],
            'minLead'     => ScheduledPostService::MIN_LEAD_MINUTES,
            'maxDays'     => ScheduledPostService::MAX_LEAD_DAYS,
        ]);
    }

    // ── make ────────────────────────────────────────────────────────────────

    /** POST — save one browser-rendered ad picture; the reply carries the path to hand back to store(). */
    public function uploadImage(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeUse($user);

        $data = $request->validate(['image' => 'required|string']);

        try {
            return response()->json(['ok' => true, 'path' => $this->images->storeDataUrl($user, $data['image'])]);
        } catch (ScheduleException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST — schedule the chosen ads on the chosen platforms. All or nothing. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeUse($user);

        $data = $request->validate([
            'items'              => 'required|array|min:1|max:50',
            'items.*.property_id' => 'required|integer',
            'items.*.copy'       => 'required|string|max:5000',
            'items.*.image_path' => 'required|string|max:300',
            'platforms'          => 'required|array|min:1',
            'platforms.*'        => 'in:facebook,instagram',
            'mode'               => 'required|in:at,daily',
            'date'               => 'required|date_format:Y-m-d',
            'time'               => 'required|date_format:H:i',
        ]);

        try {
            $created = $this->posts->schedule($user, $data['items'], $data['platforms'], $data['mode'], $data['date'], $data['time']);
        } catch (ScheduleException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'        => true,
            'scheduled' => $created->count(),
            'url'       => route('tools.ad-manager.scheduled'),
        ]);
    }

    // ── change a waiting / cancelled post ───────────────────────────────────

    public function update(Request $request, int $post): RedirectResponse
    {
        $row  = $this->findVisible($request, $post);
        $data = $request->validate([
            'copy' => 'required|string|max:5000',
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
        ]);

        return $this->attempt(fn () => $this->posts->update($row, $data['copy'], $data['date'], $data['time']), 'Post updated.');
    }

    public function cancel(Request $request, int $post): RedirectResponse
    {
        $row = $this->findVisible($request, $post);

        return $this->attempt(fn () => $this->posts->cancel($row), 'Post cancelled. It will not be published. You can put it back on the schedule from this list.');
    }

    public function reschedule(Request $request, int $post): RedirectResponse
    {
        $row  = $this->findVisible($request, $post);
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
        ]);

        return $this->attempt(fn () => $this->posts->reschedule($row, $data['date'], $data['time']), 'Post is back on the schedule.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** The post, only if it is inside the user's scope. Anything else — another agency's, out of scope, unknown — is a 404. */
    private function findVisible(Request $request, int $id): PropertyMarketingPost
    {
        $user = $request->user();
        $this->authorizeUse($user);

        return PropertyMarketingPost::query()->visibleTo($user)->whereNotNull('scheduled_for')->findOrFail($id);
    }

    private function attempt(\Closure $do, string $success): RedirectResponse
    {
        try {
            $do();
        } catch (ScheduleException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', $success);
    }

    /** Defence in depth behind the route middleware: a user without the key never reaches the data. */
    private function authorizeUse(?User $user): void
    {
        abort_unless($user && $user->hasPermission('access_ad_manager') && $user->hasPermission('ad_manager.schedule_posts'), 403);
    }

    private function validDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }
}
