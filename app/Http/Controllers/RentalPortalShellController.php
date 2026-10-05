<?php

namespace App\Http\Controllers;

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
 */
class RentalPortalShellController extends Controller
{
    public function show(): View
    {
        return view('rentals.portal.shell');
    }
}
