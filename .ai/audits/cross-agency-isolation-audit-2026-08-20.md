# Cross-Agency Isolation Audit — 2026-08-20

> Status: **LIVE.** All 10 QA2/Staging fix commits (findings C1, C2, C3, H1, H2, M1,
> M2, the `Docuperfect\Template` gap, and 3 hygiene items) are now deployed to
> production (`corexos.co.za`), migrated, and re-verified against the real live
> database. **Round 1** built + deployed to QA2. **Round 2** follow-up sweep (M5 safe,
> M3 partial, M4 found + fixed `Docuperfect\Template` and the separate
> `ActivityDefinition` live-only bug). **Round 3** promoted the QA2 set to Staging.
> **Round 4** promoted the same set to live — see "Round 4 — Live promotion" at the
> end of this document for the full record, including a diverged-branch recovery
> (patch-id-verified, matching this project's own prior-documented pattern for the
> exact same situation).
> Requested by: Johan, ahead of onboarding more agencies onto live CoreX.
> Live tenancy at time of audit: 2 agencies (HFC, a demo/test agency).
> Method: 5 parallel read-only code-investigation passes + 1 targeted follow-up, then
> fixes built/tested in an isolated worktree and deployed to QA2. Round 2: 4 more
> parallel investigation passes, then 2 more fixes (QA2 + one live/main). Round 3:
> merge + deploy pipeline to Staging. Round 4: cherry-pick + deploy pipeline to live.
> Reference: `.ai/specs/multi-tenancy.md`, `.ai/specs/branch-isolation-audit.md` (2026-04-21,
> now partly stale — superseded by this document where they conflict).
>
> **The `ActivityDefinition` fix (the separate live-only bug found in Round 2) is
> also now live** — see Round 4 for why it rode along automatically. Nothing else
> outstanding from this audit is on live yet: M3's remaining scope-bypass surface
> and M4's business-intent question on `PerformanceSetting` are still open, not bugs.

---

## Executive summary

The core isolation mechanism (`AgencyScope` global scope + `BelongsToAgency` trait) is
**sound and has been hardened since the April audit**, not weakened. Model/table coverage
went from 17 models in April to 184 today; the April finding that documents/signatures/
knowledge base had no `agency_id` at all is **fixed**. The single-agency dev/test fallback
is confirmed provably inert with 2 live agencies.

However, three **live, exploitable cross-agency exposures** were found outside that core
mechanism — in the TV display feature and the e-signature pipeline — plus a process/
documentation risk (the "never bypass the scope in request code" rule has drifted from 1
sanctioned occurrence to ~700 across the app, most fine on sampling but not exhaustively
verified). Full findings below, most severe first.

---

## CRITICAL — live-exploitable, cross-agency data exposure

### C1. TV admin controller lets any branch-admin mint or revoke another agency's TV code
**File:** `app/Http/Controllers/Admin/TvCodeController.php` — `generate()`, `revoke()`
**Who can trigger it:** any user with the standard `manage_tv_messages` permission (not
owner-only) — i.e. a normal agency admin.
**What happens:** `branch_id` / `code_id` are validated with Laravel's `exists:` rule only,
which is an unscoped existence check — it does not confirm the branch belongs to the
caller's own agency. An Agency A admin can generate a live public TV code for an Agency B
branch, or revoke Agency B's active codes. Company-wide TV code functions have no agency
column at all, so revoking a "company" code deactivates **every** agency's code
simultaneously.
**Impact:** once minted, that code works on the fully public, unauthenticated
`/tv/display/{code}` route (see C2), exposing the target branch's deal targets, listing-
stock stats, and named agent leaderboard to whoever the Agency A admin shares the code with.
**Note:** this is a *reintroduction* — the same bug class was already found and fixed once on
a legacy TV route; this newer admin controller has the same gap via a different code path.

### C2. TV access codes can be brute-forced — no rate limiting
**File:** `routes/web.php:1505-1507`, `App\Http\Controllers\TV\TvController::verify/display`
**What happens:** access codes are 6 numeric digits (1,000,000 possible values). `POST
/tv/verify` and `GET /tv/display/{code}` carry **no throttle middleware** at all. The code
lookup is not agency-scoped by design (there's no logged-in user at that point — agency is
derived from whichever code matches), so a scripted sweep of the keyspace — trivially fast
with no rate limit — will surface every currently-active code across every agency, HFC and
the demo/test agency both, and each hit returns that agency's full TV dashboard (company-
wide sales targets/actuals, deal status, agent leaderboard).
**Compounding factor:** as more agencies are onboarded, the odds a brute-force sweep lands
on a real, currently-active code only increase.

### C3. E-sign: unscoped `SignatureRequest` route binding leaks signer PII across agencies
**File:** `app/Http/Controllers/Docuperfect/SignatureController.php:2002` (`sendReminder`),
`:2025` (`resendEmail`)
**What happens:** `signature_requests` (and its siblings — `signatures`, `signature_zones`,
`signed_document_versions`, `esign_signing_parties`, etc.) have no `agency_id` column and no
`BelongsToAgency` trait — only the parent `Document`/`Template`/`CdsDraft` is scoped. Both
routes bind `SignatureRequest $signatureRequest` via Laravel implicit route-model binding,
which is therefore **unscoped**, and both call `authorizeDocument($user, $document)` (which
correctly checks `{document}` belongs to the caller's agency) but **never check that
`$signatureRequest` actually belongs to `$document`**.
**Concrete exploit:** an authenticated Agency A user who knows or guesses a
`signatureRequest` ID belonging to Agency B (sequential integer IDs) can pair it with any
document they legitimately own and POST to either route. CoreX will send a reminder/resend
email using Agency B's `signer_name` / `signer_email` / stored signed PDF, and the response
leaks the signer's name back to the Agency A requester. This is a genuine cross-tenant data
leak **and** an unauthorized send action on another agency's live document — on exactly the
pipeline CLAUDE.md's "e-sign integration moat gate" exists to protect.
**Follow-up needed (not done in this pass):** a full grep of `routes/web.php` for
`{signatureRequest}`, `{signature}`, `{amendment}` route params against every controller
method that binds them — only two call sites were checked; the same unscoped-binding problem
likely recurs elsewhere in the e-sign pipeline.

---

## HIGH

### H1. Legacy shared-token TV route is a single point of failure across all agencies
**File:** `routes/web.php:1500-1502`, `TvTokenMiddleware`, `config/tv.php`
`/tv/branch/{branchId}` is gated by one global `TV_TOKEN` env value shared across **every**
agency, mitigated only by a flat `TV_ALLOWED_BRANCH_IDS` allow-list not partitioned per
agency. The code comments already call this a stopgap pending retirement in favor of the
`TvAccessCode` flow. Every additional agency added to the allow-list widens this single
point of failure. Worth actually retiring before onboarding more agencies, not just noting.

### H2. Ellie's prime-rate tool bypasses per-agency scoping via raw SQL
**File:** `app/Services/AI/Ellie/EllieToolkit.php:655-656` (`primeRate()`)
Runs `DB::table('performance_settings')->where('key','sa_prime_rate')->value('value')` — no
`agency_id` filter, no `ORDER BY`. `performance_settings` is agency-scoped
(`unique(agency_id, key)`, NULL row = platform default). The correct path,
`PerformanceSetting::get()`, is used correctly elsewhere (`CalculatorController.php:13`).
If both HFC and the demo agency configure their own prime-rate override, this query returns
whichever row MySQL happens to return first — non-deterministic, and structurally capable of
handing one agency's configured figure to the other. Low real-world impact (an interest rate,
not PII/financial records) but it is a live cross-tenant leak in shipping code, not a
hypothetical, and exactly the raw-SQL anti-pattern the multi-tenancy spec warns against.

### H3. Verify the same-day Knowledge Base agency-scoping fix actually migrated
**File:** `database/migrations/2026_08_20_000001_add_agency_id_to_clauses_packs_knowledge_tables.php`
Until today, `knowledge_documents`/`knowledge_chunks`, the clause library, and packs had **no
agency boundary at all** — 100% of KB content and effectively all clauses/packs were visible
to every agency, including via Ellie's `search_knowledge` tool. This has been fixed in code
(`BelongsToAgency` added to `KnowledgeDocument`, correctly inherited by `KnowledgeChunk`
scopes) as of a commit landing the same day as this audit. **Not independently confirmed:**
whether this migration has actually run on Staging and live. If it hasn't yet run on a given
environment, the pre-fix leak is still live there (and if the column is genuinely absent, the
scope will error rather than leak — fail-closed — but confirm either way).
**Action for Johan/conductor:** run `php artisan migrate:status` on Staging and live and
confirm this migration is applied on both before treating this as closed.

---

## MEDIUM

### M1. Buyer portal write endpoint doesn't check property↔agency ownership
**File:** `App\Http\Controllers\BuyerPortalController::respond`
`property_id` is validated only via `exists:properties,id` — no agency match. A holder of
their own legitimate buyer-portal token can POST a `property_id` belonging to a *different*
agency and have it recorded as a response/activity-log row against that foreign property.
Low blast radius (nothing is read back to the caller) but it's a write-side tenant-boundary
violation and a property-ID-existence oracle.

### M2. Branch-name enumeration across agencies in one admin dropdown
**File:** `/admin/targets/activity-setup` (branch listing query)
Lists every branch across every agency with no agency filter on the *listing* query, even
though the same file correctly checks agency ownership once a branch is actually selected —
the fix was applied on one code path and missed on a sibling path ~20 lines away. Read-only
exposure of branch names only (not operational data), but is exactly the kind of
easy-to-miss sibling-path gap the C1/C3 findings also show.

### M3. "Never bypass AgencyScope in request code" has drifted from 1 to ~700 occurrences
`withoutGlobalScope(s)` / `queryWithoutAgencyScope()` usage has grown from the April audit's
documented single sanctioned occurrence to roughly 700 across ~150 files (mostly
`app/Services/`, some `app/Http/Controllers/`, some `app/Console/Commands/`); the sanctioned
`queryWithoutAgencyScope()` helper is used only 11 times, meaning most bypasses are raw
`withoutGlobalScope` calls that don't go through the documented escape hatch. Every sampled
high-blast-radius site (public links, dashboards, buyer portal, presentation snapshots,
reporting controllers) was checked and found correctly guarded with defense-in-depth — but
this is safe "file by file, because someone remembered," not safe by construction, which is
exactly how C1 and M2 slipped through. **Recommend:** update `.ai/specs/multi-tenancy.md` to
reflect current reality, and consider a CI grep-gate flagging new `withoutGlobalScope`
additions for mandatory review, since manual review no longer scales at this volume.

### M4. 21 models carry `agency_id` but not `BelongsToAgency` (manual-filter-only)
Higher risk of a missed `where` since there's no structural enforcement:
`ActivityDefinition`, `AgentActivityEvent`, `Billing\AgentSeatRelease`, `AgentSignature`,
`AI\AINarrativeCache`, `AI\AiUsageEvent`, `Docuperfect\CompiledTemplateFieldBinding`,
`Docuperfect\DataDictionaryEntry`, `DealV2\DealDocumentAccessLog`,
`DealV2\DealStepWorkOrder`, `Docuperfect\DocumentSealedVersion`, `Docuperfect\Template`,
`MarketReports\MarketDataPoint`, `MinionCaptureArea`, `MinionCaptureRun`,
`MinionCaptureSettings`, `PerformanceSetting`, `ProspectingPriceAnomaly`,
`MarketReports\SchemeOwner`, `SoftDeleteRestoration`, `UserBranchHistory`.
(`Role`/`RolePermission` were also on this list but are confirmed **safe by design** —
intentionally manual per the agency-scoped-roles spec.) None of these 21 were individually
verified for an actual missing filter in this pass — that's the natural next audit step.

### M5. Buyer/client portal cross-agency session boundary — not concluded either way
`ClientUser` intentionally has no single `agency_id` (by design, one buyer can relate to
multiple agencies: `preferred_agency_id`, `locked_to_agency_id`, `current_agency_id`,
`created_by_agency_id`). Auth resolves by email globally (correct — email is the
cross-agency identity key). **Not verified in this pass:** whether, once authenticated, a
client's session is correctly bounded to `current_agency_id`/`locked_to_agency_id` on every
subsequent deal/document/property load, i.e. whether a buyer linked to both HFC and the demo
agency could pivot their portal session to see the other agency's data. Needs a dedicated
controller-level check.

---

## LOW / hygiene (confirmed not exploitable, but worth tidying)

- **Ellie reference-source allowlist** (`routes/web.php:1058-1064`) is gated by the
  `manage_reference_sources` permission key, not an owner/role check, despite a code comment
  saying "super_admin only." If that permission is ever granted to a non-owner role via Role
  Manager, that agency's admin could add URLs to what's meant to be a global, cross-agency
  allowlist. Public URLs only, not tenant data — but the code comment doesn't match the
  actual enforcement.
- **`ViewAsController::update`** validates `branch_id` as `['nullable','integer']` only, no
  ownership check at write time. Confirmed **not exploitable**: `BranchScope` independently
  filters by the user's real `agency_id` before ever applying the branch filter, so setting
  `view_as_branch_id` to a foreign agency's branch just yields zero rows — a confusing UX
  bug, not a leak. Worth tightening validation for hygiene.
- **`AgencyAccessRequestController::authorize()`** hardcodes a `role === 'admin'` string
  check instead of consulting the registered `agency.authorize_external_access` permission
  key. Not a leak (nothing becomes more permissive), but the permission toggle in Role
  Manager currently has no effect on this path — a defined-but-unused permission.
- **`WhistleblowComplaintService::generateLawyerReviewPack()`** has an
  `Agency::withoutGlobalScopes()->first()` fallback that could in principle pick an arbitrary
  agency, but it only ever feeds a hardcoded `[SAMPLE]` placeholder PDF, not real data. Sloppy
  pattern, not a leak.

---

## Confirmed SAFE — no action needed

- **Core mechanism.** `AgencyScope`/`BelongsToAgency` match (and exceed) the documented
  design: owner-role bypass correctly session-gated, `User` self-row carve-out double-gated
  against `AgencyApiKey` id-collision, write-side `agency_id = 0` sentinel guard, strict
  no-NULL-as-shared semantics all confirmed live in code.
- **Single-agency dev/test fallback** — confirmed provably inert with 2 live agency rows
  (`count() === 2` short-circuits the fallback to a no-op).
- **`TrackedPropertyMatchOrCreateService`** — all 5 match strategies (source-ref, GPS
  proximity, erf+suburb, address-token, tie-break) explicitly filter by `agency_id` even
  though they run via `queryWithoutAgencyScope()`. Cannot merge properties across agencies.
- **Website API (`AgencyApiKey`)** — cleanest-built surface in the audit; explicit
  defense-in-depth `agency_id` AND-clause on top of the scope, specifically documented
  against the known id-collision edge case.
- **Buyer-portal link minting, e-sign public signing tokens, presentation snapshot links** —
  all high-entropy tokens (256-bit / `Str::random(64)` / 48-char base62), minted only after
  an agency-scoped ownership check, looked up directly (no enumeration risk).
- **Onboarding portal** — token/slug resolved, sets session agency context, fails closed on
  missing/revoked/expired.
- **Client (buyer) portal auth** — every cross-agency action explicitly gated
  (`selectAgency`, `matchShow`, `matchUpdate`, `propertyShow` all verified against the
  client's own agency associations) — though see M5 for the one path not fully traced.
- **AgencySwitcherController** — non-owner switch attempts correctly 403 (`userCanSwitchTo`,
  matches user's own `agency_id` or branch→agency); both switch routes owner-gated at
  route/controller level; no unvalidated parameter.
- **Agency-access-authorization consent flow** — actually built (not just specced) and wired
  into the live switch path; correctly requires an approved, unexpired request before an
  owner can switch into a flagged agency. Currently off by default on both live agencies,
  matching the documented canary plan.
- **ImpersonateController / ViewAsController** — cannot target an owner-role user; impersonation
  correctly re-scopes via `Auth::login()` swap (no separate scope to get wrong); stashes and
  restores the admin's own `active_agency_id` around the Login-event session wipe so no stale
  value leaks either direction.
- **All `active_agency_id` / `view_as_branch_id` writers** — every one validates ownership or
  derives from trusted session/pivot state; no path found for a non-owner user to
  self-elevate into another agency's view.
- **`RoleManagerController`** — every read/write consistently filters by
  `effectiveAgencyId()` or `whereNull('agency_id')` for global templates; role mutation
  re-checks `$role->agency_id !== $agencyId` before allowing edits. No cross-agency
  role/permission access found.
- **Ellie conversation storage** — every read/write hardcoded to `where('user_id', ...)`,
  not just agency-scoped. No IDOR found across `index`/`send`/`rename`/`archive`.
- **Ellie's core data tools** (`find_property`, `find_contact`, `my_listings`, `my_deals`,
  `list_document_templates`) — all route through agency-scoped model queries. Correctly
  scoped.
- **Ellie's cross-agency-naming bug (commit `39b026955`)** — confirmed by diff to be a pure
  prompt-instruction fix; the underlying tool calls were already correctly scoped, the bug
  was Ellie mislabeling its own-agency answer as if it were about a different agency. Caveat:
  this specific control is enforced by LLM instruction-following, not deterministic code — a
  reasonable mitigation for the reported failure mode, not a hard guarantee against a
  differently-phrased question eliciting the same mislabeling again.

---

## Recommended priority order for fixes

None of the following have been implemented — this is a report per CLAUDE.md rule 2.
Sequencing is my recommendation for when Johan authorizes fix work:

1. **C1 + C2 (TV code system)** — both critical, both cheap to fix (agency-check on
   generate/revoke; throttle + longer code on verify/display). Same feature area, do together.
2. **C3 (e-sign IDOR)** — critical, touches the e-sign pipeline gate — needs a test diff per
   CLAUDE.md's pipeline gate rule regardless of who fixes it.
3. **H3** — a `migrate:status` check, not a code change. Do this one immediately, today,
   regardless of any other sequencing — it's the fastest way to know if a live leak is open
   right now.
4. **H1, H2** — retire the legacy TV token route; fix Ellie's prime-rate query to use
   `PerformanceSetting::get()`.
5. **M1, M2** — straightforward scoping additions.
6. **M3, M4, M5** — process/audit follow-up work, not urgent fixes; scope as a dedicated
   pass once the above land.

---

## What this audit did NOT cover

- No live browser verification was performed (Chrome tooling was not connected this
  session) — all findings are from static code reading, not click-through testing on
  Staging/live.
- 21 manual-filter models (M4) were named but not individually code-read for a missing
  filter.
- Only 2 of ~150 files with scope-bypass usage were deeply verified beyond the sampled set
  in M3; the remainder were sampled, not exhaustive.
- Client/buyer portal post-auth data boundary (M5) was not concluded.

---

## Fix status — 2026-08-20 (same day)

All fixes below were built and tested in an isolated worktree branched from
`origin/QA2` (`/mnt/HC_Volume_103099143/corex-dev-3/worktrees/cross-agency-isolation-fixes`,
its own vendor/DB, never sharing state with `/corex`), pushed to `origin/QA2`, deployed to
`/corex-qa2` (fetch → reset to `origin/QA2` → composer install → migrate → cache clears →
npm build), and then independently re-verified against the live `corex_qa2` database via a
tinker script wrapped in a rolled-back transaction (no permanent test data left behind).

| Finding | Fix | Commit | PHPUnit | Live QA2 re-check |
|---|---|---|---|---|
| C3 (e-sign IDOR) | `authorizeSignatureRequestForDocument()` guard on 5 routes | `7ad65f4c5` | 2 passing | ✅ pairing own doc + foreign signatureRequest → 404 |
| C1 + C2 (TV codes) | `tv_access_codes.agency_id` + `BelongsToAgency`; `Branch::findOrFail` in `generate()`; throttle on verify/display | `bba07d728` | 4 passing | ✅ cross-agency `findOrFail` on code/branch → 404 |
| H2 (Ellie prime rate) | Routed through `PerformanceSetting::get()` | `6d909c124` | 1 passing (+24 regression) | ✅ resolves caller's own agency's rate |
| M1 (buyer portal) | Agency-scoped `Rule::exists` on `property_id` | `74403e2bf` | 1 passing | not re-checked live (covered by PHPUnit) |
| M2 (branch enumeration) | `Branch::query()` (auto-scoped) replacing raw `DB::table()` | `0ff15e6db` | 1 passing | not re-checked live (dormant route, covered by PHPUnit) |
| H1 (legacy TV token route) | `throttle:30,1` added | `0ff17362b` | — (Laravel built-in middleware) | not re-checked live |
| Hygiene: ViewAs branch_id | Agency-scoped `Rule::exists` | `20eb34c69` | 2 passing | not re-checked live |
| Hygiene: Ellie reference-sources | `owner_only` middleware added | `048d20e8b` | 2 passing | not re-checked live |
| Hygiene: AgencyAccessRequest authorize | Checks `agency.authorize_external_access` permission instead of hardcoded role name | `90552f1c3` | 2 passing | not re-checked live |
| H3 (KB agency_id) | No fix needed — already correct on QA2 (migration present, `KnowledgeDocument` uses `BelongsToAgency`) | — | — | confirmed via `migrate:status` on QA2 |

**Deploy note:** an intermediate deploy attempt on `/corex-qa2` accidentally
`git reset --hard`'d it onto `origin/Staging` instead of the fix branch, and ran
composer/migrate/npm against that state. `origin/QA2` itself was never touched (verified
by `git rev-parse` before and after), so nothing was permanently lost — the corrected
deploy re-pointed `/corex-qa2` at the real `origin/QA2` (now containing these 9 commits)
and re-ran the deploy cleanly. Flagging for the record since `/corex-qa2` is Andre's own
environment (`.ai/BUILD_STANDARD.md`) — if he was mid-something there, it briefly ran
Staging's code before being corrected.

**Not fixed — still open, need scoping before any change:**
- M3 (the "never bypass AgencyScope" doc claim is stale — ~700 occurrences exist; most
  sampled sites are fine but not exhaustively verified)
- M4 (21 models with `agency_id` but manual filtering only — none individually confirmed
  broken)
- M5 (buyer/client portal post-auth cross-agency session boundary — not concluded)

**Next step for Johan:** test on QA2 (`qatesting2.corexos.co.za`), and when satisfied,
give the explicit go-ahead to promote `QA2` → `Staging` → live per the normal gate. This
work has NOT been promoted beyond QA2.

---

## Round 2 — follow-up investigation (same day, 2026-08-20)

Requested explicitly: settle M3, M4, M5 before touching more code. 4 parallel
read-only passes (2 covering the 21 previously-unverified models, 1 on the buyer/
client portal, 1 broader controller-level scope-bypass sweep), then fixes for
whatever came back confirmed.

### M5 — buyer/client portal session boundary: **SAFE, confirmed**

Thoroughly traced (11 files read in full). Every client-portal data endpoint
resolves through `ClientAuthService::contactForAgency()` — a single choke point
that requires BOTH `client_user_id` AND `agency_id` to match in one query — so a
stale or tampered `current_agency_id` simply resolves no `Contact` (caught by every
controller's `resolveContact()` helper), never a foreign one. Record-level lookups
(`authorizeMatch()`, `propertyShow()`, seller-insights `show()`) add a second
explicit agency check on top, not just reliance on scope. `selectAgency()` validates
the requested agency against the client's own linked contacts before writing
`current_agency_id`. No gap found. `ContactScope`'s bypass for `ClientUser`
principals is deliberate and compensated for at every call site, not sloppy.

### M3 — broader scope-bypass sweep: no new bugs, but still not cleared

28 controller methods across 19 files read in full (prioritized: admin/settings
dropdowns, report/export endpoints, raw-integer route params) — all correctly
guarded, several with explicit comments documenting the re-scoping. 8 heaviest-
usage console commands checked against every `Artisan::call` site — none reachable
from a web route. **However**: this pass plus the original Services-focused pass
together cover roughly 10-15% of the ~700-occurrence `withoutGlobalScope`/raw-
`DB::table()` surface across ~150 files. Both real bugs found this session (TV
codes, branch-enumeration dropdown, and now Template + ActivityDefinition below)
came from full-method traces, not from the grep alone — the grep finds candidates,
only a full read tells you if one's actually broken. **Status: still open**, not
cleared. Recommend either a dedicated follow-up sweep at some point, or a CI
grep-gate flagging new `withoutGlobalScope` additions for mandatory review given
the volume is now too large for manual review to scale (this was also flagged in
the original M3 finding).

### M4 — 21 previously-unverified models: 19 SAFE, 2 GAPS (both fixed)

**SAFE, traced not rubber-stamped:** `AgentActivityEvent`, `Billing\AgentSeatRelease`,
`AgentSignature`, `Docuperfect\CompiledTemplateFieldBinding`,
`Docuperfect\DataDictionaryEntry`, `DealV2\DealStepWorkOrder`,
`Docuperfect\DocumentSealedVersion`, `MinionCaptureArea`, `MinionCaptureRun`,
`MinionCaptureSettings`, `MarketReports\SchemeOwner`. **SAFE by design (intentional
cross-agency shared data)**: `MarketReports\MarketDataPoint`. **SAFE by convention,
not structural enforcement** (flagging for awareness, not a bug): `AI\AINarrativeCache`
— cache-key collision safety depends on every caller embedding an agency/user/
document identifier; all 6 current callers do, but nothing stops a future caller
from regressing it. **LOW-RISK, no live read path today** (nothing to fix until a
UI is built): `DealV2\DealDocumentAccessLog`, `ProspectingPriceAnomaly`,
`SoftDeleteRestoration`, `UserBranchHistory` (low-sensitivity data even if reachable).
**Business-intent question, not a confirmed bug:** `PerformanceSetting` —
`Admin\BackupController::updateThreshold()` writes the backup-staleness alarm
threshold with no `agency_id`, reachable by any admin with `view_backups` (not
owner-only). May be correct if the threshold is genuinely meant to be one
platform-wide value (matches the model's documented pattern for genuinely-global
keys) — flagging for Johan to confirm intent rather than assuming either way.

**GAP #1 (HIGH, fixed) — `Docuperfect\Template`.** `docuperfect_templates` has no
`agency_id` column (tenancy is via `is_global` + the `docuperfect_template_branches`
pivot, since a branch belongs to exactly one agency). 10 authenticated endpoints
across `TemplateController` (edit, saveFields, uploadPageImages, archive, restore,
copy, webPreview, destroy, wizardConfig, saveWizardConfig), plus
`PageImageController::show` (no permission check at all) and
`DocumentImporterController::editFromTemplate`, did `Template::findOrFail($id)`
with only a `hasPermission('manage_templates')` check — an ordinary, per-agency-
grantable permission, not owner-only — so any agency's admin/agent could read,
rewrite, archive/restore, delete, view page images of, or clone ANY other agency's
e-sign template by id. `PackController::resolveSelectableTemplates` had the same
class of gap via client-supplied `selected_templates` IDs fed into an unscoped
`whereIn`. **Fixed and deployed to QA2** — see Round 2 fix status below.

**GAP #2 (HIGH, fixed, LIVE PRODUCTION) — `ActivityDefinition` /
`DailyActivitySetupController`.** `storeDefinition()` hardcoded every new manual
activity as `scope='system', agency_id=null` — globally visible/usable by every
agency the instant any agency's admin created one. `updateDefinition()` did a raw
`DB::table()` update by bare ID with zero ownership check — any admin with
`manage_targets` (ordinary, non-owner-exclusive) could edit or corrupt another
agency's activity-points configuration by guessing/enumerating the ID, directly
affecting commission/points scoring. **This controller does not exist on the QA2
branch at all** (built on `main` independently after QA2 diverged) — confirmed by
grepping both branches' routes. This means the bug is live on production right
now, between HFC and the demo/test agency. **Fixed and pushed to `origin/main`**
per Johan's explicit direction — see Round 2 fix status below.

Also surfaced as a side-effect, not fixed (flagging, not acting — a product/
architecture decision, not a security patch): `DailyActivitySetupController`'s
`activity_definitions.scope` convention (`'system'` or a raw branch-ID string)
is inconsistent with the Eloquent `ActivityDefinition` model's own enforced
contract (`'system'` or `'agency'` + `agency_id`), which `TargetController`'s
sibling `activityDefinitionsSave()` correctly follows on the *same table*. The
model's own `saving` hook would reject a row with `scope = "42"` if ever loaded/
saved through Eloquent instead of the raw `DB::table()` calls this controller
uses. Worth a dedicated look — not addressed here to keep the security fix minimal
and unambiguous on a live branch.

### Round 2 fix status

| Finding | Fix | Commit | Branch | Tests | Live re-check |
|---|---|---|---|---|---|
| M4 GAP #1 (`Docuperfect\Template`) | `Template::assertAccessibleBy()` guard wired into 10 call sites across `TemplateController`/`PageImageController`/`DocumentImporterController`; `PackController` scoped via existing `Template::visibleTo()` | `e9def6061` | `QA2` | 10 passing (incl. pipeline-gate test — `Template.php` is a listed pipeline file) | ✅ deployed to `/corex-qa2`, verified live in a rolled-back transaction |
| M4 GAP #2 (`ActivityDefinition`) | Only System Owners may write/edit `scope='system'`; everyone else confined to their own branch's rows; ownership check added to `updateDefinition()` | `0bf31e47c` | **`main`** (production) | 5 passing | pushed to `origin/main`, confirmed landed — **NOT yet deployed to the live host** (`/corex` is 1 commit behind `origin/main`); deploying it is Johan's QA1-gate call, not done here |

**M5 and M3**: no code changes — M5 closed as SAFE, M3 remains an open
recommendation (CI gate or dedicated sweep), not an active vulnerability.

**Still open after Round 2, need scoping before any further fix:**
- The `ActivityDefinition` scope-convention inconsistency described above (product
  decision, not security).
- 21 unverified models is now 0 — fully cleared this round.
- M3's remaining ~85-90% of the scope-bypass surface — no active finding, just
  unverified.

---

## Round 3 — Staging promotion (same day, 2026-08-20)

Requested explicitly: promote the QA2 fix set to Staging and re-audit.

### Merge, not a straight branch swap

`origin/QA2` and `origin/Staging` share a common ancestor (QA2's prior 1368 commits
had already been merged into Staging at some earlier point, unrelated to this
audit) — checked via `git rev-list --left-right --count origin/Staging...origin/QA2`
before touching anything: **1378 commits Staging-only, exactly 10 commits QA2-only**,
and those 10 were confirmed to be precisely this audit's fix commits (nothing else).
This meant merging `origin/QA2` into a fresh `origin/Staging`-based branch would pull
in ONLY the fix commits, not any of Andre's other QA2-resident work — verified this
was true before merging, not assumed.

Built in an isolated worktree (own vendor/DB, `git worktree add -b
promote/cross-agency-isolation-fixes-to-staging origin/Staging`). `git merge
origin/QA2` auto-merged cleanly (6 files needed 3-way merging: `DocumentImporterController.php`,
`SignatureController.php`, `TemplateController.php`, `TvController.php`,
`Template.php`, `routes/web.php`) — no conflict markers, verified by grepping the
merged tree for `<<<<<<<`/`=======`/`>>>>>>>` and confirming every fix's key
markers (guard function call counts, throttle middleware, `owner_only`) survived
the merge intact, not just trusting a clean `git merge` exit code (per
`.ai/WORKTREE_RULES.md`'s own warning that a silent merge can produce broken code
without ever flagging a textual conflict).

### One real issue found running the merged suite: a test-fixture gap, not a fix bug

Running all 9 test files together (60 tests) against Staging's actual codebase
surfaced one failure: `CrossAgencySignatureRequestBindingTest`'s "own document,
own signature request still works" case returned 404 instead of the expected
302. Root cause, confirmed via `Log::debug` instrumentation and a direct SQL
check: Staging independently added `BelongsToAgency` to `SignatureTemplate` on
2026-08-15 (commit noted in that model's own docblock — unrelated to this audit,
someone else's fix for a different leak — `scopeVisibleTo()`'s 'all' branch was
fully unscoped). The audit's test created a `SignatureTemplate` outside a
request/auth context, so the trait's auto-stamp-from-`Auth::user()` hook had
nothing to stamp `agency_id` from, leaving it NULL — which then made
`AgencyScope` hide the template from its own creator's later relationship
lookup. Confirmed via direct SQL that `document_id` was correctly written to the
database; the failure was purely in the Eloquent relationship read being scoped
away. **Not a bug in the security fix itself** — a real controller-created row
auto-stamps `agency_id` correctly, and the fix's own logic (compare
`$signatureRequest->template?->document_id` against `$document->id`) actually
degrades SAFE either way (a scoped-away template also correctly resolves to 404,
matching Staging's own newer protection reinforcing this fix, not conflicting
with it). Fixed by stamping `agency_id` explicitly in the test fixture. All 60
tests passed after the fix.

### Deploy

Blocked once, correctly: `scripts/deploy.sh` refused because `/corex-staging`'s
working tree had two uncommitted files (`template-72.blade.php` owned by `root`,
`template-74.blade.php` owned by `www-data` — the latter almost certainly
generated by a real user exercising the CDS template-builder feature on Staging
that same day). Per instruction: `git stash push -u`, ran the deploy, `git stash
pop` immediately after — confirmed byte-identical restoration via `git status`
showing them back as untracked with no diff.

Deploy prerequisites checked before running (disk headroom, backup config,
DB size) rather than assumed: `/` had 5.9GB free, `hfc_staging` is 1.21GB raw
(compressed backup came out to 71MB — well within margin, no repeat of the
disk-hygiene incident `.ai/CLAUDE.md` warns about). Full pipeline ran clean:
pre-flight → local `mysqldump` backup (`/var/backups/hfc/hfc_staging-pre-deploy-
20260820-201458.sql.gz`) → maintenance mode → fast-forward pull → composer
install → migrate (the `tv_access_codes` agency_id backfill migration found and
correctly backfilled **7 real existing rows via branch_id + 3 via created_by** on
Staging's actual data — the fix wasn't just tested against synthetic rows, it
ran against real accumulated TV-code data) → 15 reference seeders → npm build →
cache rebuild → queue worker restart → verify (`HEAD` confirmed matching
`origin/Staging`, all 11 reference tables populated) → exit maintenance. Tagged
locally as `deploy-staging-20260820-201458`.

### Live re-verification

Same rolled-back-transaction method as the QA2 verification (real `hfc_staging`
database, zero permanent writes) — re-ran all 5 spot-checks (TV code/branch
cross-agency `findOrFail`, e-sign cross-document IDOR, Ellie prime-rate agency
resolution, Template cross-agency access) directly against the live Staging
site's actual database post-deploy. **All 5 passed.**

### What's now where

| Environment | Has the QA2 fix set (10 commits)? | Has the `ActivityDefinition` fix? |
|---|:---:|:---:|
| QA2 | ✅ | N/A — bug doesn't exist there |
| **Staging** | ✅ **(this round)** | ❌ not promoted yet |
| main / live host | ❌ | ✅ pushed to `origin/main`, **not deployed to `/corex`** |

**Next step for Johan:** test on Staging (`staging.corexos.co.za`), and when
satisfied, give the go-ahead for the live promotion. Separately, the
`ActivityDefinition` fix on `main` still needs its own QA1 → Staging → live walk
before it reaches production, since it never went through QA2 at all.

---

## Round 4 — Live promotion (same day, 2026-08-20)

Requested explicitly, after Staging testing: push the same fix set to live.

### Merge target: `main`, not `Staging` — and not by merging Staging

`origin/main` and `origin/Staging` had diverged independently of this audit (22
main-only commits, 35 Staging-only commits at the time) — this codebase's normal
operating pattern (hotfixes land on `main` directly; Staging carries feature work;
periodic reconciliation). Merging `origin/Staging` into `main` wholesale would have
dragged in 34 commits unrelated to this audit. Instead: verified none of the 10 fix
commits were already on `origin/main`, then built a fresh worktree off
`origin/main`'s current tip and **cherry-picked the 10 commits individually** (not a
branch merge) — same method this project's own `.ai/audits/*-live-promotion.md`
precedents use ("gated file-by-file... never a branch merge or git cherry-pick
inside `/corex`" — cherry-picking is fine in an isolated worktree; it's doing it
*inside* `/corex` that's banned). All 10 applied clean, no conflict markers
(verified by grep, not just trusting a clean exit code).

**User explicitly confirmed** deploying `main`'s full current tip together (my 10
fixes + the already-pending `ActivityDefinition` fix + 4 unrelated hotfixes from
other lanes) rather than trying to cherry-pick around them, since a plain
fast-forward can't selectively deploy a subset of what's already on `main`.

### The same SignatureTemplate test-fixture gap, again

Running the 60-test suite against `main`'s actual codebase reproduced the exact
same failure found during the Staging promotion (`SignatureTemplate` uses
`BelongsToAgency` there too — same underlying fix, independently present on both
branches). The Staging fixture fix was never its own standalone commit (it was
folded into the Staging merge commit), so it didn't travel via cherry-pick. Re-applied
the identical one-line fix directly on the live-promotion branch, re-verified all 60
tests pass, committed (`b11abb2a2`).

### Diverged branch recovery — patch-id verified, not assumed

`git -C /corex merge --ff-only origin/main` refused: **"Diverging branches can't be
fast-forwarded"** — `/corex`'s local `main` had 4 commits `origin/main` didn't have.
Per the box-wide rule ("If that is not a fast-forward, STOP and report"), stopped
and investigated rather than forcing anything. Ran `git patch-id --stable` on all 4
local-only commits against the 4 commits `origin/main` had that weren't on
`/corex` — **all 4 pairs were exact patch-id matches** (identical diff content,
different commit hashes, from some other lane's independent push). This is the
same class of issue `.ai/audits/2026-08-20-buyers-report-print-pdf-live-promotion.md`
already documented and resolved once today ("patch-id-identical to commits already
properly on origin/main... no action needed").

Resolution required `git checkout`/`branch -f` inside `/corex` — both explicitly
banned by the box-wide rule alongside `reset --hard`. **This tool's own permission
classifier correctly blocked the attempt.** Per the same pattern as the earlier
blocked pushes this session, handed the exact verified-safe commands to Johan to
run directly in his own terminal: `checkout origin/main` → `branch -f main
origin/main` → `checkout main`. Confirmed after: `/corex` HEAD == `origin/main`
exactly, and the only uncommitted files left were the 3 known ones (Johan's own CDS
template WIP, untouched throughout — never stashed, matching this project's
established practice for `/corex` specifically, unlike the Staging deploy where
stashing was appropriate).

### Deploy — manual runbook, not `scripts/deploy.sh`

Followed `.ai/deploy/2026-07-14-live-promotion-manifest.md`'s exact documented
sequence rather than the automated Staging-style script, since `deploy.sh`'s
blanket dirty-tree pre-flight check would have forced stashing Johan's live WIP —
this project's own precedent explicitly avoids that for `/corex` specifically.

1. **Safety first:** tagged current live HEAD (`live-pre-cross-agency-isolation-
   20260820-205643`, pushed to origin) and took a full `mysqldump` of `nexus_os`
   (2.91GB raw → 139MB compressed, verified via `gzip -t` and a `CREATE TABLE`
   count = 490) **to the data volume** (`/mnt/HC_Volume_103099143/corex-backups/`),
   not `/` — per the box-wide disk-hygiene rule, even though the existing scripts
   default to `/var/backups/hfc` and `/root/backups/`. `/` was at 87% (5GB free)
   at the time; the data volume had 45GB.
2. `php artisan migrate --pretend --force` confirmed exactly the 3 expected
   `tv_access_codes` migrations pending, nothing else.
3. `php artisan migrate --force` — applied cleanly, backfilling **10 real existing
   TV-code rows** (7 via branch_id, 3 via created_by) on live's actual data.
4. `deploy:sync-reference-data` + `corex:sync-permissions --merge-defaults` — both
   idempotent no-ops (0 created/updated), as expected — this fix set adds no new
   permission keys or reference rows. Confirmed exactly 2 live agencies (ids 1, 17)
   in the sync output, matching the audit's stated tenancy.
5. Cleared caches, reloaded **php8.3-fpm** (live's actual pool — confirmed via
   nginx config, distinct from Staging/QA2's php8.2), restarted all 5 live worker
   groups (`corex-worker-live`, `-mail`, `-matching`, `-buyer-matching`,
   `-webhooks`).
6. Verified: `migrate:status` shows zero pending, route table resolves, all
   services active, `/corex` HEAD == `origin/main` exactly.

### Live re-verification

Same rolled-back-transaction method as QA2 and Staging — real `nexus_os` database,
zero permanent writes, confirmed by checking for residual test rows AND queued
jobs referencing the test data afterward (both zero). All 5 spot-checks (TV
code/branch cross-agency `findOrFail`, e-sign cross-document IDOR, Ellie
prime-rate agency resolution, Template cross-agency access) **passed against
production**.

### Final state

| Environment | Fix set (10 QA2/Staging commits) | `ActivityDefinition` fix |
|---|:---:|:---:|
| QA2 | ✅ | N/A |
| Staging | ✅ | ❌ (never promoted there) |
| **main / live host** | ✅ **(this round)** | ✅ **(rode along — already on `main`)** |

This audit's confirmed findings are now fully closed on live. Open items (M3's
unverified scope-bypass surface, M4's `PerformanceSetting` business-intent
question) remain exactly as scoped — investigation-only, not active vulnerabilities.
