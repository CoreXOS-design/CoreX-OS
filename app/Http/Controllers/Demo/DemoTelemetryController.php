<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDemoGrant;
use App\Jobs\Demo\FlushDemoPageViewJob;
use App\Support\Instance;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives the page-view beacon from a demo page. Runs on the DEMO host.
 *
 * Spec: .ai/specs/demo-access-control.md §6.4
 *
 * ══ THIS FAILS OPEN — every path, without exception ══
 *
 * Returns 204 unconditionally: wrong instance, no cookie, bad payload, primary on
 * fire. It NEVER returns an error, because there is no error a user could act on —
 * by the time this runs, their page has already rendered. The only thing an error
 * response could achieve is a red line in their browser console during a sales
 * demo.
 *
 * The forward to primary happens in a QUEUED JOB, so the browser is never waiting
 * on our network round trip.
 */
class DemoTelemetryController extends Controller
{
    /** POST /demo/telemetry */
    public function store(Request $request): Response
    {
        // Every early return is a 204. See the class docblock.
        if (! Instance::isDemo()) {
            return response()->noContent();
        }

        $token = $request->cookie(EnsureDemoGrant::COOKIE);

        if (! $token || ! is_string($token)) {
            return response()->noContent();
        }

        $path = (string) $request->input('path', '');

        if ($path === '') {
            return response()->noContent();
        }

        // Dispatched to the DEFAULT queue on purpose. The CoreX workers run
        // `queue:work` with no --queue flag, so a job on a named queue would sit
        // in the table forever and the telemetry would silently never arrive.
        FlushDemoPageViewJob::dispatch(
            sessionToken: $token,
            path:         mb_substr($path, 0, 255),
            routeName:    $this->str($request->input('route')),
            title:        $this->str($request->input('title')),
        );

        return response()->noContent();
    }

    private function str($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, 255);
    }
}
