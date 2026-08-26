<?php

namespace App\Http\Middleware;

use App\Models\SiteConnector;
use App\Support\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the CoreX marketing website against CoreX OS.
 *
 * Spec: .ai/specs/webinar-registration.md §3.3, §4
 *
 * Not the agency-api guard: that resolves an AGENCY from the key and hands it to
 * AgencyScope as the tenant. Webinar registrations are RR Technologies' sales data,
 * not an agency's — so there is no tenant to resolve, and a per-agency key would be a
 * one-to-many answer to a one-to-one question.
 *
 * Not the demo connector either — see the SiteConnector docblock. Different audience,
 * different credential.
 *
 * PRIMARY ONLY. Registrations and the demo grants they issue are durable records, and
 * the demo host's database is destroyed every three days (demo-access-control.md §3).
 * A demo instance answering these routes would be writing a prospect's sales record —
 * and their access credential — into a database that deletes itself.
 */
class EnsureSiteConnector
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Instance::isPrimary()) {
            return response()->json([
                'ok'      => false,
                'message' => 'The webinar API is served only by the primary instance.',
            ], 404);
        }

        $connector = SiteConnector::resolve($request->bearerToken());

        // One message for every failure mode — malformed, unknown, revoked, wrong
        // secret. A 401 that says which part was wrong is an oracle.
        if (! $connector) {
            return response()->json([
                'ok'      => false,
                'message' => 'Invalid or revoked site connector token.',
            ], 401);
        }

        $connector->markUsed();

        $request->attributes->set('site_connector', $connector);

        return $next($request);
    }
}
