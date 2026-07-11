<?php

use App\Models\FaultReport;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Mobile (Flutter) is a pure bearer-token API client — never stateful.
        // Strip Sanctum's stateful-promotion middleware from the api group so
        // an Origin/Referer header can never trick a bearer-token request into
        // booting the session stack. Prevents session-row deadlocks under
        // parallel cold-start requests on mobile. Idempotent if not present.
        $middleware->api(remove: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Per-agency maintenance gate (AT-93). Appended to the web group so it
        // runs AFTER StartSession — the authenticated user, and therefore their
        // agency, is resolvable. It acts ONLY on a logged-in non-owner whose
        // resolved agency is flagged maintenance_mode; guests, the login page,
        // System Owners and every other agency pass straight through. This is
        // deliberately a TENANT-level gate, not a platform-wide one: the CoreX
        // login always stays reachable. Spec: .ai/specs/maintenance-mode.md
        // AT-230 Demo Access Control — EnsureDemoGrant gates EVERY web page on a
        // demo host behind a live, T&C-accepted grant. It must be global: a single
        // ungated route on demo1.corexos.co.za is an open door, and remembering to
        // decorate each new route is not a security model.
        //
        // INERT on primary — Instance::isDemo() is false there and the middleware
        // returns immediately, so live/staging/local are untouched.
        //
        // It FAILS CLOSED: if primary is unreachable, nobody enters the demo.
        // Spec: .ai/specs/demo-access-control.md §6.3
        $middleware->web(append: [
            \App\Http\Middleware\AgencyMaintenanceGate::class,
            \App\Http\Middleware\EnsureDemoGrant::class,
        ]);

        // EnsureDemoGrant MUST run BEFORE Authenticate.
        //
        // Appending it to the web group is not enough: Laravel's middleware
        // PRIORITY list hoists Authenticate ahead of any appended group middleware
        // that is not itself in that list. Without this, an unauthenticated visitor
        // to demo1.corexos.co.za is bounced to /login by `auth` before the gate
        // ever runs — and /login on the demo host renders the demo-role sign-in
        // buttons. The gate would be guarding a door the visitor never has to walk
        // through. (Caught by DemoGateTest::test_no_session_cookie_redirects_to_the_gate,
        // which saw a redirect to /login instead of /demo/gate.)
        //
        // It still runs AFTER EncryptCookies + StartSession, which it needs in
        // order to read the signed corex_demo_session cookie.
        //
        // NB the anchor is the CONTRACT (AuthenticatesRequests), not the concrete
        // Authenticate class. Laravel's default priority list names the interface —
        // anchoring on Authenticate::class silently matches nothing and this whole
        // call becomes a no-op, with no error to tell you.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \App\Http\Middleware\EnsureDemoGrant::class,
        );

        $middleware->alias([
            'auth.nocache' => \App\Http\Middleware\PreventAuthPageCaching::class,
            'tv' => \App\Http\Middleware\TvTokenMiddleware::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'branch_manager' => \App\Http\Middleware\BranchManagerMiddleware::class,
            'super_admin' => \App\Http\Middleware\SuperAdminMiddleware::class,
                'admin_or_bm' => \App\Http\Middleware\AdminOrBranchManager::class,
                'auth.portal_capture' => \App\Http\Middleware\AuthenticatePortalCapture::class,
                'auth.wa_capture' => \App\Http\Middleware\AuthenticateWaCapture::class,
                'waha.webhook' => \App\Http\Middleware\VerifyWahaWebhook::class,
                'permission' => \App\Http\Middleware\CheckPermission::class,
                'owner_only' => \App\Http\Middleware\OwnerOnly::class,
                'onboarding.portal' => \App\Http\Middleware\ResolveOnboardingPortal::class,
                'agency.required' => \App\Http\Middleware\RequireAgencyContext::class,
                'branch.required' => \App\Http\Middleware\RequiresBranchAssignment::class,
                'client.ability' => \App\Http\Middleware\EnsureClientAbility::class,
                // Agency Public API (website API)
                'website.live' => \App\Http\Middleware\EnsureAgencyWebsiteLive::class,
                'website.scope' => \App\Http\Middleware\EnsureWebsiteApiScope::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            '/internal/ai-chat-proxy',
            'portal-captures/ingest',
            'communications/wa/ingest',
            'communications/wa/contact-check',
            'communications/wa/ping',
            'communications/wa/webhook', // AT-149 — WAHA server-session webhook (HMAC/secret authed)
            // ── Public, unauthenticated seller-outreach recipient actions (AT-49/AT-50).
            // The per-send 48-char opt_out_token (≈2^286 entropy) IS the credential;
            // these anonymous pages have NO authenticated session to protect, so CSRF
            // adds no security here. It DOES, however, break the confirm POST: a
            // WhatsApp/email in-app webview that drops the session cookie — or the
            // 120-min SESSION_LIFETIME lapsing between page render and submit — raises
            // TokenMismatchException, which the handler below turns into a "session
            // expired — please sign in" redirect to /dashboard. To a public recipient
            // that reads as "this link has expired". Excepting these token-gated,
            // idempotent endpoints makes the links genuinely long-lived / stateless
            // (valid indefinitely, no cookie required) without weakening anything —
            // a forger would already need the victim's unique unguessable token.
            'outreach/opt-out/*',
            'outreach/opt-in/*',
            'unsubscribe/*',
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Agency Admin Rule: convert LastAdminException into a friendly redirect/JSON error.
        $exceptions->render(function (\App\Exceptions\LastAdminException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }
            return back()->with('error', $e->getMessage());
        });

        // 419 session-expired UX: instead of the bare Laravel 419 page, send
        // the user back to /dashboard with a flash message. The auth middleware
        // on /dashboard will bounce them to login if their session is gone —
        // login displays the same flash, then they continue post-sign-in.
        // JSON callers (Alpine fetch, Chrome extension) get a structured 419.
        //
        // IMPORTANT (why this is keyed on HttpException, not TokenMismatchException):
        // Laravel's Handler::prepareException() rewraps TokenMismatchException into
        // a generic Symfony HttpException(419) BEFORE render callbacks run
        // (Foundation\Exceptions\Handler::render → prepareException → renderViaCallbacks).
        // A callback type-hinted on TokenMismatchException therefore NEVER matches,
        // so every CSRF 419 fell straight through to the raw default "419 Page
        // Expired" page (the staging symptom in
        // .ai/audits/2026-07-02-staging-419-csrf-investigation.md). We match the
        // prepared HttpException and act ONLY on status 419 — returning null for
        // any other status so 403/404/500 keep their own handlers/views. The
        // original TokenMismatchException is preserved as $e->getPrevious().
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, $request) {
            if ($e->getStatusCode() !== 419) {
                return null; // not a CSRF/session 419 — let the default handling run
            }
            if ($request->expectsJson()) {
                return response()->json([
                    'ok'    => false,
                    'error' => 'Your session expired — please sign in and continue.',
                ], 419);
            }
            // A token mismatch from a guest — almost always a stale/bfcached
            // login form whose CSRF token no longer matches the session —
            // self-heals by bouncing back to a freshly-rendered login page.
            // Sending a logged-out visitor to /dashboard would only re-bounce
            // them here anyway; this lands them on a live form in one hop with
            // a visible notice (login view renders session('status')).
            if (! $request->user()) {
                return redirect()
                    ->route('login')
                    ->with('status', 'Your session refreshed — please sign in again.');
            }
            return redirect()
                ->route('dashboard')
                ->with('warning', 'Your session expired — please sign in and continue.');
        });

        $exceptions->reportable(function (\Throwable $e) {
            try {
                // Skip exceptions that don't need fault tracking
                if (
                    $e instanceof ValidationException ||
                    $e instanceof AuthenticationException ||
                    $e instanceof \Illuminate\Auth\Access\AuthorizationException ||
                    $e instanceof NotFoundHttpException ||
                    $e instanceof TokenMismatchException
                ) {
                    return;
                }

                // Pre-migration safety: fail silently if table doesn't exist
                if (!\Illuminate\Support\Facades\Schema::hasTable('fault_reports')) {
                    return;
                }

                $exceptionClass = get_class($e);
                $file = $e->getFile();
                $line = $e->getLine();

                // Deduplication: same exception in last 24 hours
                $existing = FaultReport::where('exception_class', $exceptionClass)
                    ->where('file', $file)
                    ->where('line', $line)
                    ->where('last_seen_at', '>=', now()->subDay())
                    ->first();

                if ($existing) {
                    $existing->incrementOccurrence();
                    return;
                }

                $request = request();
                $sensitivePatterns = ['password', 'token', 'secret', 'card', 'cvv'];
                $requestData = null;

                if ($request) {
                    $requestData = collect($request->except(['_token']))->filter(
                        function ($value, $key) use ($sensitivePatterns) {
                            foreach ($sensitivePatterns as $pattern) {
                                if (stripos($key, $pattern) !== false) {
                                    return false;
                                }
                            }
                            return true;
                        }
                    )->toArray() ?: null;
                }

                FaultReport::create([
                    'type' => 'backend',
                    'severity' => 'error',
                    'title' => mb_substr($e->getMessage() ?: $exceptionClass, 0, 500),
                    'message' => mb_substr($e->getMessage() ?: '', 0, 5000) ?: null,
                    'exception_class' => $exceptionClass,
                    'file' => $file,
                    'line' => $line,
                    'trace' => mb_substr($e->getTraceAsString(), 0, 5000),
                    'url' => $request?->fullUrl() ? mb_substr($request->fullUrl(), 0, 1000) : null,
                    'method' => $request?->method(),
                    'user_id' => $request?->user()?->id,
                    'user_agent' => $request?->userAgent(),
                    'ip_address' => $request?->ip(),
                    'request_data' => $requestData,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
            } catch (\Throwable $faultError) {
                // Fault capture must NEVER break the application
            }
        });
    })->create();
