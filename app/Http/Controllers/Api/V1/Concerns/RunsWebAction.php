<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Mobile parity: run one of the WEB controller's actions for the phone and answer its outcome as JSON.
 *
 * A web action that changes something answers a redirect with a success / error message (or a validation error) flashed to the session. The
 * phone has no page to land on, so this runs the very same action, with the very same validation, permission checks and model calls, and
 * turns what it said into a status code and a plain message. No rule is copied: if the web changes its rule, the phone changes with it.
 *
 *   validation error            -> 422 {message, errors, code: invalid}
 *   abort(403|404|409|422, ...) -> the same status, {message, code}
 *   flashed error / error bag   -> 409 {message, errors, code: refused}
 *   anything else               -> 200 {ok: true, message, warning}
 */
trait RunsWebAction
{
    /**
     * @param class-string|object $controller
     * @param array<string, mixed> $params named arguments for the action ("lease" => $lease, "id" => 5 ...); "request" is added
     */
    protected function runWebAction(Request $request, string|object $controller, string $method, array $params = []): JsonResponse
    {
        $this->clearWebFlash();

        try {
            $result = app()->call([is_object($controller) ? $controller : app($controller), $method], $params + ['request' => $request]);
        } catch (ValidationException $e) {
            return response()->json(['message' => (string) $e->validator->errors()->first(), 'errors' => $e->errors(), 'code' => 'invalid'], 422);
        } catch (HttpResponseException $e) {
            return $e->getResponse() instanceof JsonResponse ? $e->getResponse() : response()->json(['message' => 'Refused.', 'code' => 'refused'], $e->getResponse()->getStatusCode());
        } catch (HttpExceptionInterface $e) {
            $status = $e->getStatusCode();

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : ($status === 403 ? 'You are not allowed to do that.' : 'Not found.'),
                'code' => match ($status) { 403 => 'forbidden', 404 => 'not_found', 410 => 'archived', default => 'refused' },
            ], $status);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'refused'], 409);
        }

        if (! $result instanceof RedirectResponse) {
            // a web action that renders a page: the using controller supplies the page's data as JSON (answerView), else just {ok:true}
            if ($result instanceof \Illuminate\View\View && method_exists($this, 'answerView')) {
                return $this->answerView($result);
            }

            return $result instanceof JsonResponse ? $result : response()->json(['ok' => true]);
        }

        $errors = session('errors');
        $flashedError = session('error');
        $body = ['ok' => true, 'message' => session('success') ?? session('status'), 'warning' => session('warning')];
        $this->clearWebFlash();

        if ($errors instanceof ViewErrorBag && $errors->any()) {
            $all = $errors->getBag('default')->all();

            return response()->json(['ok' => false, 'message' => (string) ($all[0] ?? 'Refused.'), 'errors' => $errors->getBag('default')->toArray(), 'code' => 'refused'], 409);
        }
        if (is_string($flashedError) && $flashedError !== '') {
            return response()->json(['ok' => false, 'message' => $flashedError, 'code' => 'refused'], 409);
        }

        return response()->json($body);
    }

    private function clearWebFlash(): void
    {
        try {
            session()->forget(['success', 'status', 'warning', 'error', 'errors']);
        } catch (\Throwable) {
            // no session store configured for this request: nothing was flashed
        }
    }
}
