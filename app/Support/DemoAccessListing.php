<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DemoAccessGrant;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the Demo Access list screen needs, in one place, so the controller
 * stays thin and every rule here is testable without rendering a page.
 *
 * Spec: .ai/specs/demo-access-control.md §9 (list), §9.2
 *
 * Status is DERIVED (DemoAccessGrant::status()) and cannot be filtered in SQL
 * without re-implementing the rules and drifting from them — so the set is loaded
 * (search + issued-date range in SQL), classified in PHP against that one method,
 * then paginated. A prospect list is tens-to-hundreds of rows, not millions.
 *
 * "Whatever filters the list must also filter every count": the view counts in the
 * left rail are computed over the same set the list is cut from (search, date
 * range and the two hide switches applied), so a count always equals what its view
 * shows when clicked.
 *
 * Owner-only — this lists RR Technologies' sales prospects. Not tenant-scoped.
 */
final class DemoAccessListing
{
    public const PER_PAGE = 24;

    /** Activity thresholds. Owner-only sales tooling, not an agency setting. */
    public const HOT_HOURS  = 24;   // "Hot now"        — active and seen within this many hours
    public const SOON_HOURS = 48;   // "Expiring soon"  — usable and ends within this many hours
    public const QUIET_DAYS = 3;    // "Went quiet"     — active but not seen for this many days
    public const WARM_DAYS  = 7;    // card dot: green ≤ HOT_HOURS, amber ≤ WARM_DAYS, grey beyond

    public const SPARK_DAYS = 14;

    public const VIEWS = [
        'all'      => 'All grants',
        'hot'      => 'Hot now',
        'soon'     => 'Expiring soon',
        'quiet'    => 'Went quiet',
        'unused'   => 'Not used yet',
        'ended'    => 'Ended',
        'archived' => 'Archived',
    ];

    public const VIEW_HINTS = [
        'hot'    => 'seen in the last 24 hours',
        'soon'   => 'access ends within 48 hours',
        'quiet'  => 'active, but not seen for 3+ days',
        'unused' => 'invited, never signed in',
        'ended'  => 'expired or revoked',
    ];

    public const SORTS = [
        'recent'  => 'Most recent activity',
        'expiry'  => 'Expiring soonest',
        'pages'   => 'Most pages viewed',
        'issued'  => 'Newest issued',
        'company' => 'Company A–Z',
    ];

    /** A grant's status can be extended unless it was withdrawn or archived. */
    public const EXTENDABLE = [
        DemoAccessGrant::STATUS_PENDING,
        DemoAccessGrant::STATUS_ACTIVE,
        DemoAccessGrant::STATUS_EXPIRED,
    ];

    /**
     * @return array{rows: LengthAwarePaginator, views: array<string,array>, filters: array<string,mixed>, issued: int, hasAny: bool, anyFilter: bool}
     */
    public static function build(Request $request): array
    {
        $search = trim((string) $request->input('q', ''));
        $view   = (string) $request->input('view', $request->boolean('archived') ? 'archived' : 'all');
        $view   = array_key_exists($view, self::VIEWS) ? $view : 'all';
        $sort   = (string) $request->input('sort', 'recent');
        $sort   = array_key_exists($sort, self::SORTS) ? $sort : 'recent';
        $from   = self::date($request->input('issued_from'));
        $to     = self::date($request->input('issued_to'));
        $hideUnused = $request->boolean('hide_unused');
        $hideEnded  = $request->boolean('hide_ended');

        $pages = DB::table('demo_page_views')
            ->join('demo_sessions', 'demo_sessions.id', '=', 'demo_page_views.demo_session_id')
            ->whereColumn('demo_sessions.demo_access_grant_id', 'demo_access_grants.id')
            ->selectRaw('count(*)');

        $query = DemoAccessGrant::query()
            ->select('demo_access_grants.*')
            ->selectSub($pages, 'pages_count')
            ->withCount('sessions')
            ->withMax('sessions as last_seen_max', 'last_seen_at')
            ->withMax('sessions as last_started_max', 'started_at');

        if ($search !== '') {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('company_name', 'like', $like)
                  ->orWhere('contact_email', 'like', $like)
                  ->orWhere('contact_name', 'like', $like);
            });
        }
        if ($from) {
            $query->whereDate('demo_access_grants.created_at', '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate('demo_access_grants.created_at', '<=', $to->toDateString());
        }

        $now = Carbon::now();

        /** @var Collection<int,array> $metas */
        $metas = $query->get()->map(fn (DemoAccessGrant $g) => self::meta($g, $now));

        $hasAny = $metas->isNotEmpty();

        // The hide switches shape the working set, so the rail counts agree with the list.
        $working = $metas->filter(function (array $m) use ($hideUnused, $hideEnded) {
            if ($hideUnused && $m['status'] === DemoAccessGrant::STATUS_PENDING) return false;
            if ($hideEnded && in_array($m['status'], [DemoAccessGrant::STATUS_EXPIRED, DemoAccessGrant::STATUS_REVOKED], true)) return false;
            return true;
        });

        $views = [];
        foreach (self::VIEWS as $key => $label) {
            $views[$key] = [
                'key'    => $key,
                'label'  => $label,
                'hint'   => self::VIEW_HINTS[$key] ?? null,
                'count'  => $working->filter(fn (array $m) => self::inView($m, $key))->count(),
                'active' => $key === $view,
            ];
        }

        $list = $working->filter(fn (array $m) => self::inView($m, $view));
        $list = self::sort($list, $sort)->values();

        $page    = max(1, (int) $request->input('page', 1));
        $total   = $list->count();
        $slice   = $list->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();
        $sparks  = self::sparklines($slice->pluck('id')->all());

        $items = $slice->map(function (array $m) use ($sparks) {
            $m['spark'] = $sparks[$m['id']] ?? array_fill(0, self::SPARK_DAYS, 0);
            return $m;
        });

        $paginator = new LengthAwarePaginator($items, $total, self::PER_PAGE, $page, [
            'path'  => route('admin.demo-access.index'),
            'query' => $request->query(),
        ]);

        $filters = [
            'q' => $search, 'view' => $view, 'sort' => $sort,
            'issued_from' => $from?->toDateString(), 'issued_to' => $to?->toDateString(),
            'hide_unused' => $hideUnused, 'hide_ended' => $hideEnded,
        ];

        return [
            'rows'      => $paginator,
            'views'     => $views,
            'filters'   => $filters,
            'issued'    => $metas->count(),
            'hasAny'    => $hasAny,
            'anyFilter' => $search !== '' || $view !== 'all' || $sort !== 'recent' || $from || $to || $hideUnused || $hideEnded,
        ];
    }

    // ---- Classification ----------------------------------------------------

    /** One grant, reduced to what the list needs. `status` is the single derived truth. */
    public static function meta(DemoAccessGrant $g, Carbon $now): array
    {
        $status   = $g->status();
        $lastRaw  = $g->getAttribute('last_seen_max') ?: $g->getAttribute('last_started_max');
        $last     = $lastRaw ? Carbon::parse($lastRaw) : null;
        $expires  = $g->expires_at;
        $usable   = in_array($status, [DemoAccessGrant::STATUS_PENDING, DemoAccessGrant::STATUS_ACTIVE], true);
        $hoursLeft = ($usable && $expires) ? (int) floor($now->diffInHours($expires, false)) : null;

        $dot = 'cold';
        if ($last && $last->gte($now->copy()->subHours(self::HOT_HOURS))) {
            $dot = 'fresh';
        } elseif ($last && $last->gte($now->copy()->subDays(self::WARM_DAYS))) {
            $dot = 'warm';
        }

        return [
            'id'          => $g->getKey(),
            'grant'       => $g,
            'company'     => $g->company_name,
            'email'       => $g->contact_email,
            'status'      => $status,
            'label'       => $g->statusLabel(),
            'badge'       => match ($status) {
                'active'   => 'ds-badge-success',
                'pending'  => 'ds-badge-orange',
                'expired'  => 'ds-badge-warning',
                'revoked'  => 'ds-badge-danger',
                'archived' => 'ds-badge-muted',
                default    => 'ds-badge-default',
            },
            'last'        => $last,
            'ago'         => $last ? $last->diffForHumans() : 'Never opened',
            'dot'         => $dot,
            'pages'       => (int) $g->getAttribute('pages_count'),
            'sessions'    => (int) $g->sessions_count,
            'expires'     => $expires,
            'hoursLeft'   => $hoursLeft,
            'left'        => self::timeLeft($g, $status, $now, $hoursLeft),
            'urgent'      => $hoursLeft !== null && $hoursLeft <= self::SOON_HOURS,
            'issued'      => $g->created_at,
            'canExtend'   => in_array($status, self::EXTENDABLE, true),
            'quiet'       => $status === DemoAccessGrant::STATUS_ACTIVE
                                && (! $last || $last->lt($now->copy()->subDays(self::QUIET_DAYS))),
            'hot'         => $status === DemoAccessGrant::STATUS_ACTIVE
                                && $last !== null && $last->gte($now->copy()->subHours(self::HOT_HOURS)),
            'extend'      => self::extendPayload($g, $status),
        ];
    }

    public static function inView(array $m, string $view): bool
    {
        $archived = $m['status'] === DemoAccessGrant::STATUS_ARCHIVED;

        return match ($view) {
            'archived' => $archived,
            'hot'      => ! $archived && $m['hot'],
            'soon'     => ! $archived && $m['hoursLeft'] !== null && $m['hoursLeft'] <= self::SOON_HOURS,
            'quiet'    => ! $archived && $m['quiet'],
            'unused'   => $m['status'] === DemoAccessGrant::STATUS_PENDING,
            'ended'    => in_array($m['status'], [DemoAccessGrant::STATUS_EXPIRED, DemoAccessGrant::STATUS_REVOKED], true),
            default    => ! $archived,        // 'all' — archived rows have their own view
        };
    }

    private static function sort(Collection $list, string $sort): Collection
    {
        $never = PHP_INT_MAX;

        return match ($sort) {
            'expiry'  => $list->sortBy(fn (array $m) => [$m['hoursLeft'] === null ? $never : $m['hoursLeft'], $m['id']]),
            'pages'   => $list->sortByDesc(fn (array $m) => [$m['pages'], $m['id']]),
            'issued'  => $list->sortByDesc(fn (array $m) => $m['id']),
            'company' => $list->sortBy(fn (array $m) => mb_strtolower($m['company'])),
            // Default: most recent activity first; never-opened last, newest invite first among them.
            default   => $list->sortBy(fn (array $m) => [$m['last'] ? -$m['last']->getTimestamp() : $never, -$m['id']]),
        };
    }

    // ---- Presentation helpers ---------------------------------------------

    public static function humanHours(int $hours): string
    {
        if ($hours < 24) {
            return $hours . ' h';
        }
        $d = intdiv($hours, 24);
        $h = $hours % 24;
        $out = $d . ' ' . ($d === 1 ? 'day' : 'days');

        return $h > 0 ? $out . ' ' . $h . ' h' : $out;
    }

    private static function timeLeft(DemoAccessGrant $g, string $status, Carbon $now, ?int $hoursLeft): string
    {
        return match (true) {
            $status === DemoAccessGrant::STATUS_REVOKED  => 'Revoked',
            $status === DemoAccessGrant::STATUS_ARCHIVED => 'Archived',
            $status === DemoAccessGrant::STATUS_EXPIRED  => 'Ended ' . ($g->expires_at?->diffForHumans() ?? ''),
            $g->expires_at === null                      => 'Starts at sign-in · ' . self::humanHours((int) $g->expiry_hours) . ' trial',
            $hoursLeft !== null && $hoursLeft < 1        => 'Under 1 h left',
            default                                      => self::humanHours((int) $hoursLeft) . ' left',
        };
    }

    /** What the Extend dialog needs to explain itself honestly for THIS grant. */
    private static function extendPayload(DemoAccessGrant $g, string $status): ?array
    {
        if (! in_array($status, self::EXTENDABLE, true)) {
            return null;
        }

        return [
            'company'     => $g->company_name,
            'status'      => $status,
            'statusLabel' => $g->statusLabel(),
            'action'      => route('admin.demo-access.extend', $g),
            'endsAt'      => $g->expires_at?->format('D j M Y, H:i'),
            'trial'       => $g->expires_at === null ? self::humanHours((int) $g->expiry_hours) : null,
        ];
    }

    // ---- Sparklines --------------------------------------------------------

    /**
     * Page views per day for the last SPARK_DAYS days, per grant id. One grouped
     * query for the whole page of cards, not one per card.
     *
     * @param  list<int>  $ids
     * @return array<int,list<int>>   oldest day first
     */
    public static function sparklines(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $start = Carbon::today()->subDays(self::SPARK_DAYS - 1);

        $rows = DB::table('demo_page_views as pv')
            ->join('demo_sessions as s', 's.id', '=', 'pv.demo_session_id')
            ->whereIn('s.demo_access_grant_id', $ids)
            ->where('pv.viewed_at', '>=', $start)
            ->selectRaw('s.demo_access_grant_id as gid, DATE(pv.viewed_at) as d, COUNT(*) as n')
            ->groupBy('gid', 'd')
            ->get();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = array_fill(0, self::SPARK_DAYS, 0);
        }
        foreach ($rows as $r) {
            $idx = (int) $start->diffInDays(Carbon::parse($r->d)->startOfDay());
            if ($idx >= 0 && $idx < self::SPARK_DAYS) {
                $out[(int) $r->gid][$idx] = (int) $r->n;
            }
        }

        return $out;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
