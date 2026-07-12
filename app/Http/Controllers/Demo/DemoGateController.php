<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDemoGrant;
use App\Services\Demo\DemoControlClient;
use App\Support\Instance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The demo sign-in gate and the T&C clickwrap. Runs on the DEMO host.
 *
 * Spec: .ai/specs/demo-access-control.md §6.2, §6.3
 *
 * These pages hold no data of their own — every decision is primary's, fetched
 * over DemoControlClient. The demo database is destroyed every 3 days, so the
 * only durable thing this controller does is set a cookie.
 */
class DemoGateController extends Controller
{
    public function __construct(private readonly DemoControlClient $client)
    {
    }

    /** GET /demo/gate — email + access code form. */
    public function show(Request $request)
    {
        $this->assertDemo();

        return view('demo.gate', [
            'message' => session('demo_gate_message'),
        ]);
    }

    /** POST /demo/gate — verify the emailed credential. */
    public function verify(Request $request)
    {
        $this->assertDemo();

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code'  => ['required', 'string', 'max:64'],
        ], [
            'email.required' => 'Enter the email address your invitation was sent to.',
            'email.email'    => 'Enter a valid email address.',
            'code.required'  => 'Enter the access code from your invitation email.',
        ]);

        $res = $this->client->verify(
            // Trim: people paste from an email client and bring whitespace with them.
            email:     trim($data['email']),
            code:      trim($data['code']),
            ip:        $request->ip(),
            userAgent: $request->userAgent(),
        );

        // Could not reach primary. Say THAT — do not tell them their code is wrong
        // when the truth is that our own server is down.
        if (! $res['success']) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $res['message'] ?? 'The demo is temporarily unavailable. Please try again shortly.']);
        }

        $body = $res['data'];

        if (! ($body['ok'] ?? false)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => $body['message'] ?? 'That email and access code do not match.']);
        }

        $token = $body['session']['token'];

        $target = ($body['tnc']['accepted'] ?? false)
            ? route('dashboard')
            : route('demo.tnc');

        // Signed, httpOnly, SameSite=Lax. REMEMBER THE DEVICE FOR THE LIFE OF THE
        // GRANT — see cookieLifetime().
        return redirect($target)->withCookie(
            cookie(
                EnsureDemoGrant::COOKIE,
                $token,
                $this->cookieLifetime($body['grant']['expires_at'] ?? null),
                null, null, true, true, false, 'lax'
            )
        );
    }

    /**
     * How long the browser keeps the session token.
     *
     * This used to be a hardcoded 480 (8h), on the reasoning that a shared machine
     * should not stay signed in overnight. In practice that just made an INVITED
     * prospect re-type an emailed code every working day, and it bought nothing:
     * the cookie is only a bearer of the session token. EnsureDemoGrant re-checks
     * that token against primary on EVERY non-exempt request (60s cache), so a
     * revoked grant, an expired grant or a new T&C version closes the gate within a
     * minute no matter how long the browser has held the cookie. Expiry is enforced
     * on the server, where it belongs — the cookie clock was never the control.
     *
     * So: remember the device for exactly as long as the grant is good for, and let
     * the cookie die with it. A prospect redeems the code once and stays in for the
     * whole trial; the day the grant lapses, so does the cookie.
     *
     * `expires_at` is primary's, ISO8601, and is only set once the grant has been
     * redeemed — which has just happened, on this very request. The fallback covers
     * a primary that returns null (older payload, or a grant whose clock has not
     * been stamped), and the clamp keeps us out of the two states a raw diff can
     * produce: a negative lifetime, which Laravel reads as "delete this cookie", and
     * an absurd one from a malformed date.
     */
    private function cookieLifetime(?string $expiresAt): int
    {
        $fallback = 72 * 60;          // the default grant window
        $ceiling  = 90 * 24 * 60;     // 90 days — a grant should never outlive this

        if (! $expiresAt) {
            return $fallback;
        }

        try {
            $minutes = (int) ceil(now()->diffInMinutes(Carbon::parse($expiresAt), false));
        } catch (\Throwable) {
            return $fallback;
        }

        // Already lapsed: primary will refuse the next request anyway. Give a short
        // real lifetime rather than a negative one (which would drop the cookie and
        // bounce them to the gate with no explanation).
        return max(1, min($minutes, $ceiling));
    }

    /** GET /demo/tnc — the clickwrap. */
    public function tnc(Request $request)
    {
        $this->assertDemo();

        $token = $request->cookie(EnsureDemoGrant::COOKIE);

        if (! $token) {
            return redirect()->route('demo.gate');
        }

        $res = $this->client->checkSession($token);

        if (! $res['success'] || ! ($res['data']['ok'] ?? false)) {
            return redirect()->route('demo.gate')
                ->with('demo_gate_message', $res['data']['message'] ?? $res['message']);
        }

        // Already accepted the current version — nothing to sign.
        if ($res['data']['tnc']['accepted'] ?? false) {
            return redirect()->route('dashboard');
        }

        return view('demo.tnc', [
            'tnc'   => $res['data']['tnc'],
            'grant' => $res['data']['grant'],
        ]);
    }

    /** POST /demo/tnc — record acceptance. */
    public function acceptTnc(Request $request)
    {
        $this->assertDemo();

        $request->validate([
            'accept' => ['accepted'],
        ], [
            'accept.accepted' => 'You must accept the terms to use the demo.',
        ]);

        $token = $request->cookie(EnsureDemoGrant::COOKIE);

        if (! $token) {
            return redirect()->route('demo.gate');
        }

        $res = $this->client->acceptTnc($token, $request->ip(), $request->userAgent());

        if (! $res['success'] || ! ($res['data']['ok'] ?? false)) {
            return back()->withErrors([
                'accept' => $res['data']['message'] ?? $res['message'] ?? 'We could not record your acceptance. Please try again.',
            ]);
        }

        // The gate cached "not accepted" up to 60s ago. Without this, the user
        // accepts and is bounced straight back to the T&C page — which looks like
        // the accept button is broken. Purge our own cache line for this session.
        Cache::forget('demo_gate:' . sha1($token));

        return redirect()->route('dashboard');
    }

    /** POST /demo/gate/logout */
    public function logout(Request $request)
    {
        $this->assertDemo();

        $token = $request->cookie(EnsureDemoGrant::COOKIE);

        if ($token) {
            Cache::forget('demo_gate:' . sha1($token));
        }

        return redirect()->route('demo.gate')
            ->withCookie(cookie()->forget(EnsureDemoGrant::COOKIE));
    }

    /**
     * These routes are registered on every install (one codebase), but they only
     * mean anything on a demo host. On primary they 404 — a sign-in gate for a
     * demo that does not exist here would be a confusing dead end, and worse, a
     * surface to probe.
     */
    private function assertDemo(): void
    {
        if (! Instance::isDemo()) {
            throw new NotFoundHttpException();
        }
    }
}
