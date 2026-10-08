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
 * §22 — the header carries the agency's logo (name when it has none) on every page. Before sign-in the page only knows
 * the agency when the personal link carried the person's email (`/portal?email=…`, §16): that email is looked up the
 * same way the Continue button's lookup does, and the branding is returned only when it points at exactly ONE agency.
 * Any other first visit shows the neutral "My Rentals" header until the person signs in (the page then asks for its own
 * agency's branding).
 */
class RentalPortalShellController extends Controller
{
    public function show(Request $request): View
    {
        $email = $this->linkedEmail($request);

        return view('rentals.portal.shell', ['branding' => $this->brandingFor($email), 'linkedEmail' => $email]);
    }

    /**
     * WHO the link is for. A mail link carries a SIGNED recipient reference (`r`, App\Support\PortalLink); the lease screen's copy
     * link carries `?email=`. Either way this is the address the sign-in is pre-filled with and the "this link is for somebody
     * else" card names. A tampered or unreadable `r` is ignored (null), never trusted.
     */
    private function linkedEmail(Request $request): ?string
    {
        $recipient = \App\Support\PortalLink::parse($request->query('r'));
        if ($recipient) {
            return $recipient['email'];
        }
        $email = $request->query('email');
        if (is_string($email) && strlen($email) <= 255 && preg_match('/^[^\s@]+@[^\s@]+$/', trim($email))) {
            return strtolower(trim($email));
        }

        return null;
    }

    /** @return array{name:string,logo_url:?string}|null */
    private function brandingFor(?string $email): ?array
    {
        if (! $email) {
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

        // The header shows the logo at 200 x 38 CSS px: hand it the small copy, never a multi-megapixel original (App\Support\PortalLogo).
        return ['name' => $b['name'], 'logo_url' => $b['logoUrl'] ? (\App\Support\PortalLogo::urlFor((int) $agencies->first()) ?? $b['logoUrl']) : null];
    }
}
