# AT-448 — Property expiry: Expired status, expiry warning popup, expiry lock + Extension document

> Status: **APPROVED by Andre 2026-10-07 (lane owner, QA2) — D1/D2/D6 answered, build in progress on the lane.**
> Ticket: AT-448 "Expired section for properties" (In Progress)
> Branch: `AT-448-Expired-section-for-properties` (Andre's lane → QA2 → Staging)
> Written: 2026-10-07 by Andre's lane
> Pillars: Property (primary), Agent (who is warned / who may unlock), Documents (the Extension upload)
> Governing sister specs: `p24-syndication.md` §AT-68 (expiry pull-down — unchanged by this spec),
> `corex-domain-events-spec.md` (MandateExpired), `agency-onboarding-setup.md` §6.1 (wizard savers),
> `UI_DESIGN_SYSTEM.md` §3.11 (modal), `multi-tenancy.md`.

---

## 0. The request, in Andre's words (2026-10-07)

1. Add an **Expired** option to property status; a property gets it once it is past its expiry date.
2. A new Properties setting called **Expired**: when a property is close to its expiry date (e.g. one week),
   a user opening the **Properties tab** sees a **popup** listing the properties about to expire. The agency
   sets **how many days before expiry** agents are alerted.
3. A second setting, **Expiry lock**: once a property has **gone live**, its expiry date is **locked** and
   cannot be extended. The only way to unlock it is to upload a document to a new Drive folder called
   **Extension**. The lock only applies if the agency turns it on.
4. When a property goes past its expiry date it **automatically becomes Expired** and is **taken off every
   portal** it is active on.

## 1. What already exists (verified in code — reuse, do not rebuild)

| Ask | State today | Evidence |
|---|---|---|
| Auto-expire past the date | **BUILT.** `mandates:expire` runs daily at 00:00, sets `status='expired'`, fires `Mandate\MandateExpired`. No grace period, by Johan's AT-68 ruling (the law, not a preference). | `app/Console/Commands/ExpireMandates.php`, `routes/console.php:548` |
| Take it off every portal | **BUILT.** `DesyndicateExpiredMandate` → `DesyndicatePropertyFromPortalsJob` de-lists Property24, Private Property and the agency website(s), with read-back audit-truth and 3 retries. The same job runs on any manual off-market status change via `PropertyObserver::saved()`. | `app/Listeners/Mandate/DesyndicateExpiredMandate.php`, `app/Jobs/Syndication/DesyndicatePropertyFromPortalsJob.php`, `app/Observers/PropertyObserver.php:596-750` |
| Renewal after expiry | **BUILT.** Extending the date on an expired listing sends the agent an in-app "re-syndicate" reminder; CoreX never auto-relists. | `PropertyObserver.php:155-173`, `app/Notifications/MandateNeedsResyndicationNotification.php` |
| `expired` as a status value | **EXISTS as a system status** (`Property::INACTIVE_STATUSES`, `OFF_MARKET_STATUSES`, badge "Expired"). | `app/Models/Property.php:57, 1692, 1841` |
| "Expired" in the agency's Status dropdown | **MISSING.** Not in `PropertySettingItem::DEFAULT_ROWS` nor the 2026-03 seed, so an agent cannot *choose* it. It only shows when the property already carries it (the "surface current status" fallback). | `app/Models/PropertySettingItem.php:118-135`, `show.blade.php:3183-3196` |
| Expired tile / filter on Properties | **MISSING.** Tiles are Total / On Market / Prospecting / Draft / Sold (Rentals: Available / Rented Out). Filter dropdown has no Expired. | `PropertyController.php:365-392`, `index.blade.php:240-251, 416-437` |
| Expiry warning popup | **MISSING.** No page-load popup exists on Properties. A separate per-user, opt-in bell notification `property.mandate_expiring` exists (default OFF, user-set days) — left as is. | `ScanPropertyNotifications.php:104-125` |
| Expiry lock | **MISSING.** `expiry_date` is freely editable on the property form; validated only as `nullable|date|after_or_equal:listed_date`. Mobile does not accept `expiry_date` at all. | `PropertyController.php:1191, 1608`, `MobilePropertyController.php:240-300` |
| Drive "Extension" folder | **MISSING.** Drive folders are rows in the GLOBAL `document_types` table (no agency_id), added by data migration. Uploads fire no event. | `app/Models/DocumentType.php`, `database/migrations/2026_10_03_200600_add_tpn_document_type.php`, `PropertyFileController::store` |

So the build is: **surface Expired (status item + tile + filter), the two agency settings, the popup, the
lock, the Extension folder, and a verification pass on the already-built sweep.** Item 4 of the request
needs no new code; it needs to be proven on the lane.

## 2. Business behaviour (what the user sees)

### 2.1 Expired status
- **Settings → Modules → Properties & Listings → Property Statuses** gains an **Expired** item for every
  agency (existing agencies by migration backfill, new agencies by the default rows). Agencies may rename
  or deactivate it like any other status; the system value `expired` stays valid regardless.
- The **Status** dropdown on a property offers **Expired**. Choosing it manually behaves exactly like the
  midnight sweep: the listing comes off P24, Private Property and the website, and the audit trail records
  who did it.
- **Properties list** (both Real Estate → Properties and Rentals → Properties): a new **Expired** tile after
  Sold / Rented Out, and **Expired** in the Status filter. Click the tile = filter the list to expired stock.
  Counts come from the same filtered aggregate as every other tile ("whatever filters the list must also
  filter every count").
- Status badge on cards/rows already reads "Expired" — unchanged.

### 2.2 Expiry warning popup
- New agency setting **"Warn agents this many days before a mandate expires"** (default **7**, range 1–90).
- When a user opens the Properties tab and has at least one on-market listing (in their visibility scope)
  whose expiry date falls within the next N days (today inclusive), a popup opens:
  - title "Mandates expiring soon";
  - a list sorted soonest-first: address, listing agent, expiry date, "in X days" / "today";
  - each row links to the property; a **View all** button opens the list filtered to *Expiring soon*;
  - **Got it** closes it.
- **Andre's ruling (2026-10-07): each listing triggers the popup ONCE** — the first time the user opens
  Properties on or after the day it enters the agency's window — and not again. The popup lists only the
  listings this user has not yet been shown; closing it (or clicking through) records them as seen, per
  user, per listing, per expiry date (durable, in the database — a new expiry date after an extension is a
  new cycle and may pop again). Listings already shown never re-appear in the popup, even if still in the
  window; the "Expiring soon" filter is the standing view.
- Popup list is capped at 10 rows with "+N more — View all".
- The filter **Expiring soon** (`status=expiring_soon`) is added to the Status dropdown on both lenses so
  the popup has a real destination and a manager can pull the list any time.
- Who sees what: the popup uses exactly the list's default role scope — an agent sees their own (and
  co-listed) listings, a branch manager their branch, an admin the agency. Never another agency.

### 2.3 Expiry lock + Extension
- New agency setting **"Expiry lock"** (toggle, default **off**).
- A new Drive folder **Extension** exists on every property (global document type, slug
  `mandate_extension`, sale + rental). It is useful filing even when the lock is off.
- With the lock **on**, once a property has **gone live** its Expiry Date is **locked**:
  - *Gone live* = Go Live was pressed on it (`compliance_snapshot_at` set) **or** it has ever been on a
    portal / the website (`Property::wasEverAdvertised()`). Both stamps are sticky, so an expired or
    withdrawn listing stays locked — that is the point.
  - A draft that never went live is not locked.
  - Imported Stock (AT-422) is exempt until it is taken over — its date is an import artefact, and
    setting an expiry *is* the takeover gesture.
  - A live listing with **no** expiry date may have one set for the first time (nothing is being
    extended). Once a date exists, **any** change to it (later or earlier, or clearing it) is locked.
- On the property page the Expiry Date input is read-only with a banner (STANDARDS "No Silent Locks"):
  **"Expiry date locked — this listing is live. Upload the signed extension to the Extension folder in
  Drive to unlock it."** with a button that jumps to Drive with the Extension type pre-selected.
- **Unlock rule:** the date is editable while there is an Extension document on the property uploaded
  **after** the last time the expiry date was changed. Saving a new date re-locks it. So every extension
  needs its own uploaded extension document; one old upload never unlocks forever.
- When the listing is inside the warning window (or already expired) **and** locked, the Overview shows a
  callout: "Mandate expires in X days — upload the extension to Drive, then set the new date." This is
  the "comes from the expiry date setting" part of the request: the folder is always there; the prompt
  to use it appears when the date is close.
- Server-side the lock is enforced in the web update (a locked change is rejected with the banner's
  message, never a 500), not in the model — so imports, the sweep and other system writers are untouched.
- Lock **off** → the date is editable exactly as today; the Extension folder still exists.

### 2.4 Automatic expiry and portal removal
- Unchanged (AT-68). This spec adds the **Expired** status item so the sweep's result is a status the
  agency can see, pick and filter — and a QA2/Staging verification of the full chain (see §9).

## 3. Decisions made by the lane (business consequence in one line each)

| # | Decision | Consequence for the agency |
|---|---|---|
| D1 | Popup once per listing per user (DB-stored, keyed on expiry date) — **Andre's ruling** | A listing is announced exactly once when it reaches the window; no daily nagging; an extended date can announce again. |
| D2 | No agency off-switch for the popup — **confirmed by Andre** | Every agency gets it; the days setting is the only dial. |
| D3 | Warning window counts on-market stock only | Drafts, prospecting, sold, withdrawn, expired never appear in "expiring soon". |
| D4 | "Gone live" = Go Live pressed OR ever advertised (sticky) — **confirmed by Andre** | A listing never un-locks by being withdrawn or expiring. Drafts stay free. |
| D5 | Any change to an existing date is locked, not just extensions | One rule to explain; shortening also needs the document. |
| D6 | Any file type in Extension unlocks (PDF or photo) — **confirmed by Andre** | Agents photographing a signed extension on mobile are not trapped. |
| D7 | Unlock is per-upload (document newer than last date change) | Each extension needs its own paperwork on file. |
| D8 | Extension folder is global (every agency sees it) | That is how every Drive folder works; agencies rename in Settings → Document Types. |
| D9 | Both settings go in the Setup Wizard (Properties step) | Nothing deliberately left out; no Johan omission call needed. |
| D10 | Manual "Expired" fires `MandateExpired` like the sweep | One audit trail and one de-listing path whether a human or the clock expired it. |
| D11 | Existing per-user bell notification `property.mandate_expiring` is untouched | Two dials exist (agency popup days vs user bell days); flagged in §11 as a possible follow-up, not changed here. |

## 4. Data model

### 4.1 `properties`
- `expiry_date_changed_at` DATETIME NULL — stamped by `PropertyObserver::saving()` whenever `expiry_date`
  is dirty on an existing row (and on create when a date is supplied). Backfill: NULL (treated as "never
  changed", so the first unlock needs any Extension document).

### 4.2 `property_setting_items` (agency-scoped, NOT NULL agency_id)
- New default row group `property_status`: **Expired**, `sort_order` 12 (after Withdrawn), `is_default=1`,
  `active=1`. Provisioned by **migration backfill per agency that already has property_status rows**
  (copy `2026_08_20_000001_add_sold_by_3rd_party_status_item.php` exactly — idempotent, soft-deleted rows
  don't count as present) **and** added to `PropertySettingItem::DEFAULT_ROWS` for new agencies.
  Slug check: "Expired" → `expired` = the system value. No punctuation.

### 4.3 `document_types` (global)
- New row: `slug='mandate_extension'`, `label='Extension'`, `grouping='property'`,
  `listing_types=["sale","rental"]` (**mandatory** — without it files land in "Other Documents"),
  `is_active=1`, `sort_order=max+1`. Idempotent data migration (copy the TPN migration + set
  `listing_types`). Reference data travels by migration, so `deploy:sync-reference-data` needs nothing.

### 4.3a `property_expiry_popup_views` (new, per user)
- `id`, `agency_id` (BelongsToAgency), `user_id` FK users, `property_id` FK properties, `expiry_date` DATE
  (the expiry the listing carried when shown), `seen_at` DATETIME, timestamps.
  Unique `(user_id, property_id, expiry_date)`. Model `App\Models\PropertyExpiryPopupView`. Rows are a
  log and are never deleted (no delete path exists).

### 4.4 `performance_settings` (per agency, via `PerformanceSetting::get/set` with explicit `$agencyId`)
- `mandate_expiry_warn_days` int, default 7, range 1–90.
- `mandate_expiry_lock_enabled` 0/1, default 0.

No schema snapshot change beyond the one new column → run `DB_DATABASE=hfc_dash_test php artisan
schema:dump`, strip DEFINER with perl (memory: PowerShell strip corrupts), verify no table dropped.

## 5. Settings + Setup Wizard

### 5.1 `/corex/settings?s=feature-properties` — new card **"Mandate Expiry"** (after Syndication Approval)
- Number input "Warn agents … days before a mandate expires" + Save.
- Toggle "Expiry lock — once a listing is live, its expiry date can only change after an Extension
  document is uploaded to Drive" (switch submits on change, hidden `0` companion like `pp_exclusivity_enabled`).
- Helper copy under each control: what it is + "What this changes:".
- Route `POST /corex/settings/mandate-expiry` → `SettingsController@updateMandateExpiry`,
  middleware `permission:access_settings` (same as Syndication Portals); redirect back to
  `['s' => 'feature-properties']`.
- Saver rules (§6.1 of the wizard spec): validate first (`mandate_expiry_warn_days` required|integer|
  min:1|max:90 **only when present**); every write `$request->has()`-guarded; tenant id resolved with
  `?: 0` and `abort_if($agencyId <= 0, 403)` before any write; hard failures throw `ValidationException`.
- Read side: `index()` loads `$mandateExpiryWarnDays`, `$mandateExpiryLockEnabled` for the agency.

### 5.2 Runtime resolver (never read PerformanceSetting inline in jobs/controllers)
`App\Services\Properties\MandateExpiryPolicy` (new, small):
- `warnDaysFor(?int $agencyId): int` — default 7 when `$agencyId <= 0` or unset; clamps 1–90.
- `lockEnabledFor(?int $agencyId): bool` — false when `$agencyId <= 0`.
- `isExpiryLocked(Property $p): bool` — lock on ∧ `hasGoneLive()` ∧ `expiry_date !== null` ∧
  `!isImportedStock()` ∧ `!hasFreshExtensionDocument()`.
- `hasFreshExtensionDocument(Property $p): bool` — a non-deleted `documents` row joined via
  `document_properties` with `document_type.slug='mandate_extension'` and `created_at >
  (expiry_date_changed_at ?? '1970-01-01')`.
- `expiringWindow(?int $agencyId): [Carbon $from, Carbon $to]` — today … today+N.

Property model additions: `hasGoneLive(): bool`, `scopeExpiringSoon($q, Carbon $from, Carbon $to)`
(on-market via `whereNotIn('status', OFF_MARKET_STATUSES)` + `whereBetween('expiry_date', …)`).

### 5.3 Setup Wizard (`config/agency-onboarding-copy.php`, **properties** step)
Two `source => 'perf'` controls with `explain` + `affects`:
- `mandate_expiry_warn_days`, type number, default 7, min 1, max 90, label "Warn agents before a mandate expires (days)".
- `mandate_expiry_lock_enabled`, type toggle, default 0, label "Expiry lock".
Saver: `['controller' => SettingsController::class, 'method' => 'updateMandateExpiry']`.
`perf` source needs no `currentValues()` change. Add both keys to
`tests/Feature/Onboarding/AgencySetupWizardSaverGuardTest.php` coverage (absent field = untouched).

## 6. Screens, navigation, permissions, scoping (BUILD_STANDARD §1)

| Screen | Entry | Permission | Scope | Search / sort / filter |
|---|---|---|---|---|
| Properties list — Expired tile + filter, Expiring-soon filter | existing page, both lenses | `access_properties` (existing) | existing own/branch/all + AgencyScope; counts use the same filtered clone | existing search; sort unchanged (default newest); new filter values `expired`, `expiring_soon` |
| Expiry popup | opens on Properties list load | `access_properties` | same `applyRoleScope()` as the list (make the private helper reusable, or add `Property::scopeVisibleToListDefault`) + AgencyScope; cap 10 | sorted `expiry_date ASC`; empty ⇒ no popup rendered at all |
| Property page — locked Expiry Date + banner + callout | existing Lifecycle section / Overview | existing edit auth (`authorizeProperty`) | existing | n/a |
| Drive — Extension folder | automatic (active type with listing_types) | existing Drive permissions | existing | existing |
| Settings card | rail: Modules → Properties & Listings | `access_settings` | per agency (`agency.required`) | n/a |
| Settings → Document Types | existing CRUD for the new type | existing | global | existing |

No new page ⇒ no new sidebar entry. No new permission key (declared; nothing hidden behind a missing one).

## 7. User flows

**A. Agent opens Properties, 5 days before a mandate ends (warn days = 7)**
1. Controller computes `expiringSoon` for the user's scope, minus listings with a `property_expiry_popup_views` row for this user and this expiry date ⇒ pass `$expiringProperties` to the view (none ⇒ no popup markup at all).
2. Popup opens (`<x-modal :show="true">` per §3.11). Rows link to properties; View all → `?status=expiring_soon`.
3. Got it / overlay / Esc / click-through → `POST api/v1/properties/expiry-popup/dismiss` (named, under
   `/api/v1`, relative URL per the System-Updates gotcha) writes one `property_expiry_popup_views` row per
   listed property (idempotent upsert, ids re-checked against the user's scope) for this user.

**B. Agent extends a live mandate, lock ON**
1. Property page shows Expiry Date read-only + banner + "Go to Drive → Extension".
2. Agent uploads the signed extension into Extension (web or mobile — the mobile Drive lists folders from
   the global types, no app change).
3. Reloads property: Expiry Date is editable; banner now reads "Unlocked by the extension uploaded on
   {date} — save the new expiry date." Agent sets date, saves.
4. Observer stamps `expiry_date_changed_at`; the field is locked again. If the listing was expired, the
   existing re-syndicate reminder fires (AT-68); status stays `expired` until the agent sets it (unchanged
   behaviour, flagged §11).

**C. Agent tries to bypass (hand-crafted PUT with a new date, lock ON, no fresh extension)**
→ 422 with "Expiry date is locked — upload the signed extension to Drive first." Nothing saved.

**D. Midnight sweep (unchanged) → property shows under the new Expired tile next morning.**

**E. Agent picks "Expired" in the Status dropdown**
→ observer: audit, P24 'Expired' push, PP Inactive, website removal; plus `MandateExpired` fired with
`actorUserId` (D10). Idempotent job guards make the second dispatch harmless (already documented).

## 8. Input space / prevent-or-absorb (BUILD_STANDARD §2–3)

| Input | Decision |
|---|---|
| warn days empty / "abc" / 0 / 500 | Prevent: validation message; nothing written |
| warn days absent from a wizard post | Absorb: untouched (has() guard) |
| lock toggle absent | Absorb: untouched |
| `$agencyId` 0 (owner outside switcher) on save | Prevent: 403 before any write |
| `$agencyId` 0 on read (popup/lock) | Absorb: defaults (7 / off) — no popup, no lock, no write |
| expiry_date re-submitted unchanged on a locked listing | Absorb: compared as normalised dates — not a change |
| expiry_date cleared on a locked listing | Prevent: counts as a change |
| first expiry date on a live listing with none | Absorb: allowed |
| Imported Stock takeover via expiry date | Absorb: exempt from the lock |
| Extension document soft-deleted after unlocking | Lock re-engages (query excludes trashed) |
| Extension uploaded to an agency with lock OFF | Absorb: filed, no behaviour |
| Property with null expiry_date in the window | Not "expiring"; not in popup |
| Listing agent deleted / null on a popup row | Render "— " for agent; row still links |
| Popup dismissal on a web-only QA box | Works (direct DB write, no queue) |
| Dismiss posted twice / for a property outside the user's scope | Upsert is idempotent; ids are re-checked against the user's scope before writing |
| Expiry date extended after being announced | New (user, property, expiry_date) key ⇒ announces again when it re-enters the window |

## 9. Verification plan (what will be proven, with which paths)

Single relevant test file during the build: **`tests/Feature/Properties/PropertyExpiryTest.php`** (new):
- status item: migration provisions "Expired" per agency, idempotent, new agency gets it from defaults,
  dropdown slug equals `expired`;
- tiles/filters: expired count equals filtered rows; `expiring_soon` honours window + scope + lens;
- popup: shown inside window, hidden outside, hidden when none, hidden for off-market, scoped own/branch/
  all, once per listing per user (seen rows excluded; a new expiry date announces again), dismiss
  endpoint registered under `/api/v1`, named, idempotent, scope-checked;
- lock: off ⇒ editable; on + draft ⇒ editable; on + live ⇒ 422 with message; on + live + fresh
  extension ⇒ saves and re-locks; stale extension (older than last change) ⇒ 422; cleared date ⇒ 422;
  same date ⇒ ok; first date ⇒ ok; imported stock ⇒ ok; soft-deleted extension ⇒ 422;
- manual Expired fires `MandateExpired` once with actor and dispatches the de-syndication job;
- settings saver: validation bounds, has() guards, 403 on agency 0.
Plus a wizard-guard case in `AgencySetupWizardSaverGuardTest`.

Then: `php -l` on changed files, `view:clear`, the single test file, `verify-alpine-render.mjs` on the
Properties list and the property page (Alpine touched), `scripts/dev-check.ps1` runs locally on Windows
(this is the Windows lane; dev-check is available here).

**Chain proof on QA2** (web-only, no scheduler): set a listing's expiry to yesterday, run
`php artisan mandates:expire --dry-run` then live, confirm status → Expired tile, P24/PP/website rows
`deactivated`, bell reminder on renewal. Portals are neutralised on QA; the real portal read-back is
first proven on Staging after Andre's go.

## 10. Files to create / modify

**Create**
- `database/migrations/2026_10_07_100000_add_expired_property_status_item.php`
- `database/migrations/2026_10_07_100100_add_mandate_extension_document_type.php`
- `database/migrations/2026_10_07_100200_add_expiry_date_changed_at_to_properties.php`
- `database/migrations/2026_10_07_100300_create_property_expiry_popup_views_table.php`
- `app/Models/PropertyExpiryPopupView.php`
- `app/Services/Properties/MandateExpiryPolicy.php`
- `app/Http/Controllers/Api/V1/PropertyExpiryPopupDismissController.php`
- `resources/views/corex/properties/partials/expiry-popup.blade.php`
- `tests/Feature/Properties/PropertyExpiryTest.php`

**Modify**
- `app/Models/PropertySettingItem.php` — DEFAULT_ROWS: Expired
- `app/Models/Property.php` — `hasGoneLive()`, `scopeExpiringSoon()`, fillable/cast for the new column
- `app/Observers/PropertyObserver.php` — stamp `expiry_date_changed_at`; fire `MandateExpired` on manual expired
- `app/Http/Controllers/CoreX/PropertyController.php` — Expired/expiring filters + tile count; popup data; lock enforcement in `update()`; `$isExpiryLocked` / `$extensionUnlockedAt` for the view
- `resources/views/corex/properties/index.blade.php` — Expired tile (both lenses), filter options, `@include` popup
- `resources/views/corex/properties/show.blade.php` — locked Expiry Date + banner; expiring callout
- `app/Http/Controllers/CoreX/SettingsController.php` — `index()` reads; `updateMandateExpiry()`
- `resources/views/corex/settings.blade.php` — Mandate Expiry card
- `routes/web.php` — settings POST; `/api/v1/properties/expiry-popup/dismiss`
- `config/agency-onboarding-copy.php` — two controls + saver in the properties step
- `tests/Feature/Onboarding/AgencySetupWizardSaverGuardTest.php` — guard case
- `database/schema/mysql-schema.sql` — re-dump from the test DB (new column)
- `.ai/CHAT_STARTER.md` — IN FLIGHT entry
- Demo: `DemoDataSeeder::backfillPropertyStatusItems()` picks up DEFAULT_ROWS (verify, change only if it hard-codes names)

**Not touched (declared):** `ExpireMandates`, `DesyndicatePropertyFromPortalsJob`, portal sync services
(refresh-cost contract untouched — no new portal calls), `ScanPropertyNotifications`, mobile API.

## 11. Boundaries and follow-ups raised, not built

- After an extension on an *expired* listing the status stays `expired` until the agent changes it
  (pre-existing AT-68 behaviour). A "set back to Active" prompt in the renewal reminder would close it —
  separate ask.
- Two "days" dials now exist: the agency popup window (this spec) and each user's opt-in bell threshold
  for `property.mandate_expiring`. Collapsing them (agency value as the bell default) is a one-line
  follow-up once Andre confirms it is wanted.
- `PropertyStatusChanged` domain event from the events catalogue is still unimplemented; this spec fires
  the existing `MandateExpired` instead of inventing a path.
- Scheduler-dependent proof (the real midnight run) can only be watched on Staging, not QA2.

## 12. Acceptance criteria

- [ ] "Expired" appears in Settings → Property Statuses for every agency and in the property Status dropdown
- [ ] Properties list shows an Expired tile and filter on both lenses; tile count = filtered rows
- [ ] Settings → Properties & Listings has the Mandate Expiry card; both controls save per agency and appear in the Setup Wizard with explain/affects; wizard posts never wipe the other value
- [ ] Opening Properties inside the window shows the popup once per listing per user, scoped correctly, with working links and View all → Expiring soon
- [ ] Lock off: date editable. Lock on + live: read-only with banner and Drive shortcut; direct PUT rejected with the message
- [ ] Drive shows an Extension folder on sale and rental properties; uploading there unlocks the date; saving re-locks; a stale or deleted upload does not unlock
- [ ] Manual Expired and the midnight sweep both leave the listing off P24, PP and the website, with one audit line each
- [ ] Test file passes for every path in §9; render gate green on the two Blade pages; nothing outside §10 touched
