<?php

namespace App\Http\Middleware;

use App\Models\ContactAccessLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Logs access to individual contact records (view/edit/export).
 * Applied to contact detail routes. Does NOT log list views (bulk).
 */
class LogsContactAccess
{
    public function handle(Request $request, Closure $next, string $actionType = 'view')
    {
        $response = $next($request);

        // Only log successful responses
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $user = Auth::user();
        if (!$user) {
            return $response;
        }

        // Extract contact from route parameter
        $contact = $request->route('contact');
        if (!$contact) {
            return $response;
        }

        $contactId = is_object($contact) ? $contact->id : (int) $contact;

        try {
            ContactAccessLog::create([
                'agency_id' => $user->effectiveAgencyId() ?? ($user->agency_id ?? 1),
                'contact_id' => $contactId,
                'user_id' => $user->id,
                // AT-118 — under switch-user, user_id is the impersonated user; record
                // the real acting admin so the contact-access trail is honest.
                'impersonator_id' => \App\Support\Impersonation::actingAdminId(),
                'action_type' => $actionType,
                'accessed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'request_id' => $request->header('X-Request-Id'),
            ]);
        } catch (\Throwable $e) {
            // Never block the request for audit log failures
            \Illuminate\Support\Facades\Log::warning("Contact access log failed: {$e->getMessage()}");
        }

        return $response;
    }
}
