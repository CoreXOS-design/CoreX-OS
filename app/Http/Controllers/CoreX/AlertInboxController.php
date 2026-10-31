<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\NotificationEventType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The alert inbox (spec notification-system-overview.md section 16): every alert the logged-in user has been sent, with its
 * read state. Strictly the user's OWN alerts - every query is pinned to notifiable = the logged-in user, and an id that is
 * someone else's is a 404. Alerts are never deleted from here (read / unread only).
 */
class AlertInboxController extends Controller
{
    public const PER_PAGE = 25;

    private const TITLE = "JSON_UNQUOTE(JSON_EXTRACT(data, '\$.title'))";
    private const MESSAGE = "JSON_UNQUOTE(JSON_EXTRACT(data, '\$.message'))";
    private const BODY = "JSON_UNQUOTE(JSON_EXTRACT(data, '\$.body'))";
    private const KEY = "JSON_UNQUOTE(JSON_EXTRACT(data, '\$.event_key'))";

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:120',
            'status' => 'nullable|in:all,unread,read',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'sort' => 'nullable|in:newest,oldest',
        ]);
        $status = $filters['status'] ?? 'all';
        $sort = $filters['sort'] ?? 'newest';

        $query = $this->own($user);
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->whereRaw(self::TITLE . ' like ?', [$like])
                    ->orWhereRaw(self::MESSAGE . ' like ?', [$like])
                    ->orWhereRaw(self::BODY . ' like ?', [$like]);
            });
        }
        if (! empty($filters['type'])) {
            $query->whereRaw(self::KEY . ' = ?', [$filters['type']]);
        }
        if ($status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse($filters['to'])->endOfDay());
        }
        $query->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc')->orderBy('id');

        $rows = $query->paginate(self::PER_PAGE)->withQueryString();

        // The types this user actually has, labelled from the catalogue (a key with no catalogue row is shown in plain words).
        $keys = $this->own($user)->selectRaw(self::KEY . ' as k')->distinct()->pluck('k')->filter()->values();
        $labels = NotificationEventType::query()->whereIn('key', $keys)->pluck('label', 'key');
        $types = $keys->mapWithKeys(fn ($k) => [$k => $labels[$k] ?? Str::headline(str_replace(['.', '_'], ' ', $k))])->sort();

        return view('corex.alerts.index', [
            'rows' => $rows,
            'types' => $types,
            'labels' => $types,
            'filters' => ['q' => $q ?? '', 'type' => $filters['type'] ?? '', 'status' => $status, 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '', 'sort' => $sort],
            'filtered' => ($q ?? '') !== '' || ! empty($filters['type']) || $status !== 'all' || ! empty($filters['from']) || ! empty($filters['to']),
            'hasAny' => $this->own($user)->exists(),
        ]);
    }

    /** Mark read, then go to the alert's own link (same site only). No link = back to the inbox. */
    public function open(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        abort_if(! $notification, 404);
        $notification->markAsRead();

        $data = $notification->data ?? [];
        $target = $this->sameSite($request, $data['action_url'] ?? ($data['url'] ?? null));

        return redirect($target ?? route('corex.alerts.index'));
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        abort_if(! $notification, 404);
        $notification->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    private function own($user)
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }

    /** An alert link is honoured only if it is a path on this site; anything pointing elsewhere is ignored. */
    private function sameSite(Request $request, mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['host']) && strcasecmp($parts['host'], $request->getHost()) !== 0) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
