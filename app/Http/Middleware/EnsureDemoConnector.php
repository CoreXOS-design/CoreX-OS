<?php

namespace App\Http\Middleware;

use App\Models\DemoConnector;
use App\Support\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the demo instance against PRIMARY, using the universal connector.
 *
 * Spec: .ai/specs/demo-access-control.md §5.1
 *
 * Replaces the agency-api guard on /api/v1/demo-access/*. That guard resolves an
 * AGENCY from the key and hands it to AgencyScope as the tenant — correct for an
 * agency's public website, wrong here: demo access grants are not tenant data, and
 * pinning them to an arbitrary agency would be a lie in the data model and a
 * "demo:*" scope sitting one mis-click away in the agency key UI.
 *
 * This authenticates the DEMO INSTANCE ITSELF. There is exactly one, so there is
 * exactly one credential.
 *
 * Only PRIMARY serves these routes: a demo host answering them would be writing
 * grants into a database that is dropped every 3 days.
 */
class EnsureDemoConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        // The durable records live on primary. If a demo host is somehow serving
        // this, refuse rather than write evidence into a disposable database.
        if (! Instance::isPrimary()) {
            return response()->json([
                'ok'      => false,
                'message' => 'The demo control API is served only by the primary instance.',
            ], 404);
        }

        $connector = DemoConnector::resolve($request->bearerToken());

        // One message for every failure mode — malformed, unknown, revoked, wrong
        // secret. A 401 that says which part was wrong is an oracle.
        if (! $connector) {
            return response()->json([
                'ok'      => false,
                'message' => 'Invalid or revoked demo connector token.',
            ], 401);
        }

        $connector->markUsed();

        $request->attributes->set('demo_connector', $connector);

        return $next($request);
    }
}
