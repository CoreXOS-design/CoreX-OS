<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mobile parity: lets a mobile route point STRAIGHT at a web controller action that answers a redirect.
 *
 * The web action keeps doing everything it does on the web - route-model binding, form-request validation, the permission and own / branch /
 * agency guards, the model calls - so the phone cannot drift from the rule. This middleware only changes how the outcome is delivered:
 *   - the request is marked "wants JSON", so a validation error is a 422 {message, errors} and an abort is a JSON {message} with its status;
 *   - a redirect that carries an error (withErrors / ->with('error')) becomes 409 {ok: false, message, errors, code: refused};
 *   - any other redirect becomes 200 {ok: true, message, warning, redirect}, where message is what the web flashed ("Lease restored.") and
 *     redirect is the web path it would have opened (how the app learns the id of a record it just created).
 * A web action that already answers JSON (or a file) passes through untouched. Spec: .ai/specs/at-508-mobile-parity-addendum.md §0.
 */
class MobileWebAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $this->clearFlash();

        $response = $next($request);

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        $errors = session('errors');
        $flashedError = session('error');
        $message = session('success') ?? session('status');
        $warning = session('warning');
        $link = session('reissued_link'); // re-issue: {url, code, expires} - shown once on the web, so it must travel in the answer
        $target = parse_url($response->getTargetUrl(), PHP_URL_PATH) ?: null;
        $this->clearFlash();

        if ($errors instanceof ViewErrorBag && $errors->any()) {
            $bag = $errors->getBag('default');

            return new JsonResponse(['ok' => false, 'message' => (string) ($bag->all()[0] ?? 'Refused.'), 'errors' => $bag->toArray(), 'code' => 'refused'], 409);
        }
        if (is_string($flashedError) && $flashedError !== '') {
            return new JsonResponse(['ok' => false, 'message' => $flashedError, 'code' => 'refused'], 409);
        }

        // `redirect` is the web path the action would have opened (e.g. /corex/rental-applications/57): how the app learns the id of what it just created
        return new JsonResponse(['ok' => true, 'message' => $message, 'warning' => $warning, 'redirect' => $target, 'link' => $link]);
    }

    private function clearFlash(): void
    {
        try {
            session()->forget(['success', 'status', 'warning', 'error', 'errors']);
        } catch (\Throwable) {
            // no session store for this request: nothing was flashed
        }
    }
}
