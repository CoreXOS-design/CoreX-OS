# QA2 two-week feature audit — 2026-09-26

**Scope:** every commit unique to `QA2` relative to its merge-base with `origin/main`
(`06ea391fcd0ebb45d88552453af47aeac04820fd`, 2026-09-17) through `HEAD`
(`f03333228d709018ba8e58a17538e4b518e671d5`, 2026-09-23) — 34 commits, 215 files,
+12,608/-1,197 lines, covering roughly 2026-09-14 to 2026-09-23.

**Method:** read-only. Three parallel deep-dive agents, no files changed, no broad test
suite run (per CLAUDE.md §13 — static diff/code reading only). Findings below are exactly
what those agents reported, consolidated and re-ranked across all three. Nothing in this
document has been fixed — report-only, per non-negotiable #2.

**Features in scope:** One-Email Sub-Users (AT-423), tenant-isolation agency-scan fixes
(AT-424), Ad Manager Template Manager, Advanced Guiding + Spot Help (tours), Admin Users
Ledger/Roster restyle (AT-422), Imported Stock (AT-419), plus ~10 smaller one-off fixes.

---

## Ranked findings (most severe first)

### 1. [HIGH — functional regression] Bulk "Resend Invitation" is broken for every genuinely-pending user

**Where:** `app/Http/Controllers/Admin/UserManagementController.php:1224`, inside `bulk()`.

```php
if (! $u->is_active) { $skipped[] = "{$u->name} — is inactive"; continue; }
```

Every newly-invited user (sub-user or normal) is created with `is_active => false`
(`store()` line 307) and only flips to `true` on first login. So the bulk action skips
every user it's meant to serve, every time, and reports "nobody was changed." The
single-row `resendInvite()` (line 1051) has no such check and works correctly — only the
bulk path is broken. This also contradicts `.ai/specs/one-email-sub-users.md` §6.8, which
says Resend must show "regardless of `is_active`."

**Why tests missed it:** `tests/Feature/Admin/UsersLedgerBulkTest.php`'s "pending" fixture
(line 105) sets `email_verified_at => null` but leaves the `agent()` helper's default
`is_active => 1` — a combination the real invite flow can never produce. The test is green
only because its fixture is unrealistic.

**Failure scenario:** Admin creates a user, they never log in, a week later the admin
selects them in the Users list and clicks bulk Resend — 0 sent, no error, no way to tell
why. Falls back to per-row Resend, which still works.

### 2. [MEDIUM] Tours: `TourRegistry::canOpenRoute()` has an incomplete middleware allowlist — assistants get offered guides for pages they can't access

**Where:** `app/Support/Tours/TourRegistry.php:1509-1537`.

`canOpenRoute()` only recognizes `permission:` and `owner_only` middleware; any other gate
(`deny_assistant`, `deny_assistant_property_write`, or an in-controller-only check) is
silently treated as "open." This is the same bug class the AT-422-window fix ("Seller Info
guide follows its page's gate") addressed for one tour — but that fix didn't generalize the
allowlist, so it recurs at:

- `comp-rcr` (defs/compliance-b.php:241-244) — real gate is in-controller only.
- `earn-dashboard` (defs/portal-comms.php:376-379) and `calc-revenue-share`
  (defs/training-ai-calc.php:462-465) — gated only by `deny_assistant`; an assistant (who
  must never see agency finance, per AT-267) is offered both guides.
- `property-capture` write path — gated by `deny_assistant_property_write`, same gap.
- `assist-admin-create` (defs/assistants.php:25-29) — declares the right permission but
  the route's separate `deny_assistant` gate isn't checked.

**Impact:** UX/information-leak (an assistant learns a finance/admin feature exists and is
offered a guide for it, then hits a 403 trying to use it) — not a data leak; the underlying
routes still enforce their own gates correctly. Not caught by `AdvancedGuidingTest.php`,
which only diffs against `permission:`-style gates.

Two other permission-key mismatches (`re-core-matches`, `deals-detail`/`deals-create`) were
also found but are **pre-existing and already tracked** — `AdvancedGuidingTest.php` already
excludes them via `$pendingGateDecision`/`RETIRED_SCREENS` pending a decision. Not new, not
regressions from this window.

### 3. [MEDIUM] Two mojibake instances the "13 pages" punctuation fix (5ed2c727e) didn't reach — one is live legal contract text

- `database/seeders/data/field-groups.json` — 6 garbled entries (e.g. `"Lessor â€" Name
  only"`), seeds `FieldGroup::name`, renders live in the field-groups list, e-sign wizard,
  and CDS builder pickers.
- `resources/views/docuperfect/web-templates/cds/template-111.blade.php` — still garbled.
  The fix commit's own message discloses this exclusion ("generated CDS templates reported
  separately") — worth confirming that separate report was actually filed, since this is
  customer-facing legal contract text, live today.

### 4. [MEDIUM] Rentals: pre-existing cross-agency `rental_agents` pivot rows aren't cleaned up by the AT-424 migration

`database/migrations/2026_09_21_000001_add_agency_id_to_rentals_table.php` correctly
backfills `rentals.agency_id`, but does nothing about the `rental_agents` pivot. Before this
fix, `RentalsController::store/update` synced agent ids with no agency check, so any
pre-fix rental could already have another agency's agent attached — and that stale link
survives untouched, still feeding `WorksheetController.php:943` and
`RentalWorksheetInclusionService`. Not exploitable going forward (new code prevents new
bad links) — this is data-hygiene residue, not a live leak. No backfill/audit step scrubs
old data. **Recommend:** a one-off script cross-checking `rental_agents.user_id`'s agency
against `rentals.agency_id`.

### 5. [LOW-MEDIUM] AT-424: 3 of the 10 claimed fix areas have zero regression-test coverage

`tests/Feature/MultiTenancy/AgencyScanFixesTest.php` has 16 real cross-tenant-attack tests
(attacker = a real second agency's admin, assertion = 403/404 + DB-level "row untouched"),
but only 7 of the 10 areas the fix commit claims are covered. Untested (traced by hand and
found correct, but unguarded against a future regression):
- Command Centre feedback-card class settings (`CommandCentreService.php:1209-1222`)
- Map GPS borrowing (`MapController.php:298-330`, `MapPinService.php:819-940,1339-1362`) —
  the largest, most intricate diff in the whole commit, entirely unpinned
- MIC matched-address lookup (`MarketIntelligenceController.php:874-882`)

### 6. [LOW-MEDIUM] AT-423: no negative-permission (403) tests for any sub-user endpoint

Enforcement itself is correct and doubled-up (route middleware `permission:manage_users` /
`permission:manage_performance_settings`, plus controller-level `abort_unless`), verified by
direct inspection — but `OneEmailSubUserTest.php` never asserts a 403 for a user lacking
those permissions. True by inspection, not proven by the suite.

### 7. [LOW] Two shipped bug fixes this window have zero regression-test coverage for the exact bug they fixed

- `f03d6238c` (rentals mailables — dropped a `$queue` property that fatally collided with
  `Queueable`'s own) — fix is correct, `php -l` clean on all 4 files, but nothing asserts
  the mailables actually instantiate/build. A regression of this exact collision wouldn't
  be caught by CI.
- `c3298713032` (outreach colleague-draft rollback — now rolls back only the triggering
  `<select>`, not every dropdown on the page) — fix is correct, single call site, but only
  server-rendered-data tests exist; the client-side behavior that was the actual bug isn't
  covered.

### 8. [LOW] Cross-agency archived-username collision gets a worse (generic) error message

`UserManagementController.php:261` — the archived-username collision check still carries
`AgencyScope`, so it only catches collisions within the *acting admin's own* agency. If
Agency B tries to reuse a username archived in Agency A, the check misses it and the admin
sees a generic DB-constraint error instead of the intended "belongs to an archived user —
restore them instead" message. No data leak, nothing crashes — just a worse error string.
More likely for usernames than emails, since a username stem is derived only from the
domain label before the first dot. Existing test only covers the same-agency case.

### 9. [LOW] `OneEmailService::mainAccount()` trusts a foreign key without re-checking agency ownership

`app/Services/Users/OneEmailService.php:41-48` resolves `agency->one_email_user_id` via
`withoutGlobalScopes()->withTrashed()->find()` with no check that the found user's
`agency_id` matches the agency. Not exploitable today (the only writer,
`OneEmailSettingsController::update()`, validates against `candidates($agency)` first) —
flagged as defense-in-depth only, in case the column is ever written another way.

### 10. [LOW] One unscoped-looking query next to the AT-424 fixes, not currently reachable

`WorksheetMarketController::index()` (~line 54): `Worksheet::where('period',...)` is
filtered only by period, not agency — but the surrounding `$agents`/`$branches` collections
it's keyed against are correctly agency-scoped by this same fix, so it isn't reachable as a
leak in practice. Worth a defensive `whereIn('user_id', $agents->keys())` if the render
logic ever changes.

### 11. Coverage gaps only (mechanism proven sound elsewhere, just not demonstrated by these features' own suites)

- Ad Manager: the "every active property of every agent" list has no dedicated two-agency
  isolation test (the template side does have one); scoping is real (`BelongsToAgency` on
  both `Property` and `PropertyAdTemplate`, cross-agency id 404s via `canAdvertise()`).
- Imported Stock: no dedicated two-agency isolation test in `ImportedStockTest.php` (908
  lines otherwise thorough); scoping is inherited correctly from `Property`'s own global
  scope.

### 12. Minor / cosmetic, no action needed unless convenient

- Docuperfect delete-copy fix undersells recoverability (doesn't mention the user's own
  Restore button alongside "support can recover it").
- `resources/views/layouts/partials/ellie-widget.blade.php` (348 lines) is dead code, not
  referenced anywhere live; the real guide-button implementation is in `help-widget.blade.php`.
- Imported Stock's empty state reuses the generic Properties "Start with your first
  listing" CTA, which doesn't quite fit an off-market list.
- Task-brief hash mismatch: the 5 commit hashes originally given for AT-419 are all
  ancestors of the window base (already on `main`); the actual in-window Imported Stock
  work is `02c4ac3a0`, `fb1bed451`, `95629b812`. Flagging for whoever tracks ticket→commit
  mapping.

---

## What checked out clean (verified, not assumed)

- **AT-423 One-Email Sub-Users**: soft-delete-only throughout; mail-routing safety net
  (`SubUserMailRouter`) correctly dedupes/cancels/rewrites To/Cc/Bcc/Reply-To; all 6
  spec-listed outward-facing address call sites correctly use `deliveryEmail()`;
  login/password-reset lookups all resolve a single row via the unique index — no other
  `User::where('email', ...)` call site in the app assumes one-user-per-email; agency
  scoping on sub-user candidates/shared-inbox picker; permissions gated at route +
  controller; follow-up commit `1f1af0b98` is a complete, correctly-scoped fix, not a
  partial patch; spec conformance confirmed section-by-section against
  `.ai/specs/one-email-sub-users.md`.
- **AT-424 tenant isolation**: both migrations backfill correctly with a
  backfill-then-NOT-NULL-then-FK pattern (no nullable escape hatch); `AgencyScope`/
  `BelongsToAgency` fails closed; adding `BelongsToAgency` to `Rental`/`TvMessage` closes
  the IDOR on direct-URL access automatically (verified via implicit route-model binding);
  TV message public/unauthenticated routes correctly use a non-`Auth`-dependent scope path;
  7 of 10 fixed areas have real adversarial tests (attacker = second agency's admin, hits by
  ID, asserts 404 + DB-level "row untouched").
- **Ad Manager Template Manager**: full CRUD, soft-delete only, agency-scoped, nav entry +
  permissions present, list-screen completeness (search/sort/filter/pagination/empty-state)
  all present and tested.
- **Admin Users Ledger/Roster (AT-422, non-sub-user parts)**: bulk actions resolve targets
  through the same agency-filtered query the list uses (a crafted cross-agency id is
  silently dropped); deactivate is reversible, no hard delete; permission-gated at route +
  controller; explicitly tested with a two-agency setup.
- **Imported Stock (AT-419)**: scoping inherited correctly from `Property`; own permission
  key + sidebar entry + route gating; migration is nullable/no-default/reversible; takeover
  logic correctly rejects a past expiry date.
- **Tours**: the "Guided Tours switch hides every help surface" fix is confirmed complete
  — no unswitched surface found; ~60+ other tour defs across every remaining def file match
  their real page gates.
- **Smaller fixes**: Ellie `page_path` wiring, notifications header variant, evaluation
  sample-data retirement, docuperfect delete-copy accuracy (aside from the minor nitpick
  above) — all confirmed correct end-to-end.

---

## Note on task-brief accuracy

Several commit hashes in the original audit brief for "Imported Stock" and several of the
"smaller fixes" turned out to be ancestors of the window base (already merged to `main`
before QA2 diverged), not part of this window. Agents confirmed this with
`git log --oneline <base>..HEAD -- <path>` before spending time on them and skipped what
didn't apply — noting here so ticket→commit references get corrected at the source.
