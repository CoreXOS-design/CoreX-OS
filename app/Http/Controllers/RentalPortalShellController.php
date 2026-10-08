<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-portal-access.md §9 — AT-445. One Blade shell for the
 * whole tenant/landlord web portal (login, lease, documents, faults,
 * landlord properties/decisions) — a single route, client-side "router"
 * (Alpine watches the URL path) rather than a server route per screen.
 * Mirrors the "thin web client of the existing /api/v1/client/*
 * endpoints" design from this ticket's Step 0 report: no second backend,
 * just the first Blade consumer of the same API the mobile app also
 * reaches (by bearer token instead of session cookie).
 *
 * §21 — the header carries the agency's logo (name when it has none) on every page. Before sign-in the page only knows
 * the agency when the personal link carried the person's email (`/portal?email=…`, §16): that email is looked up the
 * same way the Continue button's lookup does, and the branding is returned only when it points at exactly ONE agency.
 * Any other first visit shows the neutral "My Rentals" header until the person signs in (the page then asks for its own
 * agency's branding).
 */
class RentalPortalShellController extends Controller
{
    public function show(Request $request): View
    {
        return view('rentals.portal.shell', ['branding' => $this->brandingFromLink($request)]);
    }

    /** @return array{name:string,logo_url:?string}|null */
    private function brandingFromLink(Request $request): ?array
    {
        $email = $request->query('email');
        if (!is_string($email) || strlen($email) > 255 || !preg_match('/^[^\s@]+@[^\s@]+$/', trim($email))) {
            return null;
        }

        $agencies = Contact::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereNotNull('client_user_id')
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->pluck('agency_id')->filter()->unique()->values();

        if ($agencies->count() !== 1) {
            return null;
        }

        $b = Agency::publicBrandingFor((int) $agencies->first());

        return ['name' => $b['name'], 'logo_url' => $b['logoUrl']];
    }
}
