<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeskSession;
use App\Models\Platform\PlatformCompany;
use App\Services\Desk\DeskControlException;
use App\Services\Desk\DeskSessionService;
use App\Services\Desk\DeskUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CoreX Desk - the AGENT's side of the API (spec .ai/specs/corex-desk.md section 7.2). Session-authenticated (web group), own
 * session only: someone else's session id is a 404. Gated by desk.request_help.
 */
class DeskSessionController extends Controller
{
    public function __construct(private DeskSessionService $desk)
    {
    }

    /** POST /api/v1/desk/sessions - "Get help": a waiting session and its 6-digit code. */
    public function store(Request $request): JsonResponse
    {
        try {
            [$session, $code] = $this->desk->start($request->user(), $request->ip(), $request->userAgent());
        } catch (DeskUnavailableException) {
            return response()->json([
                'message' => 'CoreX Desk is switched off for your agency.',
                'phone' => self::supportPhone(),
            ], 409);
        }

        return response()->json([
            'session' => $session->load('agent', 'agency')->toClientArray(),
            'code' => $code,
            'code_minutes' => (int) config('desk.code_minutes', 10),
        ], 201);
    }

    /** GET /api/v1/desk/sessions/current - the agent's open session, if any (the page restores its bar from this). */
    public function current(Request $request): JsonResponse
    {
        $session = $this->desk->currentFor($request->user());

        return response()->json(['session' => $session?->load('agent', 'agency', 'supportUser')->toClientArray()]);
    }

    /** POST /api/v1/desk/sessions/{id}/heartbeat {page, active} - `active` = real input since the last ping (a ping alone is not activity); keeps the session alive; answers with its status so a dead one is noticed. */
    public function heartbeat(Request $request, int $id): JsonResponse
    {
        $session = $this->mine($request, $id);
        $session = $this->desk->heartbeat($session, $request->input('page'), $request->boolean('active'));
        $tabs = [];
        if ($session->isLive() && is_string($request->input('tab'))) {
            $this->desk->touchTab($session, $this->desk->cleanTabKey($request->input('tab')));
            // the tabs the server holds as open: a tab that is missing from it (a "leaving" that overtook its "joined") joins again
            $tabs = array_keys($this->desk->openTabs($session));
        }

        return response()->json(['status' => $session->status, 'ended_by' => $session->ended_by, 'server_ms' => (int) round(microtime(true) * 1000), 'tabs' => $tabs]);
    }

    /** POST /api/v1/desk/sessions/{id}/leaving - beacon from a page that is closing; the next page has a short grace to reconnect. */
    public function leaving(Request $request, int $id): JsonResponse
    {
        $session = $this->mine($request, $id);
        $this->desk->leaving($session);
        // A CoreX tab going away says so by name: the console drops it unless the same tab comes back (its next page) within the grace.
        $tab = $request->input('tab');
        if (is_string($tab) && $session->isLive()) {
            try {
                $this->desk->coreXTabEvent($session, $request->user(), 'leave', $this->desk->cleanTabKey($tab), null, null, null, is_numeric($request->input('at')) ? (int) $request->input('at') : null);
            } catch (\Throwable) {
                // best effort: a beacon has nobody to tell
            }
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/desk/sessions/{id}/end - the agent ends it (Cancel on the code screen, or End session on the red bar). */
    public function end(Request $request, int $id): JsonResponse
    {
        $session = $this->desk->end($this->mine($request, $id), DeskSession::ENDED_BY_AGENT, $request->user());

        return response()->json(['status' => $session->status]);
    }

    // ── The agent's CoreX tabs (spec section 4f) ─────────────────────────────────────────────────────

    /** POST /api/v1/desk/sessions/{id}/tabs {action: join|update|leave, tab, path, title, from?} - this CoreX tab joins (or leaves) the session. */
    public function tab(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:join,update,leave'],
            'tab' => ['required', 'string', 'regex:/^(main|c[a-z0-9]{3,10})$/'],
            'path' => ['nullable', 'string', 'max:300'],
            'title' => ['nullable', 'string', 'max:300'],
            'from' => ['nullable', 'string', 'regex:/^(main|c[a-z0-9]{3,10})$/'],
            'at' => ['nullable', 'integer'],
        ]);
        try {
            $tabs = $this->desk->coreXTabEvent($this->mine($request, $id), $request->user(), $data['action'], $data['tab'], $data['path'] ?? null, $data['title'] ?? null, $data['from'] ?? null, $data['at'] ?? null);
        } catch (\App\Services\Desk\DeskNotLiveException) {
            return response()->json(['message' => 'That Desk session is not live.'], 409);
        }

        $session = $this->mine($request, $id);

        return response()->json(['tabs' => array_values($tabs), 'session' => $session->load('agent', 'agency', 'supportUser')->toClientArray(), 'messages' => $this->desk->recentMessages($session)]);
    }

    /** POST /api/v1/desk/sessions/{id}/highlight/clear {tab, reason: clicked|navigated} - the agent clicked the highlighted element, or left the page. */
    public function highlightClear(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tab' => ['nullable', 'string', 'max:12'], 'reason' => ['nullable', 'string', 'max:12']]);
        $this->desk->highlightDone($this->mine($request, $id), $request->user(), $data['tab'] ?? null, (string) ($data['reason'] ?? 'clicked'));

        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/desk/sessions/{id}/message {text, tab, id} - the agent's reply to support (audited; the text also travels as a whisper). */
    public function message(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000'], 'tab' => ['nullable', 'string', 'max:12'], 'id' => ['nullable', 'string', 'max:24']]);
        try {
            $row = $this->desk->message($this->mine($request, $id), $request->user(), 'agent', $data['text'], $data['tab'] ?? null, $data['id'] ?? null);
        } catch (\App\Services\Desk\DeskNotLiveException) {
            return response()->json(['message' => 'That Desk session is not live.'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => $row]);
    }

    // ── Take-over (phase 2): the agent's answers and the report of what their browser replayed ──────────

    /**
     * POST /api/v1/desk/sessions/{id}/control/respond {allow, request_id} - Allow / Decline a request for control. The request_id is the one the question
     * carried (a mismatch is a 409). An Allow answers with the grant (the signing key) - this answer and the agent's own `control/key` call are the only
     * places the agent's browser gets it.
     */
    public function controlRespond(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['allow' => ['required', 'boolean'], 'request_id' => ['required', 'string', 'max:40']]);

        return $this->control(fn () => $this->desk->respondControl($this->mine($request, $id), $request->user(), (bool) $data['allow'], $data['request_id']), $id, (bool) $data['allow']);
    }

    /** POST /api/v1/desk/sessions/{id}/control/key {tab} - the agent's page asks for the grant again (a page change, or control that moved to this tab). 404 unless control is granted for exactly that tab. */
    public function controlKey(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tab' => ['required', 'string', 'max:12']]);
        $grant = $this->desk->agentGrant($this->mine($request, $id), $request->user(), $data['tab']);

        return $grant ? response()->json(['grant' => $grant])->header('Cache-Control', 'no-store') : response()->json(['message' => 'Control is not granted for that tab.'], 404);
    }

    /** POST /api/v1/desk/sessions/{id}/control/revoke {reason?: agent|idle} - "Take back control" (or the idle clock ran out here). */
    public function controlRevoke(Request $request, int $id): JsonResponse
    {
        $reason = $request->input('reason') === 'idle' ? 'idle' : 'agent';

        return $this->control(fn () => $this->desk->revokeControl($this->mine($request, $id), $request->user(), $reason), $id);
    }

    /** POST /api/v1/desk/sessions/{id}/control/actions {tab, actions: [{type, selector, label, ok, reason, n}]} - what ran (never typed values). */
    public function controlActions(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tab' => ['nullable', 'string', 'max:12'], 'actions' => ['required', 'array', 'max:200']]);

        try {
            $n = $this->desk->recordControlActions($this->mine($request, $id), $request->user(), (string) ($data['tab'] ?? 'main'), array_values(array_filter($data['actions'], 'is_array')));
        } catch (DeskControlException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['recorded' => $n]);
    }

    private function control(\Closure $do, ?int $id = null, bool $withGrant = false): JsonResponse
    {
        try {
            $session = $do();
        } catch (DeskControlException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
        $body = ['control' => $session->toClientArray()['control']];
        if ($withGrant && $session->control_state === DeskSession::CONTROL_GRANTED) {
            $body['grant'] = $this->desk->agentGrant($session, request()->user(), $session->control_tab);
        }

        return response()->json($body)->header('Cache-Control', 'no-store');
    }

    private function mine(Request $request, int $id): DeskSession
    {
        return DeskSession::withoutGlobalScopes()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    /** The CoreX platform support line, shown where Desk is switched off. */
    public static function supportPhone(): ?string
    {
        $phones = collect(PlatformCompany::current()->phones ?? []);
        $row = $phones->first(fn ($p) => stripos((string) ($p['label'] ?? ''), 'support') !== false) ?? $phones->first();

        return $row['number'] ?? null;
    }
}
