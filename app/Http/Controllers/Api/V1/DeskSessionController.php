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

    /** POST /api/v1/desk/sessions/{id}/heartbeat {page} - keeps the session alive; answers with its status so a dead one is noticed. */
    public function heartbeat(Request $request, int $id): JsonResponse
    {
        $session = $this->mine($request, $id);
        $session = $this->desk->heartbeat($session, $request->input('page'));

        return response()->json(['status' => $session->status, 'ended_by' => $session->ended_by]);
    }

    /** POST /api/v1/desk/sessions/{id}/leaving - beacon from a page that is closing; the next page has a short grace to reconnect. */
    public function leaving(Request $request, int $id): JsonResponse
    {
        $this->desk->leaving($this->mine($request, $id));

        return response()->json(['ok' => true]);
    }

    /** POST /api/v1/desk/sessions/{id}/end - the agent ends it (Cancel on the code screen, or End session on the red bar). */
    public function end(Request $request, int $id): JsonResponse
    {
        $session = $this->desk->end($this->mine($request, $id), DeskSession::ENDED_BY_AGENT, $request->user());

        return response()->json(['status' => $session->status]);
    }

    // ── Take-over (phase 2): the agent's answers and the report of what their browser replayed ──────────

    /** POST /api/v1/desk/sessions/{id}/control/respond {allow} - Allow / Decline a request for control. */
    public function controlRespond(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['allow' => ['required', 'boolean']]);

        return $this->control(fn () => $this->desk->respondControl($this->mine($request, $id), $request->user(), (bool) $data['allow']));
    }

    /** POST /api/v1/desk/sessions/{id}/control/revoke {reason?: agent|idle} - "Take back control" (or the idle clock ran out here). */
    public function controlRevoke(Request $request, int $id): JsonResponse
    {
        $reason = $request->input('reason') === 'idle' ? 'idle' : 'agent';

        return $this->control(fn () => $this->desk->revokeControl($this->mine($request, $id), $request->user(), $reason));
    }

    /** POST /api/v1/desk/sessions/{id}/control/actions {tab, actions: [{type, selector, label, ok, reason, n}]} - what ran (never typed values). */
    public function controlActions(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tab' => ['nullable', 'string', 'max:12'], 'actions' => ['required', 'array', 'max:200']]);

        try {
            $n = $this->desk->recordControlActions($this->mine($request, $id), $request->user(), (string) ($data['tab'] ?? 'main'), $data['actions']);
        } catch (DeskControlException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['recorded' => $n]);
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
