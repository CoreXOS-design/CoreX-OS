# Agency/Branch-context assumption — class audit (2026-07-13)

**Class:** code assuming an agency/branch-bound acting user. Breaks for super-admins
(`agency_id = NULL` → `effectiveAgencyId()` returns NULL), console/job/webhook/public
contexts, and null relations. Two confirmed live 500s today: **AT-241** (super-user
calendar) and **MIC** (`Call to effectiveAgencyId() on null`, MarketIntelligenceController:64).

**Canonical safe pattern:** `.ai/STANDARDS.md` **Rule 17**.
- Reads: `forAgency((int) ($user->effectiveAgencyId() ?: 0))` — `0` routes to a `<= 0` guard
  returning unsaved defaults (never `?? 1`, which assumes agency 1 exists → FK-1452 / wrong tenant).
- Null receiver: `$x->relation?->effectiveAgencyId()` (never bare chain).
- Writes: derive agency from the domain object, or persist NULL (nullable col only), or reject —
  never stamp hardcoded `1` or sentinel `0` into a NOT-NULL/FK column.

**Ownership:** calendar instances fixed by m1 on branch `AT-241` (`64968491`). MIC = m2 (in progress).
**Everything below (minus those) = the single class-fix ticket.**

---

## HIGHEST-LEVERAGE FIX (do first)
`app/Models/CommissionSetting.php:90` — `forAgency($id)` is a plain `firstOrCreate(['agency_id'=>$id])`
with **NO `<= 0` guard** (unlike `AgencyContactSettings::forAgency`). Its callers (Commission*, SettingsController)
currently rely on `?? 1` to avoid a 1452. **Add the same `if ($agencyId <= 0) return (new self())->forceFill($defaults);`
guard** — then the callers below can switch `?? 1` → `?: 0` safely.

---

## SECTION 1 — Hardcoded nonzero agency fallback (`?? 1` / `?: 1`)

### 1a · HIGH — WRITE / firstOrCreate stamped under the wrong (or absent) agency
| file:line | verbatim | use |
|---|---|---|
| FeedbackReportController.php:56 | `'agency_id' => auth()->user()->effectiveAgencyId() ?? 1,` | insertGetId |
| Training/TrainingController.php:138 | `$validated['agency_id'] = $user->effectiveAgencyId() ?? 1;` | create |
| Onboarding/OnboardingController.php:89 | `$validated['agency_id'] = $user->effectiveAgencyId() ?? 1;` | create |
| Middleware/LogsContactAccess.php:40 | `'agency_id' => $user->effectiveAgencyId() ?? ($user->agency_id ?? 1),` | ContactAccessLog::create |
| CoreX/PropertyController.php:913 | `$agencyId = auth()->user()->effectiveAgencyId() ?? 1;` | creates contacts + stamp |
| Api/ProspectingApiController.php:46 | `$agencyId = $user->effectiveAgencyId() ?? $user->agency_id ?? 1;` | ProspectingSearch create |
| Api/ProspectingApiController.php:346 | (same) | dedupe-then-create |
| Commission/CommissionSettingsController.php:69 | `$agencyId = $user->effectiveAgencyId() ?? 1;` → forAgency->update | mutates wrong agency's settings |
| Services/CommissionCalculationService.php:30 | `$agencyId = $user->effectiveAgencyId() ?? 1;` | cap-period create |
| Commission/CommissionController.php:22 | `$agencyId = $user->effectiveAgencyId() ?? 1;` | get-or-create cap period |
| Commission/CommissionController.php:147 | (same) | CommissionSetting::forAgency firstOrCreate |
| Commission/RevenueShareController.php:14 | (same) | CommissionSetting::forAgency firstOrCreate |
| CoreX/SettingsController.php:153 | `CommissionSetting::forAgency($user?->effectiveAgencyId() ?? 1);` | firstOrCreate |
| CommandCenter/BuyerPipelineController.php:164 | `$agencyId = $user->effectiveAgencyId() ?? 1;` → forAgency | firstOrCreate |
| _CalendarController.php:1984_ | forAgency(... ?? 1)->calendarMaxExpansionDays() | **FIXED by m1 (AT-241)** |

### 1b · MED — `$model->agency_id ?? 1` on insert (col NOT-NULL → rarely fires; normalise to `?? 0`)
BuyerStateService.php:59, 72, 114 · BuyerDetailController.php:264, 310, 332 ·
ContactAccessLogObserver.php:26 · PropertyAuditService.php:26, 77 ·
_CalendarController.php:1182_ (`$calendarEvent->agency_id ?? 1`, m1-domain) ·
_CalendarEventService.php:436_ (`$user->agency_id ?: 1`, m1-domain — write, see note).

### 1c · MED — READ/query resolves to agency 1 for a null-agency user (leaks wrong-agency data)
CommandCentreService.php:44, 137, 164, 1417 · Commission/CommissionController.php:295 ·
FeedbackReportController.php:125, 166 · Training/TrainingController.php:19, 106 ·
CommandCenter/ReportingController.php:30, 45(branch ?:1), 55 · DuplicateCleanupController.php:14 ·
Compliance/AgentComplianceController.php:19 · CoreX/PropertyContactController.php:172 ·
CoreX/ContactController.php:505, 639 · Docuperfect/ESignWizardController.php:835 ·
Presentation/PresentationController.php:353 · Presentation/PresentationSnapshotController.php:53 ·
CoreX/MarketIntelligenceController.php:64, 677, 2230, 2242, 2274, 2290 **(MIC — m2)** ·
BuyerStateService.php:26, 232 · BuyerDetailController.php:259 · BuyerPipelineController.php:53 ·
ContactGovernanceController.php:19 (`return $agencyId ?? 1;`) ·
_CalendarController.php:2497_ + _CalendarEventService.php:639_ (`$user->agency_id ?: 1`, m1-domain read).

### 1d · LOW — hardcoded fallback but guarded by a prior real fallback
BuyerPortalController.php:35, 105 · Compliance/WhistleblowComplaintService.php:724 (`?? first()` catches miss).

---

## SECTION 2 — Sentinel `?? 0` / `?: 0` (intended-safe) — 97 sites; sample traced SAFE
OutreachCanvassingController:38 (abort_if <=0) · PresentationAnalyticsController:39 (abort_unless) ·
PdfSplitterController:330 (>0 ternary) · RecurrenceExpander:64 + CalendarController:465/1384 (forAgency guard) …

### 2 · FLAG — latent UNGUARDED sentinel write (belongs in the ticket)
`app/Http/Controllers/DealV2/SupplierDirectoryController.php:51` —
`$this->service->findOrCreate((int) $request->user()->effectiveAgencyId(), $data, ...)`. Null
`effectiveAgencyId()` casts to **0**; `AgencyServiceProvider::findOrCreate` has NO `<= 0` guard →
null-agency user → `firstOrCreate(agency_id=0)` → **FK-1452 / 500**. HIGH if reachable.

---

## SECTION 3 — Null-receiver accessor calls (`->effectiveAgencyId()` on possibly-null)

`effectiveAgencyId()` / `effectiveBranchId()` are themselves null-safe (return `?int`, use
`optional()`, User.php:447-471). So "Call to … on null" can ONLY come from a **null receiver**
(a null `$request->user()` in an unauth path, or a null variable/relation), NOT from the method's
internals. The MIC crash was the historical inline `optional(Branch::find())->agency_id` deref that
`effectiveAgencyId()` now encapsulates — MIC:64 today is auth+permission gated (m2 owns it).

### 3a · MED — genuine in-code null-receiver bugs (NOT middleware-protected)
| file:line | verbatim | why null |
|---|---|---|
| CoreX/PropertyWizardController.php:476-477 | `$agencyId = $agent->agency_id ?? …;` `$branchId = $agent->effectiveBranchId() ?? …;` | `$agent = User::find($agentId)` returns null for a soft-deleted / out-of-scope agent id → both derefs 500. Reachable saving the property wizard. Fix: `$agent?->…` + handle null. |
| DocumentFilingController.php:107 | `(Branch::find($branchId)->name ?? 'Unknown')` | `Branch::find($branchId)` null for soft-deleted/out-of-scope branch → `->name` on null. `??` guards the value, not the object. Fix: `Branch::find($branchId)?->name ?? 'Unknown'`. |

### 3b · SYSTEMIC (LOW today, one route-mistake from HIGH) — the "false-comfort `??`" idiom
~110 `auth()->user()->effectiveAgencyId() ?? 1` sites sit behind `auth`/`sanctum`/`permission:`
middleware, so `user()` is non-null **today** — the `??` guards the result, not the receiver, so any
route mis-registration flips them to 500. Highest-value to harden = **silent JSON/API endpoints**:
`CoreX/MarketIntelligenceController.php:2230, 2242, 2274` (m2's module) and the `Api/*` namespace →
convert to `$request->user()?->effectiveAgencyId() ?? …`. Full ~110-site inventory: see sweep B output.

### 3c · Housekeeping (delete)
`app/Http/Controllers/BM/PerformanceController.php.pre_restore.20260202_132223` — a stray backup
controller committed to the tree (not autoloaded). Soft-delete/remove it.

---

## BATCH-TICKET SCOPE (everything here minus m1-calendar[done] & m2-MIC)
1. **Add the `<= 0` guard to `CommissionSetting::forAgency()` (model:90)** — unblocks safe `?? 1`→`?: 0` for all Commission/Settings callers. _(do first)_
2. Section **1a** HIGH writes → derive-from-context or `?: 0`+guard (never `?? 1` into NOT-NULL agency_id).
3. Section **1c** MED reads → `?: 0` (post-guard) so null-agency users don't silently read agency-1 data.
4. Section **1b** MED `$model->agency_id ?? 1` inserts → normalise to `?? 0`.
5. **SupplierDirectoryController:51** unguarded sentinel write → guard `AgencyServiceProvider::findOrCreate` on `<= 0`.
6. Section **3a** null-receiver derefs (PropertyWizard:476-477, DocumentFiling:107) → `?->`.
7. Section **3b** harden the silent JSON/API `??`-chains to `?->`.
8. Delete the `.pre_restore` backup controller (3c).
