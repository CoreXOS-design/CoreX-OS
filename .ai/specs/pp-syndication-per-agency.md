# Spec: Private Property Syndication — Per-Agency Credentials

**Status:** Draft — awaiting approval
**Drafted:** 2026-05-28
**Drafted by:** Andre (via Claude)

---

## Overview

Move Private Property (PP) SOAP syndication credentials out of `.env` and into per-agency database columns, mirroring how Property24 credentials already live on the `agencies` table. Expose the fields in the existing **Admin → Agencies → [agency] → Syndication** tab as a new section directly under the Property24 block.

Today every PP call reads `config('services.private_property.*')` which is sourced from a single `.env` block. This means CoreX can only ever syndicate one agency to PP. To run multi-agency on production (HFC1, HFC2, future agencies) each agency needs its own PP login, branch GUID, and webhook secret.

## Why

- **Multi-tenancy non-negotiable (CLAUDE.md #7):** Per-agency creds for an external integration cannot live in a single global `.env`.
- **Parity with P24:** P24 already has per-agency `p24_username`, `p24_password`, `p24_user_group_id`, `p24_enabled` columns and a UI in the same tab. PP is the only major outbound integration still tied to env.
- **Operational:** Admins should be able to update PP creds without an SSH/deploy cycle.

## Pillars

- **Property** — outbound syndication writes Property data to PP. Resolution: `Property → agency_id → Agency` provides the credentials.
- **Agent** — PP `AgentId` registrations already happen per-user. The branch GUID those calls use becomes per-agency.

No new pillar tables. No new pillar models.

## Data Model

### Migration: `add_pp_syndication_columns_to_agencies`

Add to `agencies`:

| Column | Type | Notes |
|--------|------|-------|
| `pp_enabled` | `boolean` default `false` | Master toggle, same shape as `p24_enabled` |
| `pp_username` | `string(191)` nullable | PP SOAP login |
| `pp_password` | `string(255)` nullable | Stored plain (parity with `p24_password` — already plain). Future hardening: encrypted cast. |
| `pp_branch_guid` | `string(64)` nullable | PP-assigned branch identifier |
| `pp_wsdl` | `string(255)` nullable | Override; defaults to sandbox WSDL when blank |
| `pp_sandbox` | `boolean` default `true` | Mirrors `PP_SANDBOX` |
| `pp_image_base_url` | `string(255)` nullable | Override APP_URL for image hosting |
| `pp_webhook_secret` | `string(255)` nullable | HMAC secret registered with PP |
| `pp_last_sync_error` | `text` nullable | Same pattern as `p24_last_sync_error` |

Existing per-agency PP fields stay (none today — this is the first set).

### Config resolver

New: `App\Services\PrivateProperty\PrivatePropertyConfig`

```php
PrivatePropertyConfig::for(Agency $agency): array
PrivatePropertyConfig::forProperty(Property $property): array
PrivatePropertyConfig::forCurrentAgency(): array  // uses auth()->user()->agency
```

Returns the same keys today's `config('services.private_property')` returns: `username`, `password`, `branch_guid`, `wsdl`, `sandbox`, `image_base_url`, `webhook_secret`. Per-agency value wins; falls back to env when the agency column is null. This keeps backward compatibility during rollout.

The `config/services.php` block stays as the default-only source (env). No call site reads it directly after this change — all reads go through `PrivatePropertyConfig`.

### Call sites to refactor

All current `config('services.private_property.*')` reads (20+ sites across `PrivatePropertyTokenService`, `PrivatePropertySoapClient`, `PrivatePropertyListingMapper`, `PrivatePropertySyndicationService`, `AgentPpController`, `PpWebhookController`, `PpManage`, `PpSmokeTest`) become `PrivatePropertyConfig::forProperty($property)['…']` or `::for($agency)['…']`.

`PpWebhookController` is the trickiest — webhook arrives without an agency context. Resolution: PP webhook payload includes `BranchId` (the GUID). We look up the Agency whose `pp_branch_guid` matches; if none, fall back to env (legacy single-tenant). The HMAC secret used for verification then comes from that resolved Agency.

## UI Placement

`resources/views/admin/agencies/create-edit.blade.php` — **Syndication** tab. Add a new section below the existing Property24 block, mirroring its layout exactly:

```
┌─ Property24 — Default Agency ID ────────────┐ (existing)
│  P24 Agency ID, Label                       │
│  ☐ Enable Property24 integration            │
│  Username / Password / User Group ID        │
│  [Test Connection] [Refresh Locations]      │
└─────────────────────────────────────────────┘

┌─ Private Property — Credentials ─────────── ┐ (new)
│  ☐ Enable Private Property integration      │
│  Username / Password / Branch GUID          │
│  WSDL (optional) / ☐ Sandbox mode           │
│  Image Base URL (optional)                  │
│  Webhook Secret                             │
│  [Test Connection]                          │
└─────────────────────────────────────────────┘
```

No new page; no new nav entry required (this tab already exists and is linked in the admin sidebar).

## User Flow

1. Super-admin / agency admin goes to **Admin → Agencies → [agency] → Syndication**.
2. Scrolls past the Property24 section.
3. Fills in PP credentials (or pastes from a vault).
4. Ticks **Enable Private Property integration**.
5. Clicks **Save**.
6. Optionally clicks **Test Connection** — backend calls `PrivatePropertyTokenService` with the just-saved Agency to verify the username/password authenticates and returns a token. JSON response, same UX as P24 test.
7. From here on, every PP outbound call originating from a property belonging to that agency uses these credentials.

Password field follows the same pattern as `p24_password`: blank input keeps existing value; non-blank overwrites.

## Permissions

Reuse existing `manage_agencies` permission (already gates this entire admin page). No new permission keys needed.

## Controller Changes

`App\Http\Controllers\Admin\AgencyController::update` and `::store`:

- Add validation rules for the 8 new fields (mirror P24 rules: all `nullable|string` except booleans).
- Same blank-password-skip logic as `p24_password`.
- Auto-enable PP when both `pp_username` and effective `pp_password` are present (parity with P24 auto-enable).
- New `testPpConnection(Agency)` method, routed as `agencies.pp.test`. Hits `PrivatePropertyTokenService::tokenFor($agency)`; returns JSON `{success, message}`.

`Agency` model: add the 8 columns to `$fillable` and cast `pp_enabled` / `pp_sandbox` to `bool`.

## Acceptance Criteria

1. **Schema:** Migration adds all 8 columns; `Agency::factory()` creates an instance with them null/default; `php artisan migrate:fresh` succeeds.
2. **UI:** Syndication tab shows the new section directly under P24. All fields render with correct existing values on edit. Empty password field doesn't wipe the stored value on save.
3. **Save round-trip:** Save the form with PP creds → reload page → values appear in inputs (password masked).
4. **Test Connection button:** With valid sandbox creds, returns success JSON. With bad creds, returns failure JSON with the SOAP fault message.
5. **Resolver:** `PrivatePropertyConfig::for($agency)['username']` returns the DB value when set, env value when null.
6. **Refactor:** No remaining `config('services.private_property.*')` call in `app/` outside `PrivatePropertyConfig` itself. `grep -r "services.private_property" app/` returns only that one file.
7. **Webhook:** PP webhook with `BranchId` matching `agencies.pp_branch_guid` verifies HMAC against that agency's `pp_webhook_secret`. Webhook with unknown branch GUID falls back to env secret (legacy path), logged.
8. **Backward compat:** Existing single-tenant production (HFC2) keeps working with no DB values set — env fallback handles it. Deploy can be staged: migration first, UI second, env values copied per agency third, env removed last (separate prompt, separate PR).
9. **Tests:** `scripts/dev-check.ps1` passes with 0 new failures. New unit test `PrivatePropertyConfigTest` covers the agency-vs-env resolution.
10. **Lint:** `php -l` clean on every changed file.

## Files to Create / Modify

### Create
- `database/migrations/2026_05_28_120000_add_pp_syndication_columns_to_agencies.php`
- `app/Services/PrivateProperty/PrivatePropertyConfig.php`
- `tests/Unit/PrivatePropertyConfigTest.php`

### Modify
- `app/Models/Agency.php` — fillable + casts
- `app/Http/Controllers/Admin/AgencyController.php` — validation, save, `testPpConnection`
- `routes/web.php` — add `agencies.pp.test` POST route
- `resources/views/admin/agencies/create-edit.blade.php` — new PP section in syndication tab
- 20+ call sites listed above — swap `config('services.private_property.*')` → `PrivatePropertyConfig::…`
- `app/Http/Controllers/PrivateProperty/PpWebhookController.php` — resolve agency from `BranchId`

### Untouched
- `config/services.php` — env-backed defaults stay as fallback source
- `.env.example` — keep PP_* keys documented as legacy/global defaults

## Rollout Order (one PR)

1. Migration.
2. Resolver + tests.
3. Refactor call sites.
4. Model + controller + route.
5. View.
6. Webhook agency resolution.
7. `dev-check.ps1` green.

## Open Questions

- Should `pp_password` be encrypted at rest now (vs `p24_password` which is plain)? Default to **no**, match existing pattern; flag for a future security pass that hits both.
- Do we need a per-branch override (like P24's `branches.p24_agency_id`)? **No** — PP branch GUID is one per agency in PP's data model, not per CoreX branch.
