<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreX\DeskConsoleController;
use App\Models\DeskSession;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CoreX Desk - the permanent audit of every session (Compliance > Reports & Logs > Desk sessions; spec
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

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('started_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('started_at', '<=', $to);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $query->where(function ($w) use ($like, $user) {
                $w->whereHas('agent', fn ($a) => $a->where('name', 'like', $like))
                    ->orWhereHas('supportUser', fn ($s) => $s->where('name', 'like', $like));
                if ($user->isOwnerRole()) {
                    $w->orWhereHas('agency', fn ($a) => $a->where('name', 'like', $like));
                }
            });
        }

        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'started';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
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
