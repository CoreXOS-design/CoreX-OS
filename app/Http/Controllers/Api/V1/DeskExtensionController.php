<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeskSession;
use App\Services\Desk\DeskControlException;
use App\Services\Desk\DeskNotLiveException;
use App\Services\Desk\DeskSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * CoreX Desk phase 3 - the CoreX Chrome extension's side of the API (spec .ai/specs/corex-desk.md section 13). The extension
 * authenticates with its Sanctum bearer token (the same one it uses for every other /api/v1 call), so the browser-session routes of
 * DeskSessionController cannot serve it. Everything here acts as the token's user and only on THEIR OWN open session; gated by
 * desk.request_help like Get help itself. Nothing from a shared tab's screen passes through the server.
 */
class DeskExtensionController extends Controller
{
    public function __construct(private DeskSessionService $desk)
    {
    }

    /** GET /api/v1/desk/ext/session - the user's open session (if any), the Reverb connection details, and the sites that may be shared. */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId();
        $key = config('reverb.apps.apps.0.key');
        $public = config('reverb.public');
        $enabled = $this->desk->agencyAllowsDesk($agencyId) && filled($key) && filled($public['host'] ?? null);
        $session = $enabled ? $this->desk->currentFor($user) : null;

        return response()->json([
            'enabled' => $enabled,
            'reverb' => $enabled ? ['key' => $key, 'host' => $public['host'], 'port' => (int) $public['port'], 'scheme' => $public['scheme']] : null,
            'allowed_hosts' => $this->desk->allowedExtensionHosts($agencyId),
            'heartbeat_seconds' => (int) config('desk.heartbeat_seconds', 30),
            'session' => $session?->load('agent', 'agency', 'supportUser')->toClientArray(),
            'tabs' => $session && $session->isLive() ? array_values($this->desk->openTabs($session)) : [],
            'control_idle_seconds' => $this->desk->controlIdleMinutes($agencyId ? (int) $agencyId : null) * 60,
        ]);
    }

    /**
     * POST /api/v1/desk/ext/broadcasting-auth {socket_id, channel_name} - the private-channel handshake for a token user. Deliberately
     * limited to the Desk channel: this endpoint must never authorise any other private channel.
     */
    public function channelAuth(Request $request)
    {
        $channel = (string) $request->input('channel_name', '');
        abort_unless(preg_match('/^private-desk\.session\.\d+$/', $channel) === 1, 403);

        return Broadcast::auth($request);
    }

    /** POST /api/v1/desk/ext/sessions/{id}/heartbeat - the extension keeps the session alive while it shares a tab. */
    public function heartbeat(Request $request, int $id): JsonResponse
    {
        $session = $this->desk->heartbeat($this->mine($request, $id), null);

        return response()->json(['status' => $session->status, 'ended_by' => $session->ended_by]);
    }

    /** POST /api/v1/desk/ext/sessions/{id}/tabs {action: join|update|leave, tab: "x<id>", url, title} */
    public function tab(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:join,update,leave'],
            'tab' => ['required', 'string', 'regex:/^x\d{1,10}$/'],
            'url' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $tabs = $this->desk->tabEvent($this->mine($request, $id), $request->user(), $data['action'], $data['tab'], $data['url'] ?? null, $data['title'] ?? null);
        } catch (DeskNotLiveException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['tabs' => array_values($tabs)]);
    }

    /** POST /api/v1/desk/ext/sessions/{id}/message {text, tab, id} - the agent's reply to support from a shared Chrome tab. */
    public function message(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000'], 'tab' => ['nullable', 'string', 'regex:/^x\d{1,10}$/'], 'id' => ['nullable', 'string', 'max:24']]);
        try {
            $row = $this->desk->message($this->mine($request, $id), $request->user(), 'agent', $data['text'], $data['tab'] ?? null, $data['id'] ?? null);
        } catch (DeskNotLiveException) {
            return response()->json(['message' => 'That Desk session is not live.'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'message' => $row]);
    }

    // ── Take-over on a shared extension tab (phase 2): same answers and the same report as the CoreX page, over the token ────────

    /** POST /api/v1/desk/ext/sessions/{id}/control/respond {allow} */
    public function controlRespond(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['allow' => ['required', 'boolean']]);

        return $this->control(fn () => $this->desk->respondControl($this->mine($request, $id), $request->user(), (bool) $data['allow']));
    }

    /** POST /api/v1/desk/ext/sessions/{id}/control/revoke {reason?: agent|idle} */
    public function controlRevoke(Request $request, int $id): JsonResponse
    {
        $reason = $request->input('reason') === 'idle' ? 'idle' : 'agent';

        return $this->control(fn () => $this->desk->revokeControl($this->mine($request, $id), $request->user(), $reason));
    }

    /** POST /api/v1/desk/ext/sessions/{id}/control/actions {tab, actions: [...]} */
    public function controlActions(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['tab' => ['required', 'string', 'regex:/^x\d{1,10}$/'], 'actions' => ['required', 'array', 'max:200']]);

        try {
            $n = $this->desk->recordControlActions($this->mine($request, $id), $request->user(), $data['tab'], $data['actions']);
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
}
