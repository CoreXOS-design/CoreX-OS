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

        // AT-253 (STANDARDS Rule 17) — DERIVE the agency from the CONTACT being accessed,
        // not from the acting user. This log is about a contact, and the contact knows which
        // tenant it belongs to; the viewer may be an owner/super-admin who belongs to none.
        // The old `?? 1` filed every super-admin's contact view into AGENCY 1's POPIA access
        // trail — an audit record attributing access to the wrong tenant, which is precisely
        // the record you cannot afford to have wrong.
        $agencyId = is_object($contact)
            ? ($contact->agency_id ?? null)
            : \App\Models\Contact::withoutGlobalScopes()->whereKey($contactId)->value('agency_id');

        // agency_id is NOT NULL here. With no contact agency to derive from there is nothing
        // honest to write, so we skip the row rather than invent a tenant — and say so in the
        // log, because a missing audit entry must never be silent.
        if (! $agencyId) {
            \Log::warning('AT-253 contact-access log skipped: no agency context', [
                'contact_id' => $contactId, 'user_id' => $user->id,
            ]);

            return $response;
        }

        try {
            ContactAccessLog::create([
                'agency_id' => $agencyId,
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
