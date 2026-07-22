<?php

namespace App\Http\Middleware;

use App\Models\AssistantActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-267 — record what an assistant does.
 *
 * Runs on every web request but does NOTHING unless the signed-in user is an
 * assistant (a single bool check for everyone else). For an assistant, when the
 * request successfully touches a record-scoped route (one bound to a property,
 * contact or deal), it appends one row to `assistant_activity_log`:
 *   GET   → opened     PUT/PATCH → edited     DELETE → deleted
 *   POST  → edited  (only when the route name ends in `.update`, so the many
 *                    small sub-action POSTs — increment, remark, … — stay out)
 *
 * The agent reads this back on My Assistants → Activity to see, plainly, which
 * of their records the assistant has been opening and changing. Writes are
 * wrapped so a logging failure can never break the page the assistant is on.
 */
class LogAssistantActivity
{
    /** Route parameter name → the short subject slug we store. Priority order. */
    private const SUBJECTS = [
        'property' => 'property',
        'contact'  => 'contact',
        'deal'     => 'deal',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            // Never let an audit write break the request the assistant is making.
            Log::warning('LogAssistantActivity failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $user = $request->user();
        if (! $user || ! $user->is_assistant) {
            return;
        }

        // Only successful responses count as "they did it".
        if ($response->getStatusCode() >= 400) {
            return;
        }

        $route = $request->route();
        if (! $route) {
            return;
        }

        $action = $this->actionFor($request);
        if ($action === null) {
            return;
        }

        // Find the first bound record among the tracked route params.
        $subjectSlug = null;
        $subject     = null;
        foreach (self::SUBJECTS as $param => $slug) {
            if ($route->hasParameter($param)) {
                $subjectSlug = $slug;
                $subject     = $route->parameter($param);
                break;
            }
        }
        if ($subjectSlug === null || $subject === null) {
            return; // list pages, dashboards, etc. — nothing record-scoped to log
        }

        $assignment = $user->activeAssistantAssignment();
        if (! $assignment) {
            return;
        }

        [$subjectId, $subjectLabel] = $this->identify($subject);

        AssistantActivityLog::create([
            'agency_id'               => $assignment->agency_id,
            'assistant_assignment_id' => $assignment->id,
            'assistant_user_id'       => $user->id,
            'agent_user_id'           => $assignment->agent_user_id,
            'action'                  => $action,
            'subject_type'            => $subjectSlug,
            'subject_id'              => $subjectId,
            'subject_label'           => $subjectLabel,
            'route_name'              => $route->getName(),
            'url'                     => mb_substr($request->fullUrl(), 0, 300),
            'method'                  => $request->method(),
            'created_at'              => now(),
        ]);
    }

    /** opened / edited / deleted — or null when this verb/route is not worth logging. */
    private function actionFor(Request $request): ?string
    {
        return match ($request->method()) {
            'GET', 'HEAD'    => 'opened',
            'PUT', 'PATCH'   => 'edited',
            'DELETE'         => 'deleted',
            'POST'           => str_ends_with((string) optional($request->route())->getName(), '.update') ? 'edited' : null,
            default          => null,
        };
    }

    /**
     * Resolve a subject's id + a human label from a route-bound model (or a raw
     * id when binding didn't hydrate a model).
     *
     * @return array{0:?int,1:?string}
     */
    private function identify(mixed $subject): array
    {
        if (! is_object($subject)) {
            return [is_numeric($subject) ? (int) $subject : null, null];
        }

        $id = method_exists($subject, 'getKey') ? (int) $subject->getKey() : null;

        // Try the common human-facing attributes without assuming a model type.
        $label = $subject->full_name
            ?? $subject->title
            ?? $subject->address
            ?? $subject->name
            ?? $subject->reference
            ?? null;

        if (is_string($label) && trim($label) !== '') {
            return [$id, mb_substr(trim($label), 0, 190)];
        }

        return [$id, null];
    }
}
