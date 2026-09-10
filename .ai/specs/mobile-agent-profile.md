# Spec — Mobile Agent Profile: editable contact/compliance fields + public-page preview

> Status: Approved (Johan, 2026-09-09 — direct request)
> Module: Mobile app → Profile screen
> Pillars: **Agent** (primary — `User` fields), read-only surface of the agent's own public **Property**-adjacent business-card page

## What this adds

The mobile app's Profile screen gains a view/edit surface for fields that already exist on
`users` but were never exposed to mobile, plus a "Preview my agent page" action:

- **FFC Number** (`users.ffc_number`)
- **Cell** (`users.cell`) — required, matches web
- **WhatsApp** (`users.whatsapp_number`) — deliberately separate from Cell (added
  2026-08-28, no auto-backfill — an agent may WhatsApp on a different number)
- **Facebook Profile URL** (`users.website_social_facebook`)
- **Instagram Profile URL** (`users.website_social_instagram`)
- **Preview my agent page** — opens the agent's existing public profile page
  (`User::publicProfileUrl()`, format `/corex/agents/{name-slug}/{qr_code_slug}`), the same
  page already linked from the web My Portal → Profile tab ("Preview ↗").

No migration: every field already exists (`2026_03_06_000001_add_contact_fields_to_users_table.php`,
`2026_04_21_000001_add_compliance_fields_to_users_table.php`,
`2026_08_28_000001_add_whatsapp_number_to_users.php`,
`2026_06_06_100003_add_agent_public_profile_and_articles.php`) and is already editable on
web (My Portal → Profile, `AgentPortalController::updateProfile`,
`resources/views/agent/portal.blade.php`). This spec is purely a mobile API + parity spec —
edits from either client write the same `users` row, so a change made on the phone is visible
on web on next page load and vice versa (no sync logic needed).

Only these five fields are exposed for mobile *editing* — not `phone`, `fax`, `website`,
`id_number`, `about_me`, `website_social_linkedin`, `website_social_youtube`, all of which also
exist and are web-editable but were not asked for here. Do not add them speculatively.

### Also fixed in this pass: missing `role` on mobile profile identity

Johan flagged (2026-09-09, same request) that the mobile app doesn't show the signed-in user's
role. Root cause: the mobile app's existing identity call, `GET /api/v1/profile`
(`routes/api.php`, name `v1.profile`), never returned a role field at all — it returns
`id/name/email/branch/ffc_status/agency` only. Fixed by adding `role` (raw key, e.g. `"agent"`)
and `role_label` (human label from the `roles.label` column, e.g. `"Agent"`, `"Branch Manager"`)
to that response. This is the one pre-existing endpoint touched outside the new
`mobile/profile` surface — everything else here is additive/new routes.

## Mobile API

New endpoints, mirroring the existing `mobile/*` conventions (plain JSON, no API Resource
classes, `auth:sanctum` + `app_access` route-group middleware, no new permission key — reuses
the same `edit_own_profile` permission the web edit already checks):

| Method | Path | Controller method | Mirrors |
|---|---|---|---|
| GET   | `/api/v1/mobile/profile` | `MobileProfileController::show` | new |
| PATCH | `/api/v1/mobile/profile` | `MobileProfileController::update` | `AgentPortalController::updateProfile` (validation subset) |

Route names: `v1.mobile.profile.show`, `v1.mobile.profile.update` — under the canonical
`v1` + `app_access` group in `routes/api.php`, auto-discovered by `Admin\ApiCatalogController`
(non-negotiable #7, no manual catalog step).

The "preview my agent page" button does **not** get a new endpoint — it reuses the existing
`GET /api/v1/me/agent-qr` (`AgentQrController::mine`, spec `agent-qr-onboarding.md`), whose
`url` field is already `User::publicProfileUrl()`. `MobileProfileController::show` also returns
`public_profile_url` directly (same value) so the Profile screen doesn't need a second round
trip just to render the preview button.

### GET /api/v1/mobile/profile

```json
{
  "id": 5,
  "name": "Andre Agent",
  "email": "andre@example.com",
  "role": "agent",
  "role_label": "Agent",
  "cell": "0821234567",
  "whatsapp_number": "0821234567",
  "ffc_number": "FF123456",
  "website_social_facebook": "https://facebook.com/andre.agent",
  "website_social_instagram": "https://instagram.com/andre.agent",
  "public_profile_url": "https://qatesting2.corexos.co.za/corex/agents/andre/9kkpazukgy",
  "can_edit": true
}
```

`can_edit` mirrors `hasPermission('edit_own_profile')` so the mobile UI can show the fields
read-only (no Save button) for a role that doesn't carry it, without a failed PATCH round trip.

### PATCH /api/v1/mobile/profile

Request body — all keys optional except `cell`, same validation as
`ProfileUpdateRequest` for the fields mobile edits:

```php
'cell'                     => ['required', 'string', 'max:50'],
'whatsapp_number'          => ['nullable', 'string', 'max:50', 'regex:' . User::SA_MOBILE_REGEX],
'ffc_number'               => ['nullable', 'string', 'max:50'],
'website_social_facebook'  => ['nullable', 'string', 'max:255'],
'website_social_instagram' => ['nullable', 'string', 'max:255'],
```

Gated by `abort_unless($user->hasPermission('edit_own_profile'), 403)` — identical check to
`AgentPortalController::updateProfile`. On success, `$user->fill($validated)->save()` (same
model, same table as web) and returns the same shape as `show()`.

422 on validation failure (Laravel's standard `{ message, errors: {field: [msgs]} }` shape).

## Data model

No migrations. Reuses existing `users` columns listed above.

## User flow

```
Mobile: Profile screen
   ├─ GET /api/v1/mobile/profile on screen load / pull-to-refresh
   ├─ Edit FFC Number / Cell / WhatsApp / Facebook URL / Instagram URL
   ├─ Save → PATCH /api/v1/mobile/profile → same row web edits
   ├─ Tap "Preview my agent page" → opens public_profile_url in an in-app browser / external browser
Web: My Portal → Profile tab shows the same fields (unchanged) — a mobile edit is visible there
     on next load, and a web edit is visible on mobile on next pull-to-refresh (same table, no
     merge logic).
```

## Permissions

No new permission key. Reuses `edit_own_profile` (`config/corex-permissions.php:206`), already
granted to agent-tier roles. Route-group gate: `auth:sanctum` + `app_access` (matches every
other `mobile/*` endpoint); the permission check is in-controller (matches the web controller,
which also checks it in-controller rather than route middleware, for the same abort-with-message
behaviour).

## Acceptance criteria

- [ ] `GET /api/v1/mobile/profile` returns all five fields + `role`/`role_label` +
      `public_profile_url` + `can_edit` for the authenticated agent.
- [ ] `PATCH /api/v1/mobile/profile` updates `cell`/`whatsapp_number`/`ffc_number`/
      `website_social_facebook`/`website_social_instagram` on the same `users` row the web
      My Portal → Profile tab edits; a web page load after the PATCH shows the new values.
- [ ] `cell` is required (422 if blank); `whatsapp_number` rejects a non-SA-mobile-shaped value
      via `User::SA_MOBILE_REGEX`, matching web.
- [ ] A user without `edit_own_profile` gets `can_edit: false` on GET and a 403 on PATCH.
- [ ] `GET /api/v1/profile` now includes `role` and `role_label`.
- [ ] Both new routes appear in `/admin/api` (auto-discovery, non-negotiable #7).
- [ ] `php -l` clean on all changed/new PHP files.
- [ ] `php artisan route:list --path=api/v1/mobile/profile` shows both routes.
- [ ] Feature test `tests/Feature/Profile/MobileAgentProfileTest.php` — passes individually.

## Files

### Created
- `app/Http/Controllers/Api/MobileProfileController.php`
- `tests/Feature/Profile/MobileAgentProfileTest.php`
- `.ai/specs/mobile-agent-profile.md` (this file)
- `.ai/specs/mobile-agent-profile-MOBILE-PROMPT.md`

### Modified
- `routes/api.php` — added `v1.mobile.profile.{show,update}`; added `role`/`role_label` to
  the existing `v1.profile` closure
