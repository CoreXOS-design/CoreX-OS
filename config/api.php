<?php

/**
 * ══════════════════════════════════════════════════════════════════
 * CoreX OS - API platform limits
 * ══════════════════════════════════════════════════════════════════
 *
 * Rental portal security fixes (audit 2026-10-19, D10). Platform-wide protection limits that no single agency owns. The per-agency
 * limit for the tenant / owner portal is an agency setting instead (rental_portal_settings.portal_api_per_minute).
 */

return [

    // Requests per minute one signed-in STAFF user may make to the staff mobile API (the `staff-api` limiter). Generous: a phone syncing a
    // property or a contact list is chatty. Never lower than 60.
    'staff_per_minute' => (int) env('STAFF_API_PER_MINUTE', 600),
];
