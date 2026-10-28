<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreX\DeskConsoleController;
use App\Models\DeskSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CoreX Desk - the permanent audit of every session (Admin > Desk > Desk sessions; spec
 * .ai/specs/corex-desk.md sections 5 and 9). Read-only: there is no edit or delete. An agency admin sees only their own agency's
 * sessions, owner roles see every agency's - enforced in the query, so a session id from another agency is a 404.
 */
class DeskSessionsController extends Controller
{
    private const SORTS = [
        'started' => 'started_at',
        'status' => 'status',
        'duration' => 'joined_at',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        $query = DeskSessionsController::scoped($request)->with('agent', 'supportUser', 'agency');

        // (a filter is text: ?status[]=x, ?sort[]=x and the like are dropped from the request - for the view too - instead of crashing the page)
        foreach (['q', 'status', 'from', 'to', 'sort', 'dir'] as $k) {
            if (is_array($request->query($k))) {
                $request->query->remove($k);
            }
        }
        $text = fn (string $key): string => is_string($request->query($key)) ? trim((string) $request->query($key)) : '';
        if (($status = $text('status')) !== '') {
            $query->where('status', $status);
        }
        if (($from = $text('from')) !== '' && strtotime($from) !== false) {
            $query->whereDate('started_at', '>=', $from);
        }
        if (($to = $text('to')) !== '' && strtotime($to) !== false) {
            $query->whereDate('started_at', '<=', $to);
        }
        if (($q = $text('q')) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(function ($w) use ($like, $user) {
                $w->whereHas('agent', fn ($a) => $a->where('name', 'like', $like))
                    ->orWhereHas('supportUser', fn ($s) => $s->where('name', 'like', $like));
                if ($user->isOwnerRole()) {
                    $w->orWhereHas('agency', fn ($a) => $a->where('name', 'like', $like));
                }
            });
        }

        $sortIn = $text('sort');
        $sort = array_key_exists($sortIn, self::SORTS) ? $sortIn : 'started';
        $dir = $text('dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy(self::SORTS[$sort], $dir)->orderBy('id', 'desc');

        return view('compliance.desk-sessions.index', [
            'sessions' => $query->paginate(25)->withQueryString(),
            'isOwner' => $user->isOwnerRole(),
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $session = self::scoped($request)->with('agent', 'supportUser', 'agency', 'endedByUser', 'events.actor')->findOrFail($id);

        return view('compliance.desk-sessions.show', ['session' => $session]);
    }

    /** The sessions this user may see (own agency; every agency for an owner role) - the one scope used by list and detail. */
    public static function scoped(Request $request)
    {
        return DeskConsoleController::scopeQuery(DeskSession::withoutGlobalScopes(), $request->user());
    }
}
