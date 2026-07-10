# Spec: Property Upload Wizard + Properties Index Redesign

**Status:** Draft — Awaiting Johan's review
**Date:** 2026-04-18
**Author:** Andre
**Supersedes/complements:** `property-page-redesign.md` (Part B, Phase 2 — quick-create)

---

## Problem

The current property create flow is a single 500-line form with 20+ fields on one page. A non-tech-savvy agent sitting at a kitchen table with a seller sees a wall of inputs, no guidance, no progress indicator, and only finds out about missing fields on submit. Photos cannot be organised during creation — that requires a second trip to the Gallery tab after save. The properties index page looks like a generic SaaS admin panel.

**Target user:** a Home Finders agent in Uvongo who uses WhatsApp and Facebook daily but does not think of themselves as "tech-savvy". They need to list a property in under 3 minutes, on a phone or laptop, without reading a manual.

---

## What This Spec Does

1. **Replaces the single-page create form** with a 4-step guided wizard.
2. **Adds a fresher look** to the properties index — scannable cards, visible status ribbons, smart filter chips, loud pricing.
3. **Does NOT touch** P24 syndication, PP syndication, the show page, the edit flow, or any existing data model.

---

## Pillar Connections

| Pillar | Reads | Writes |
|--------|-------|--------|
| Property | — | creates new `properties` row |
| Agent | `users` for default agent | sets `agent_id` |
| Contact | `contacts` (optional link at step 4) | writes to `contact_property` pivot |
| Deal | — | — (future: trigger mandate flow from wizard) |

---

## Non-Negotiables Preserved

- **No hard deletes** — wizard only writes, nothing destructive.
- **Multi-tenancy** — `agency_id` set by `BelongsToAgency` on `Property::create()`. No manual overrides.
- **P24/PP syndication** — wizard calls the existing `PropertyController@store` which uses `Property::create()` → `PropertyObserver::saved()` → `SyncPropertyToWebsite::dispatchSync()`. **Zero change to syndication pipeline.**
- **Permissions** — reuses existing `access_properties`, `properties.create`. No new permission keys.
- **Settings-first** — property types, mandate types, categories, statuses pulled from `PropertySettingItem` (never hardcoded).
- **Soft deletes** — N/A (create-only flow).

---

## Part A: Upload Wizard

### A1. Flow Overview

```
Step 1: Basics                   Step 2: Photos                  Step 3: Details                 Step 4: Review
─────────────                   ───────────────                 ─────────────────              ─────────────────
· Listing type (Sale/Rental)    · Drag-drop photo zone          · Full description              · Summary of all fields
· Address (suburb + street)     · Preview grid                  · Mandate type                  · Readiness checklist
· Price                         · Reorder by drag               · Branch                         · "Save as draft" OR
· Property type                 · Set cover (star)              · Agent (defaults to self)       · "Save & Publish"
· Beds / Baths / Garages        · Skip allowed                  · Floor / Erf m²                · Warning if publishing
· Short summary                                                 · Commission, fees               incomplete
                                                                · Features (optional)
```

- 4 steps, progress bar at top, "Back" and "Continue" at bottom.
- **Autosave** on each "Continue" click — saves a draft `Property` row with `status='draft'` after step 1.
- **Resume** — drafts are resumed only via the listing's "Drafts" button (`?resume=<id>`, AT-188); a plain "New Property" always starts fresh. Agents may keep multiple concurrent drafts. Resuming preloads the draft's fields and edits it in place (AT-210).
- **Skippable:** only step 1 fields are required to create the draft. Steps 2–4 can be skipped entirely — the property is saved as a draft.
- **Publish gate:** step 4 shows a readiness checklist. Publish button is enabled only when required items are green (title, price, type, suburb, ≥1 photo, description).

### A2. Step 1 — Basics (the only required step)

**Fields:**
- **Listing Type** — two large tiles (For Sale / For Rental). Default: Sale.
- **Property Type** — dropdown from `PropertySettingItem::group('property_type')`.
- **Suburb** — text input with KZN suburb autocomplete (client-side list from existing properties + static KZN list). Autofills City and Region on selection.
- **Street Number + Street Name** — side-by-side (optional but strongly recommended).
- **Price (ZAR)** — number input with live "R 1,200,000" formatting. For rental, label reads "Monthly Rental (ZAR)".
- **Beds / Baths / Garages** — +/- steppers (not typed numbers). Default 0.
- **Short Summary (headline)** — text input, ≤ 120 chars, live character count.

**Smart defaults (set server-side in wizard controller):**
- `agent_id` = current user
- `branch_id` = current user's branch
- `agency_id` = current user's agency
- `province` = "KwaZulu-Natal"
- `listing_type` = "sale"
- `status` = "draft"

**Validation:** client-side — Continue button disabled until Listing Type, Property Type, Suburb, Price, and Short Summary are filled. Server-side validation matches existing `PropertyController@store`.

### A3. Step 2 — Photos

- **Drag-drop zone** (FilePond or native HTML5 drag-drop) — accepts image/*, max 5 MB per file, up to 30 files per batch.
- **Preview grid** — thumbnails with index badge. Drag to reorder.
- **Cover image** — first photo in grid is cover, marked with a gold star. Click any photo's star icon to promote it.
- **Remove** — × on hover removes that image from the queue (before upload).
- **Upload happens on "Continue"** — photos POST to the draft property, stored in `gallery_images_json`. Progress bar shows during upload.
- **Skip allowed** — "Skip for now" button at the bottom right. Photos can be added later from the property show page's Gallery tab.

**Server path:** existing `PropertyController@update` with `gallery_images[]` field — no new upload logic. Reuses `storeImages()` helper.

### A4. Step 3 — Details

- **Full Description** — textarea, 6 rows, char counter (no hard max).
- **Mandate Type** — dropdown from `PropertySettingItem::group('mandate_type')`.
- **Branch** — dropdown, defaulted to user's branch.
- **Agent** — dropdown, defaulted to current user. Only visible if user has scope='all' or 'branch'.
- **Floor Size (m²)** and **Erf Size (m²)** — side-by-side.
- **Rental-only fields** (conditional on step 1 listing type):
  - Deposit Amount
  - Lease Start Date / Lease End Date
- **Commission %** and **Admin Fee** — collapsible "Financials" sub-section (default collapsed).
- **Features** — simple tag input (chips), optional. Stored in `features_json`.

All fields optional. "Continue" is always enabled.

### A5. Step 4 — Review & Publish

**Readiness checklist:**
```
✅ Title / Summary       ✅ Property Type        ⚠ Photos (0)
✅ Price                 ✅ Suburb                ⚠ Description
✅ Beds / Baths          ✅ Agent assigned
```
- Green tick when item meets threshold.
- Warning (amber) for Photos if < 1 and Description if empty.
- Clicking any checklist item jumps back to the relevant step.

**Actions:**
- **Save as Draft** (secondary button) — keeps `status='draft'`, redirects to property show page.
- **Save & Publish** (primary button, blue) — sets `published_at=now()`, `status='active'`. Disabled until all required items are green. Triggers PP/P24 sync via observer.

### A6. Wizard Controller & Routes

**New routes** (additions to `/corex/properties/*` group — protected by existing `access_properties` permission):

```php
Route::get ('/wizard',                       [PropertyWizardController::class, 'start'])->name('wizard');
Route::post('/wizard/draft',                 [PropertyWizardController::class, 'createDraft'])->name('wizard.draft');
Route::post('/wizard/{property}/step',       [PropertyWizardController::class, 'saveStep'])->name('wizard.step');
Route::post('/wizard/{property}/photos',     [PropertyWizardController::class, 'uploadPhotos'])->name('wizard.photos');
Route::post('/wizard/{property}/finalize',   [PropertyWizardController::class, 'finalize'])->name('wizard.finalize');
```

**Controller methods:**
- `start()` — renders the wizard. A plain "New Property" visit ALWAYS starts fresh (AT-188): it only rebinds to an existing draft when the request carries `?resume` (the listing's "Drafts" button / multi-draft popup). An agent may hold **any number** of concurrent drafts. On `?resume`, `start()` also hydrates the wizard (step-1 + step-3 fields + the P24 location picker) from the draft's own saved values so continuing edits the SAME record (AT-210 — `$draftPrefill`).
- `createDraft(Request)` — validates step-1 fields, then **idempotent** (AT-210): with an optional `property_id` that resolves to the caller's own unpublished draft it `->update()`s that row in place (owner/branch/status preserved; `PropertySuburbLinked` only re-fires on a suburb change); otherwise it `Property::create(...)`s a fresh draft with smart defaults. A tampered/foreign/published id mints a new draft rather than mutating another row. This stops "resume" or "back → edit step 1" from minting a duplicate and orphaning the original. **Observer fires but `isPublished()` is false, so no sync yet.** Returns JSON `{id, next_step: 2}`.
- `saveStep(Property, Request)` — validates the submitted step's fields, calls `$property->update(...)`. Returns JSON `{next_step}`.
- `uploadPhotos(Property, Request)` — same logic as `PropertyController@update`'s gallery image branch. Returns JSON `{uploaded: N, urls: []}`.
- `finalize(Property, Request)` — validates publish intent, sets `published_at` + `status='active'` if publishing, calls `$property->update()`. **Observer fires `saved()` → syndication dispatches.** Redirects to property show page.

**All controller methods protect syndication by** never touching `pp_*` or `p24_*` columns directly. Sync is driven entirely by the `published_at` transition via the observer — exactly as it is today.

### A7. Sidebar / Navigation

- **Properties index** "New Property" button routes to `/corex/properties/wizard` by default.
- A small text link next to it: "Use classic form" → `/corex/properties/create` (existing flow, untouched). Power users / Johan can still use the full single-page form.
- Sidebar unchanged — the existing "Properties" link still routes to the index.

---

## Part B: Properties Index Redesign

### B1. Visual Changes

**Header:**
- Keep navy banner but add a light gradient (navy → slightly lighter navy) for warmth.
- Larger "New Property" button, pill-shaped, white with navy text.
- Add a small "+ Quick Add" secondary button next to it (opens wizard).

**KPI cards (replace flat numbers):**
- Each card gets an **icon** on the left (home, house-check, edit, tag, globe).
- Add a **sparkline** below each number showing last 30 days (light grey, no axes).
- Hover raises the card 2px with soft shadow.

**Filter bar:**
- Collapse 7 dropdowns into a single **chip row**: `● Active ● For Sale ● My Listings`.
- Click a chip to toggle a dropdown inline. "More filters" button (text link) reveals the advanced panel below.
- Search input keeps its place, widened.

**Property cards (grid view):**
- Status **ribbon** on the top-left corner (corner-cut, not a pill):
  - SOLD = red
  - ACTIVE = green
  - DRAFT = amber
  - WITHDRAWN = grey
- **Price** becomes the loudest element — 1.5rem bold, brand colour.
- Agent avatar (circle, 28px) + name in footer.
- Photo aspect 16:9 with subtle gradient overlay at bottom for text legibility.
- Bed/bath/garage icons in a single row with counts.
- Hover: card lifts 2px.

**Empty state:**
- Friendly illustration (simple SVG house with a "+" badge).
- Headline: "No properties yet."
- Sub: "Start with your first listing. Takes under 3 minutes."
- Large "Create My First Listing" button linking to the wizard.

**List view:**
- Unchanged structure, but add the status ribbon on the leading cell and make price bold.

### B2. What Is NOT Changing

- Routes (`/corex/properties`) — only the blade template updates.
- Controller logic — `PropertyController@index` untouched.
- Filter query semantics — same URL params.
- The agent picker, status dropdown, sort, advanced filters — same fields, just regrouped visually.
- The grid/list toggle — unchanged.

---

## Data Model / Migrations

**None.** This spec introduces zero schema changes. All existing columns on `properties`, `contact_property`, and `property_files` are reused.

---

## Files to Create

```
app/Http/Controllers/CoreX/PropertyWizardController.php     NEW
resources/views/corex/properties/wizard.blade.php           NEW
resources/views/corex/properties/_wizard-step-1.blade.php   NEW (partial)
resources/views/corex/properties/_wizard-step-2.blade.php   NEW
resources/views/corex/properties/_wizard-step-3.blade.php   NEW
resources/views/corex/properties/_wizard-step-4.blade.php   NEW
resources/views/corex/properties/_empty-state.blade.php     NEW
```

## Files to Modify

```
routes/web.php                                              add wizard routes in /corex/properties group
resources/views/corex/properties/index.blade.php            redesign visuals (no route changes)
```

## Files NOT Touched

```
app/Http/Controllers/CoreX/PropertyController.php           UNCHANGED — wizard is parallel
app/Observers/PropertyObserver.php                          UNCHANGED — syndication still fires on save
app/Jobs/SyncPropertyToWebsite.php                          UNCHANGED
app/Services/PrivateProperty/*                              UNCHANGED
app/Services/Syndication/Property24/*                       UNCHANGED
resources/views/corex/properties/show.blade.php             UNCHANGED
resources/views/corex/properties/create-edit.blade.php      UNCHANGED
resources/views/layouts/corex-sidebar.blade.php             UNCHANGED
```

---

## User Flow (Step by Step)

1. Agent clicks **Properties** in sidebar → lands on redesigned index.
2. Clicks **+ New Property** → `/corex/properties/wizard` loads Step 1.
3. If a draft exists for this user, a top banner offers: "You have a draft — [Continue] or [Start fresh]".
4. Agent fills in listing type, property type, suburb (autocomplete), price, beds/baths/garages, short summary.
5. Clicks **Continue** → POST `/wizard/draft` → draft Property created, wizard advances to Step 2.
6. Agent drags photos into the upload zone. Optionally reorders. Optionally sets cover.
7. Clicks **Continue** → POST `/wizard/{id}/photos` → photos uploaded, wizard advances to Step 3. Or clicks **Skip for now**.
8. Agent adds description, mandate, branch, m² sizes, commission.
9. Clicks **Continue** → POST `/wizard/{id}/step` → fields saved, wizard advances to Step 4.
10. Agent sees summary + readiness checklist.
11. Clicks **Save as Draft** (keeps `status='draft'`, no sync) OR **Save & Publish** (sets `published_at`, `status='active'`, observer dispatches `SyncPropertyToWebsite`).
12. Redirects to `/corex/properties/{id}` (show page).

---

## Permissions

Reuses existing:
- `access_properties` — gate the wizard routes.
- `properties.create` — required for `start`, `createDraft`.
- `properties.edit` — required for `saveStep`, `uploadPhotos`, `finalize`.

No new permission keys.

---

## Acceptance Criteria

- [ ] `/corex/properties/wizard` renders Step 1 for users with `access_properties`.
- [ ] Step 1 Continue creates a draft `Property` row with `status='draft'`, `agent_id=current user`, `agency_id` set correctly.
- [ ] Step 2 photo upload appends URLs to `gallery_images_json`; cover is index 0.
- [ ] Step 3 updates description, mandate, branch, sizes, commission.
- [ ] Step 4 "Save as Draft" returns user to show page with `status='draft'`, `published_at=null`.
- [ ] Step 4 "Save & Publish" sets `published_at` + `status='active'`, fires observer, dispatches `SyncPropertyToWebsite` (verified by checking `P24SyndicationLog` or PP submission log after publish).
- [ ] Existing `/corex/properties/create` still works for power users.
- [ ] Existing edit / update flow unchanged.
- [ ] P24 and PP syndication produce the same result as before the wizard was added (tested by publishing a wizard-created property and one created via the classic form — both should appear in portals identically).
- [ ] Properties index redesign: status ribbons visible on cards, price bolded, empty state shows illustration.
- [ ] Mobile: wizard usable on a 375 px screen, photo upload works on iOS Safari.
- [ ] `scripts/dev-check.ps1` passes with 0 new failures.
- [ ] `php -l` clean on all modified files.
- [ ] `php artisan route:clear`, `view:clear`, `cache:clear` produce no errors.

---

## Mobile App Rebuild

A separate prompt (`/prompts/mobile-property-wizard.md`) briefs the mobile Claude to mirror this wizard against the HFC REST/JSON API. The mobile wizard will POST to the same wizard endpoints (returning JSON), so the server side is shared. See that file for the full brief.

---

## Out of Scope (Future Specs)

- Google Places / What3Words address lookup (currently a static KZN suburb list).
- Bulk property import from CSV / Excel.
- AI-assisted description generation (Ellie integration).
- Seller/landlord contact wizard integration at step 3.
- Video upload.
- Matterport tour upload in-wizard.
