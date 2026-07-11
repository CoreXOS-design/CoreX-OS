<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DemoTncVersion;
use App\Services\Demo\DemoAccessService;
use App\Support\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The demo host's control API. Runs on PRIMARY.
 *
 * Spec: .ai/specs/demo-access-control.md §5
 *
 * Authenticated by the EXISTING machine-auth layer: the agency-api guard resolves
 * an AgencyApiKey from the bearer token, and website.scope:<scope> authorises it.
 * No new auth layer — and deliberately NOT behind `website.live`, which 403s unless
 * agency.website_enabled and has nothing to do with the demo.
 *
 * Registered in routes/api.php (bearer, machine-to-machine). NOT in web.php —
 * that group is for cookie-authenticated browser XHR.
 *
 * NB the URL prefix is /api/v1/demo-access, NOT /api/v1/demo — the latter is
 * already the mobile app's demo-login group (api.demo.status / api.demo.login).
 */
class DemoAccessApiController extends Controller
{
    public function __construct(private readonly DemoAccessService $service)
    {
    }

    /**
     * GET /api/v1/demo-access/ping — "is this token wired up correctly?"
     *
     * Powers the Test-connection button on the demo's Demo Connection page. Getting
     * a clear yes/no HERE, at the moment someone pastes a token, is the difference
     * between a 10-second fix and a prospect hitting a gate that correctly fails
     * closed and looks indistinguishable from an outage.
     */
    public function ping(Request $request): JsonResponse
    {
        $this->assertPrimary();

        $connector = $request->attributes->get('demo_connector');

        return response()->json([
            'ok'            => true,
            'instance'      => Instance::role(),
            'connector'     => $connector?->name,
            'tnc_version'   => DemoTncVersion::current()?->version,
            // Surfaced because a live connection with NO published terms still means
            // every prospect is hard-blocked at the clickwrap. Better to say so on
            // the Test-connection result than to let it read as a clean pass.
            'tnc_published' => DemoTncVersion::current() !== null,
        ]);
    }

    /**
     * POST /api/v1/demo-access/verify — exchange email + code for a session.
     */
    public function verify(Request $request): JsonResponse
    {
        $this->assertPrimary();

        $data = $request->validate([
            'email'      => ['required', 'string', 'email', 'max:255'],
            'code'       => ['required', 'string', 'max:64'],
            'ip'         => ['nullable', 'string', 'max:45'],
            'user_agent' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->service->verify(
            email:     $data['email'],
            code:      $data['code'],
            ip:        $data['ip'] ?? $request->ip(),
            userAgent: $data['user_agent'] ?? null,
        );

        if (! $result['ok']) {
            // 200, not 4xx: this is a business verdict the demo host must render as
            // a friendly gate message, not a transport failure it should treat as
            // "primary is down" (which would fail closed with the WRONG reason).
            return response()->json([
                'ok'      => false,
                'status'  => $result['status'],
                'message' => $result['message'],
            ]);
        }

        return response()->json([
            'ok'      => true,
            'status'  => $result['status'],
            'session' => ['token' => $result['session']->session_token],
            'grant'   => $this->grantPayload($result['grant']),
            'tnc'     => $this->tncPayload($result['grant']),
        ]);
    }

    /**
     * GET /api/v1/demo-access/session/{token} — re-check a live session.
     *
     * The demo gate calls this on every request (cached 60s its side). This is the
     * round trip that makes revoke and expiry actually bite.
     */
    public function session(Request $request, string $token): JsonResponse
    {
        $this->assertPrimary();

        $result = $this->service->checkSession($token);

        if (! $result['ok']) {
            return response()->json([
                'ok'      => false,
                'status'  => $result['status'],
                'message' => $result['message'],
            ]);
        }

        return response()->json([
            'ok'     => true,
            'status' => $result['status'],
            'grant'  => $this->grantPayload($result['grant']),
            'tnc'    => $this->tncPayload($result['grant']),
        ]);
    }

    /**
     * POST /api/v1/demo-access/accept-tnc — record clickwrap acceptance.
     */
    public function acceptTnc(Request $request): JsonResponse
    {
        $this->assertPrimary();

        $data = $request->validate([
            'session_token' => ['required', 'string', 'size:36'],
            'ip'            => ['nullable', 'string', 'max:45'],
            'user_agent'    => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->service->checkSession($data['session_token']);

        if (! $result['ok']) {
            return response()->json([
                'ok'      => false,
                'status'  => $result['status'],
                'message' => $result['message'],
            ]);
        }

        $acceptance = $this->service->acceptTnc(
            grant:     $result['grant'],
            ip:        $data['ip'] ?? $request->ip(),
            userAgent: $data['user_agent'] ?? null,
        );

        if (! $acceptance) {
            // No T&C published. FAIL CLOSED — the clickwrap is a legal control and
            // "there is no text to show" is not a reason to waive it. The
            // DemoTncVersionSeeder (registered in deploy:sync-reference-data)
            // guarantees v1 exists; if we are here, that is the bug.
            return response()->json([
                'ok'      => false,
                'status'  => 'no_tnc',
                'message' => 'The demo terms are unavailable. Please contact us.',
            ], 503);
        }

        return response()->json([
            'ok'     => true,
            'status' => $result['grant']->status(),
            'tnc'    => $this->tncPayload($result['grant']->fresh()),
        ]);
    }

    /**
     * POST /api/v1/demo-access/page-view — telemetry.
     *
     * FAILS OPEN. An unknown session token is a 204, never an error: by the time
     * this is called the prospect has already been served their page, and there is
     * no user left to tell. Erroring here would only turn a lost data point into a
     * failed queue job and a red light on a dashboard.
     */
    public function pageView(Request $request): JsonResponse
    {
        $this->assertPrimary();

        $data = $request->validate([
            'session_token' => ['required', 'string', 'size:36'],
            'path'          => ['required', 'string', 'max:255'],
            'route_name'    => ['nullable', 'string', 'max:255'],
            'title'         => ['nullable', 'string', 'max:255'],
        ]);

        $this->service->recordPageView(
            sessionToken: $data['session_token'],
            path:         $data['path'],
            routeName:    $data['route_name'] ?? null,
            title:        $data['title'] ?? null,
        );

        return response()->json(['ok' => true]);
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * The durable records live here, on primary. If this ever runs on a demo host
     * it is writing grants into a database that gets dropped every 3 days.
     */
    private function assertPrimary(): void
    {
        if (! Instance::isPrimary()) {
            throw ValidationException::withMessages([
                'instance' => 'The demo control API is only served by the primary instance.',
            ]);
        }
    }

    private function grantPayload($grant): array
    {
        return [
            'id'           => $grant->id,
            'company_name' => $grant->company_name,
            'contact_name' => $grant->contact_name,
            'email'        => $grant->contact_email,
            'expires_at'   => optional($grant->expires_at)->toIso8601String(),
            'status'       => $grant->status(),
        ];
    }

    /** What the demo host needs to decide whether to show the clickwrap. */
    private function tncPayload($grant): array
    {
        $current = DemoTncVersion::current();

        return [
            'current_version' => $current?->version,
            'body'            => $current?->body,
            'accepted'        => $grant->hasAcceptedCurrentTnc(),
        ];
    }
}
