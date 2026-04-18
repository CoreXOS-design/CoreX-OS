# Mobile Claude Prompt — Rebuild the Property Upload Wizard on Mobile

> **Use this prompt verbatim in the CoreX OS mobile app's Claude Code session.**
> Paste everything below the horizontal rule into the mobile repo's Claude session.

---

You are working on the **CoreX OS mobile app** (iOS/Android). The web team just shipped a new **Property Upload Wizard** on the Laravel server at `corex.hfcoastal.co.za` (dev server: `91.99.130.85:8084`). Your job is to mirror that wizard inside the mobile app so agents can list properties from their phone in the field. The server-side API is ready and live — do not rebuild it. You wire the mobile UI to it.

## Context — who uses this

A real estate agent standing outside a property with a seller. One hand holds the phone. They open the CoreX app, tap **New Property**, and need to finish in under 3 minutes. They are not tech-savvy. They use WhatsApp and Facebook. They do not read manuals.

## Session-start protocol (mobile app)

1. Read `/CLAUDE.md` in the mobile repo (if it exists).
2. Read any spec in `/specs/` that covers property creation on mobile. If none exists, create one at `/specs/property-upload-wizard-mobile.md` before touching code, following the same format as the web spec referenced below.
3. Confirm the app's HTTP client, auth token storage, navigation framework (React Navigation / Expo Router / SwiftUI), and state management library before writing any screens.

## The four server endpoints you must call

Auth: Bearer token from your existing login flow. Send `Accept: application/json` and `X-Requested-With: XMLHttpRequest` on every request so the server returns JSON (the wizard controller honours `wantsJson()`).

Base URL: whatever the app's configured CoreX server is (dev/staging/prod).

| Purpose | Method | Path | Request | Response |
|---|---|---|---|---|
| **Step 1 — create draft** | `POST` | `/corex/properties/wizard/draft` | `multipart/form-data` with: `listing_type` (sale\|rental), `property_type`, `suburb`, `street_number?`, `street_name?`, `price` (int, ZAR), `beds` (int), `baths` (int), `garages` (int), `title` | `{ ok: true, property: { id, title }, next: 'photos' }` |
| **Step 2 — upload photos** | `POST` | `/corex/properties/wizard/{property}/photos` | `multipart/form-data` with `gallery_images[]` (JPG/PNG, ≤ 5 MB each, up to 30 per batch) | `{ ok: true, uploaded: N, urls: [...], total: N }` |
| **Step 2 — reorder photos** | `POST` | `/corex/properties/wizard/{property}/photos/reorder` | `order[]=0&order[]=2&order[]=1` (indexes into current list) | `{ ok: true }` |
| **Step 2 — remove a photo** | `POST` | `/corex/properties/wizard/{property}/photos/remove` | `index=2` | `{ ok: true }` |
| **Step 3 — save details** | `POST` | `/corex/properties/wizard/{property}/step` | `multipart/form-data` with any of: `description`, `excerpt`, `mandate_type`, `branch_id`, `agent_id`, `size_m2`, `erf_size_m2`, `commission_percent`, `admin_fee`, `rental_amount`, `deposit_amount`, `lease_start_date` (YYYY-MM-DD), `lease_end_date`, `features[]` | `{ ok: true, next: 'review' }` |
| **Step 4 — finalize** | `POST` | `/corex/properties/wizard/{property}/finalize` | `publish=1` (publish live) or `publish=0` (save as draft) | 302 redirect on web; for mobile, treat HTTP 200 or 302 as success and navigate to the property show screen. |
| **Discard draft** | `DELETE` | `/corex/properties/wizard/{property}` | none | 302 redirect / 200 |

All endpoints require the user to have the `access_properties` permission and ownership of the draft (enforced by the scope check on the server).

### What the server handles automatically — do not re-implement

- **Agency isolation** (`agency_id` injected by `BelongsToAgency` trait).
- **Smart defaults** — `agent_id` = current user, `branch_id` = user's branch, `province` = KwaZulu-Natal, `status='draft'`.
- **P24 and Private Property syndication** — when `publish=1` is sent to `finalize`, the server's `PropertyObserver::saved()` detects the `published_at` transition and dispatches `SyncPropertyToWebsite`, which handles both P24 and PP sync. **The mobile client must NEVER touch any `pp_*` or `p24_*` field directly.**
- **Image storage paths and URL generation**.
- **Soft deletes** — discard draft uses the server's `SoftDeletes` pipe.

## Mobile UX — mirror the web wizard's behaviour

Build a 4-step wizard screen. The spec on the server is at `.ai/specs/property-upload-wizard.md`. Read it. Mobile deviates only where a phone genuinely needs a different pattern.

### Step 1 — Basics

- **Listing type**: two large tappable tiles (For Sale / For Rental). Default Sale.
- **Headline**: single-line text input, 200-char counter.
- **Property type**: bottom-sheet picker pulled from `GET /corex/api/settings/property-types` (or cached enum — see "Settings & lookups" below).
- **Price**: numeric keyboard; format as "R 1 200 000" in a helper label below the field. Label reads "Monthly rental (R)" when listing type is rental.
- **Address**: street number + street name (optional), then **Suburb** with autocomplete. On mobile, suburb autocomplete should use the cached KZN suburb list first (see below), falling back to a free text input.
- **Beds / Baths / Garages**: stepper controls (+/−), not number pads. Default 0, max 20.
- Continue button is **disabled** until: listing type, headline, property type, suburb, and price are filled.

### Step 2 — Photos

Critical for mobile:

- **Camera access** — native "Take photo" button in addition to "Choose from library" (use expo-image-picker or RN equivalent). Agents often photograph the property in place.
- **Batch pick** — library picker allows multi-select up to 30 images.
- **Resize before upload** — scale down any image over 5 MB client-side (long edge ≤ 2400 px, JPEG quality 80) so mobile data is not wasted.
- **Upload progress** — one POST per batch. Show progress bar with per-image status if possible.
- **Offline queue** — if the request fails due to connectivity, stash the photos in a local queue keyed by `property.id` and retry when network returns. Show a "will retry when online" indicator.
- **Reorder** — long-press + drag. First photo is the cover.
- **Skip allowed** — explicit skip button; the draft stays valid.

### Step 3 — Details

- Description: multiline input.
- Mandate, branch, agent: pickers. Agent picker is hidden for scope `own` users (same rule as web — the server enforces this anyway).
- Sizes / commission / admin fee: numeric steppers or number keyboards.
- Rental-only fields appear only when step-1 listing type is rental (deposit, lease dates).

### Step 4 — Review

- Show the same readiness checklist: Headline, Price, Property type, Suburb, Photos (≥ 1), Description (≥ 30 chars).
- Tapping a checklist item jumps back to that step.
- Render a live "listing preview card" that looks like a portal listing (cover photo, price, address, bed/bath icons, description snippet).
- Two buttons at the bottom: **Save as draft** (always enabled) and **Save & publish** (enabled only when all checklist items are green).

## Settings & lookups

The server exposes property types and mandate types as settings rows. If the mobile app already has a lookup cache, reuse it. Otherwise, hit these read-only endpoints on first load and cache them for 24 hours:

- `GET /corex/api/settings/property-types` → `[{ name: 'house', label: 'House' }, ...]`
- `GET /corex/api/settings/mandate-types` → `[{ name: 'Sole', label: 'Sole Mandate' }, ...]`
- `GET /corex/api/branches` (for the branch picker)
- `GET /corex/api/agents?scope=branch` (for agent picker, admin/BM only)

If any of these endpoints don't exist yet, file a follow-up ticket rather than hardcoding the values — **no hardcoding** is a CoreX non-negotiable.

## Offline & resume behaviour

- After step 1 succeeds and the server returns a property `id`, cache it locally (AsyncStorage / UserDefaults) as the "active draft".
- If the app is killed and reopened, show a banner on the wizard entry screen: "You have a draft: {title} — Continue or Start fresh".
- Network failures on steps 2/3 should be retryable. Do not lose the user's input on a transient failure.

## Error handling

- **422 Unprocessable Entity** → show the field-level errors inline (response JSON has `errors`).
- **403 Forbidden** → show "You don't have permission to create listings" and link to the profile screen.
- **5xx** → "Something went wrong on our side. Try again in a moment." Offer a retry button.
- **Network offline** → queue the request locally (step 2 especially), show "Will upload when online".

## What NOT to do

- Do NOT touch `pp_syndication_*`, `p24_syndication_*`, `pp_ref`, or `p24_ref` fields from the client. Server handles all of this.
- Do NOT call `/corex/properties` (the classic create endpoint) — use the wizard endpoints only.
- Do NOT assume field names or types — each wizard endpoint's validation is authoritative; handle 422s gracefully.
- Do NOT skip the session-start protocol on the mobile repo.
- Do NOT build a progress screen that shows server-side stuff like "Syncing to P24" — syncing is async and invisible to the user. The success criteria is "property is saved", full stop.

## Acceptance criteria

- [ ] A fresh install can complete the full 4-step wizard on a cellular connection and land on the property show screen.
- [ ] A property published via the mobile wizard appears on the website and in P24 / PP (verified by viewing the property's show screen on web, checking the syndication panel).
- [ ] A draft started on web and a draft started on mobile are both visible and resumable from either surface.
- [ ] Photo uploads survive loss of network (queued + retried).
- [ ] `Save as draft` keeps the property in the list with a Draft ribbon; `Save & publish` moves it to Active.
- [ ] Discarding a draft soft-deletes on the server (no hard delete).
- [ ] App does not crash on a property where some fields are null (draft state).

## Definition of done

Same as web: feature works end-to-end against the staging server, doesn't break any existing property screens, and the PR description includes a short video of a fresh listing being created and published from the phone.
