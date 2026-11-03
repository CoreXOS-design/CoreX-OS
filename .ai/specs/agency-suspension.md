# Agency Suspension (Dev → Agency Management)

> Status: approved by Johan (verbal, 2026-11-01). Owner-only platform action.

## What / why
A System Owner can **suspend** an agency (e.g. non-payment, contract pause). While suspended, nobody
belonging to that agency can sign in or keep working. Fully reversible; no data is touched.
Distinct from **Disable** (`is_active`, setup/deactivated) and **Maintenance** (temporary splash).

## Behaviour
- Agency Management (`/settings/agencies`) gets a **Suspend / Lift suspension** button + a red **Suspended** badge.
- Sign-in by a user of a suspended agency is refused on the login form with:
  "Your agency has been suspended. Please contact {support email} to find out more."
- A user already signed in is signed out on their next request and sent to the login page with the same message
  (JSON/API requests get a 403 with the same message).
- System Owners (no agency) are never blocked and can always lift it. Other agencies are unaffected.
- Support email is platform config (`integrations.support_email`, env `COREX_SUPPORT_EMAIL`, default info@corexweb.co.za) — not per-agency, not hardcoded in views.

## Data
`agencies.suspended_at` (nullable timestamp). Null = not suspended.

## Pillars / scoping
Platform-level (Agency model); owner_only route; no agency-scoped data involved. Not an agency setting, so not in the Setup Wizard.

## Files
migration `add_suspended_at_to_agencies`; `Agency` (suspend/unsuspend/isSuspended); `AuthenticatedSessionController::store`; `Middleware/EnforceAgencySuspension` (web group in bootstrap/app.php);
`Admin/AgencyController::toggleSuspend`; route `agencies.toggle-suspend`;
`admin/agencies/index.blade.php`; `config/integrations.php`; `tests/Feature/AgencySuspensionTest.php`.

## Acceptance
- [ ] Suspended agency user cannot sign in; sees message with the support email
- [ ] Signed-in user of that agency is bounced on next request
- [ ] Owner unaffected; other agency unaffected
- [ ] Lifting restores access; toggle is owner-only
