<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\DeskSession;
use App\Services\Desk\DeskControlException;
use App\Services\Desk\DeskRateLimitedException;
use App\Services\Desk\DeskSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * CoreX Desk - the SUPPORT console (spec .ai/specs/corex-desk.md section 4). Route group gated by permission:desk.support.
 * Owner roles reach every agency; anyone else only their own agency's sessions (DeskSessionService::canSupport) - a session
 * outside that scope is a 404, the same answer as no session.
 */
class DeskConsoleController extends Controller
{
    public function __construct(private DeskSessionService $desk)
    {
    }

    /** GET /corex/desk - the console page: join box, live view, live sessions. */
    public function index(Request $request): View
    {
        $this->desk->expireStale();

        return view('desk.console', [
            'sessions' => $this->visibleSessions($request)->open()->with('agent', 'agency', 'supportUser')->latest('id')->get(),
            'consoleConfig' => ['canControl' => (bool) $request->user()->hasPermission('desk.control'), 'me' => \App\Support\Desk\DeskSupporterLabel::for($request->user()), 'timing' => (bool) config('desk.timing_overlay'), 'messageMax' => (int) config('desk.message_max', 300), 'urls' => [
                'join' => route('corex.desk.join'),
                'list' => route('corex.desk.sessions'),
                'sessions' => url('corex/desk/sessions'),
            ]],
        ]);
    }

    /** GET /corex/desk/sessions - the live (waiting + live) sessions the user may see, as JSON for the console list. */
    public function sessions(Request $request): JsonResponse
    {
        $this->desk->expireStale();
        $rows = $this->visibleSessions($request)->open()->with('agent', 'agency', 'supportUser')->latest('id')->get()
            ->map(function (DeskSession $s) {
                if ($s->isLive()) {
                    $this->desk->settleTabs($s);   // a CoreX tab that was closed leaves the console within a couple of seconds
                }

                return $s->toClientArray() + ['tabs' => $s->isLive() ? array_values($this->desk->openTabs($s)) : []];
            })->values();

        return response()->json(['sessions' => $rows, 'server_ms' => (int) round(microtime(true) * 1000)]);
    }

    /** POST /corex/desk/join {code} */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        try {
            $session = $this->desk->join($request->user(), $data['code']);
        } catch (DeskRateLimitedException $e) {
            return response()->json(['message' => 'Too many wrong codes. Try again in ' . ceil($e->retryAfter / 60) . ' minute(s).'], 429);
        }
        if (! $session) {
            return response()->json(['message' => 'That code was not found, or it has expired. Ask the agent for a new one.'], 422);
        }

        return response()->json(['session' => $session->load('agent', 'agency', 'supportUser')->toClientArray() + ['tabs' => array_values($this->desk->openTabs($session)), 'messages' => $this->desk->recentMessages($session)]]);
    }

    /** POST /corex/desk/sessions/{id}/attach - open a live session of your scope again, or join it as a second support person. */
    public function attach(Request $request, int $id): JsonResponse
    {
        $session = DeskSession::withoutGlobalScopes()->find($id);
        $attached = $session ? $this->desk->attach($session, $request->user()) : null;
        if (! $attached) {
            return response()->json(['message' => 'That session is not open any more.'], 404);
        }

        return response()->json(['session' => $attached->load('agent', 'agency', 'supportUser')->toClientArray() + ['tabs' => array_values($this->desk->openTabs($attached)), 'messages' => $this->desk->recentMessages($attached)]]);
    }

    /** POST /corex/desk/sessions/{id}/highlight {selector, note} */
    public function highlight(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'selector' => ['required', 'string', 'max:' . (int) config('desk.selector_max', 500)],
            'note' => ['nullable', 'string', 'max:' . (int) config('desk.note_max', 200)],
            'tab' => ['nullable', 'string', 'max:12'],
        ]);
        $this->desk->highlight($this->mine($request, $id), $request->user(), $data['selector'], $data['note'] ?? null, $data['tab'] ?? null);

        return response()->json(['ok' => true]);
    }

    /** POST /corex/desk/sessions/{id}/highlight/clear */
    public function clearHighlight(Request $request, int $id): JsonResponse
    {
        $this->desk->clearHighlight($this->mine($request, $id), $request->user(), $request->input('tab'));

        return response()->json(['ok' => true]);
    }

    /** POST /corex/desk/sessions/{id}/message {text, tab, id} - a line from support to the agent (audited; the text also travels as a whisper). */
    public function message(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000'], 'tab' => ['nullable', 'string', 'max:12'], 'id' => ['nullable', 'string', 'max:24']]);
        try {
            $row = $this->desk->message($this->mine($request, $id), $request->user(), 'support', $data['text'], $data['tab'] ?? null, $data['id'] ?? null);
        } catch (\App\Services\Desk\DeskNotLiveException) {
            return response()->json(['message' => 'That Desk session is not live.'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => $row]);
    }

    /** POST /corex/desk/sessions/{id}/pointer {on} - audited on/off only; the moves themselves travel on the channel. */
    public function pointer(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean'], 'tab' => ['nullable', 'string', 'max:12']]);
        $this->desk->pointer($this->mine($request, $id), $request->user(), (bool) $data['on'], $data['tab'] ?? null);

        return response()->json(['ok' => true]);
    }

    /** POST /corex/desk/sessions/{id}/timing - only while the timing overlay is switched on (config desk.timing_overlay). */
    public function timing(Request $request, int $id): JsonResponse
    {
        abort_unless(config('desk.timing_overlay'), 404);

        return response()->json(['server_ms' => $this->desk->timingProbe($this->mine($request, $id)), 'done_ms' => (int) round(microtime(true) * 1000)]);
    }

    /** POST /corex/desk/sessions/{id}/end */
    public function end(Request $request, int $id): JsonResponse
    {
        $session = $this->desk->end($this->scoped($request, $id), DeskSession::ENDED_BY_SUPPORT, $request->user());

        return response()->json(['status' => $session->status]);
    }

    /** POST /corex/desk/sessions/{id}/control/request {tab} - ask the agent to let you take control of the CoreX page or a shared tab. */
    public function controlRequest(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tab' => ['nullable', 'string', 'max:12']]);

        return $this->control(fn () => $this->desk->requestControl($this->scoped($request, $id), $request->user(), (string) ($data['tab'] ?? 'main')));
    }

    /** POST /corex/desk/sessions/{id}/control/end - hand control back (or withdraw a request nobody has answered). */
    public function controlEnd(Request $request, int $id): JsonResponse
    {
        return $this->control(fn () => $this->desk->endControlBySupport($this->scoped($request, $id), $request->user()));
    }

    private function control(\Closure $do): JsonResponse
    {
        try {
            $session = $do();
        } catch (DeskControlException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['control' => $session->toClientArray()['control']]);
    }

    /** A LIVE session this support user has joined (highlight / pointer need the joined user, not any support user). */
    private function mine(Request $request, int $id): DeskSession
    {
        $session = $this->scoped($request, $id);
        abort_unless($session->isLive() && $session->isSupporter($request->user()), 404);

        return $session;
    }

    private function scoped(Request $request, int $id): DeskSession
    {
        $session = DeskSession::withoutGlobalScopes()->findOrFail($id);
        abort_unless($this->desk->canSupport($request->user(), $session), 404);

        return $session;
    }

    /** Sessions in this user's scope: every agency for an owner role, otherwise their own agency only. */
    public static function scopeQuery($query, $user)
    {
        if ($user->isOwnerRole()) {
            return $query;
        }

        return $query->where('agency_id', $user->effectiveAgencyId() ?: 0);
    }

    private function visibleSessions(Request $request)
    {
        return self::scopeQuery(DeskSession::withoutGlobalScopes(), $request->user());
    }
}
