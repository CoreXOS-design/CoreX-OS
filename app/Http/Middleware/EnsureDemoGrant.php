<?php

namespace App\Http\Middleware;

use App\Services\Demo\DemoControlClient;
use App\Support\Instance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * The demo gate. Nobody sees the demo without a live, accepted grant.
 *
 * Spec: .ai/specs/demo-access-control.md §6.3
 *
 * ══ THIS FAILS CLOSED ══
 *
 * If primary is unreachable — network down, token wrong, 500 — NOBODY GETS IN.
 * That is deliberate and it is the opposite of the telemetry path (§6.4), which
 * fails OPEN. The asymmetry is the whole design:
 *
 *   - The gate is a SECURITY control. An access control that opens when its
 *     authority is unreachable is not an access control; it is a doorbell.
 *   - Telemetry is an OBSERVABILITY control. A demo page must never block, slow
 *     or error because a page view could not be logged.
 *
 * The single most likely way this bites in production: deploying the demo host
 * with COREX_INSTANCE_ROLE=demo BEFORE primary has the AgencyApiKey minted. The
 * gate then correctly refuses everyone. Spec §15 fixes the ordering; the client
 * logs a distinct, loud message for that case so it is not mistaken for a network
 * fault.
 *
 * ══ REVOKE LATENCY ══
 *
 * Primary's verdict is cached for gate_cache_ttl (60s). So a revoked grant keeps
 * working for up to 60 seconds. That is a real limit, not a bug — and the admin
 * UI's revoke dialog says so out loud rather than implying a kill we cannot
 * deliver. Caching is what stops every demo page load from blocking on a network
 * round trip.
 *
 * Inert on primary: Instance::isDemo() is false there, so this returns instantly.
 */
class EnsureDemoGrant
{
    public const COOKIE = 'corex_demo_session';

    /**
     * Paths the gate must never guard.
     *
     * TWO KINDS OF EXEMPTION HERE, and the distinction matters:
     *
     * 1. The gate's OWN pages. Guarding /demo/gate would redirect it to itself
     *    forever.
     *
     * 2. THE STAFF DOOR — `demo-owner-login`, and the password-authenticated login
     *    routes generally. This is the escape hatch that makes a UI-configured
     *    connector safe: if the stored token is wrong, revoked, or points at the
     *    wrong host, the gate FAILS CLOSED and nobody gets in — including the System
     *    Owner who needs to reach Dev Settings → Demo Connection to fix it. Without
     *    this exemption a bad paste bricks the demo until someone SSHes in.
     *
     *    Exempting them is safe because they are protected by a PASSWORD, and
     *    demo-owner-login additionally refuses anyone who is not an owner. A
     *    password-protected login page being publicly reachable is not a leak — it
     *    is exactly as exposed as the live CoreX login page already is.
     *
     *    What must NOT be exempt is the PASSWORDLESS demo-role login
     *    (`demo-login/{role}`), which signs you in as a real demo user with no
     *    credential at all. That is the door prospects walk through, and
     *    DemoLoginController::login() checks for a live grant cookie itself (it sits
     *    in the `guest` group, outside this middleware's reach).
     */
    private const EXEMPT = [
        // The gate itself.
        'demo/gate',
        'demo/gate/*',
        'demo/tnc',
        'demo/tnc/*',
        'demo/telemetry',

        // The staff door — password-protected, and the only way back in when the
        // connector is misconfigured. NOT 'demo-login/*' (passwordless).
        'demo-owner-login',
        'login',
        'logout',
        'forgot-password',
        'reset-password',
        'reset-password/*',

        'up',                 // Laravel's health endpoint
        'build/*',
        'storage/*',
        'favicon.ico',
    ];

    public function __construct(private readonly DemoControlClient $client)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // On primary this whole feature does not exist.
        if (! Instance::isDemo()) {
            return $next($request);
        }

        if ($request->is(self::EXEMPT)) {
            return $next($request);
        }

        // THE STAFF BYPASS. A signed-in System Owner is us, not a prospect.
        //
        // This is the other half of what makes a UI-configured connector safe: an
        // owner must be able to reach Dev Settings → Demo Connection to FIX a broken
        // connector, and the gate cannot verify anything while the connector is
        // broken. Gating the owner on a working gate would be a deadlock — the only
        // way to repair the demo would be SSH.
        //
        // It is a LOCAL check (the role on the authenticated session), so it holds
        // even when primary is unreachable. It costs nothing: reaching this state
        // requires a password on demo-owner-login, which additionally refuses
        // non-owners.
        if ($request->user()?->isOwnerRole()) {
            return $next($request);
        }

        $token = $request->cookie(self::COOKIE);

        if (! $token || ! is_string($token)) {
            return $this->toGate($request, null);
        }

        $verdict = $this->verdict($token);

        // Transport failure → FAIL CLOSED. Note we do NOT tell the prospect their
        // code is wrong; that would be a lie about our own outage.
        if (! $verdict['reachable']) {
            return $this->toGate($request, $verdict['message'] ?? 'The demo is temporarily unavailable. Please try again shortly.');
        }

        if (! $verdict['ok']) {
            return $this->toGate($request, $verdict['message']);
        }

        // Grant is live but has not accepted the CURRENT T&C version — which also
        // catches everyone mid-session when a new version is published.
        if (! $verdict['tnc_accepted']) {
            return redirect()->route('demo.tnc');
        }

        // Hand the resolved grant to the rest of the request — the watermark and
        // the telemetry beacon both read it from here.
        $request->attributes->set('demo_grant', $verdict['grant']);
        $request->attributes->set('demo_session_token', $token);

        return $next($request);
    }

    /**
     * Primary's verdict on this session, cached for the TTL.
     *
     * The cache key is the session token, so revoking a grant invalidates on the
     * clock rather than on a key we would have to guess how to purge from another
     * host.
     */
    private function verdict(string $token): array
    {
        $ttl = max(1, (int) config('corex.instance.gate_cache_ttl', 60));

        return Cache::remember('demo_gate:' . sha1($token), $ttl, function () use ($token) {
            $res = $this->client->checkSession($token);

            // Could not reach primary at all.
            if (! $res['success']) {
                return [
                    'reachable' => false,
                    'ok'        => false,
                    'message'   => $res['message'],
                ];
            }

            $data = $res['data'];

            return [
                'reachable'    => true,
                'ok'           => (bool) ($data['ok'] ?? false),
                'message'      => $data['message'] ?? null,
                'grant'        => $data['grant'] ?? null,
                'tnc_accepted' => (bool) (($data['tnc']['accepted'] ?? false)),
            ];
        });
    }

    private function toGate(Request $request, ?string $message): Response
    {
        // Never redirect an XHR into an HTML gate page — it would be parsed as the
        // JSON the caller expected and produce a baffling console error instead of
        // an honest 401.
        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'message' => $message ?? 'Your demo session has ended. Please sign in again.',
            ], 401);
        }

        return redirect()
            ->route('demo.gate')
            ->with('demo_gate_message', $message)
            ->withCookie(cookie()->forget(self::COOKIE));
    }
}
