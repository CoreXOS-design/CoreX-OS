# Oversight-scope collision hunt (2026-09-14)

**Trigger:** while building Core Matches (`.ai/specs/core-matches.md`), found that `Contact`
carries its own unrelated `ContactScope` (driven by the Contacts module's own data-scope
permission), which silently re-narrowed a manager's `core_matches.all_view` oversight query to
whatever their *personal* Contacts visibility happened to be — fixed in that build. The conductor
asked: does the same collision class exist anywhere else that matters? This is that hunt.

**Status:** investigation only. Nothing outside Core Matches has been changed. Per explicit
instruction ("report, do not fix — that is with Johan and he has not ruled"), the items below are
reported, not touched.

---

## The collision class, in general terms

A model carries a global scope that filters rows by role/permission (e.g. `ContactScope`,
`BranchScope`, `DealBranchScope`). Separately, some *other*, unrelated permission is supposed to
grant a manager/oversight-style wider view over the same data. If the code that grants the wider
view doesn't also bypass (or the underlying scope doesn't also relax for) the first scope, the
manager silently sees less than the permission they were granted promises — no error, no warning,
just fewer rows. This is exactly the bug the Core Matches `ContactScope` fix corrected.

## Every global scope found

| Scope class | Applies to (via) | Permission it reads | Bypass convention |
|---|---|---|---|
| `App\Models\Scopes\AgencyScope` | Any model using `BelongsToAgency` (~90 models) | none — tenant wall | not a collision candidate (tenant isolation, not role-based) |
| `App\Models\Scopes\ContactScope` | `Contact` only (`Contact.php:32`) | `PermissionService::getDataScope($user,'contacts')` | per-call-site `withoutGlobalScope(ContactScope::class)` — its own docblock names this as the sanctioned "admin oversight query" escape hatch |
| `App\Models\Scopes\BranchScope` | Any model using `BelongsToBranch` (`User`, `Property`, `ContactMatch`, `CommandTask`, `ProspectingListing`, `OutreachQueue`, `DealV2`, `LeaveApplication`, `CommissionLedger`, `DailyActivity`, `Rental`, `Target`, `Worksheet`, `DealMoneyLine`, …) | `branches.view_all`, further gated on the agency's `split_branches_enabled` | docblock recommends granting `branches.view_all` to the role that needs it, not a per-call bypass |
| `App\Models\Scopes\DealBranchScope` | `Deal` only (`Deal.php:19`) | `branches.view_all` (same as BranchScope, pivot-based) | same convention as BranchScope |
| `App\Models\Scopes\LivePropertyScope` | `CommandTask`, `CalendarEvent` | none — hides rows whose linked property is soft-deleted | not permission-based, not a collision candidate |

## Genuine bug instances found (same class as the fixed Core Matches bug)

**A. `Contact`/`ContactScope` collision — Command Center Oversight dashboard**
- `app/Services/Oversight/OversightService.php:290` (`staleLeads()`): `Contact::query()->whereIn('created_by_user_id', $agentIds)->...`, no `withoutGlobalScope(ContactScope::class)` anywhere in the file.
- Oversight gate: `app/Http/Controllers/CoreX/Dashboard/OversightController.php:21,46,95,119` — `dashboard.oversight.view`/`.manage`, and `roles.oversight_scope` (`OversightService.php:27`, enum `branch|agency`) — both completely independent of the `contacts.view` data-scope `ContactScope` reads.
- Effect: a manager whose role has `oversight_scope='agency'` but whose own personal `contacts` data-scope is `own`/`branch` gets a silently truncated "stale leads" list for agents legitimately in their oversight scope — identical failure mode to the fixed bug.

**B. `OversightService::agentsInScope()` itself — the most exposed instance**
- `OversightService.php:24-40`: `User::query()` (line 29), narrowed by `branch_id` only when `scope==='branch'`. `User` carries `BranchScope` via `BelongsToBranch`. If `oversight_scope='agency'` but the manager lacks `branches.view_all` and the agency has `split_branches_enabled=true`, `BranchScope` silently narrows the *agent list itself* to the manager's own branch before any of the seven oversight categories even run — no bypass anywhere in the file.
- The same file also queries `Deal::query()` (line 140, collides with `DealBranchScope`), `Property::query()` (lines 172, 201, collides with `BranchScope`), and `CommandTask::query()` (line 235, collides with `BranchScope`) — none bypassed.
- Role Manager never auto-implies `branches.view_all` for `oversight_scope`/`dashboard.oversight.*` — the only auto-implication that exists anywhere is `branches.edit_all → branches.view_all` (`RoleManagerController.php:287-289`).

**D. Same "all → no extra narrowing" pattern repeated across four other modules** (confirmed: `scopeVisibleTo('all')` returns the query unmodified, relying on `BranchScope`/`DealBranchScope` to already be lenient, which only happens with a separate `branches.view_all` grant; no bypass in any of these controllers):
- **Market Intelligence** canvassing pool — `app/Http/Controllers/CoreX/MarketIntelligenceController.php:126-130` (`market_intelligence.view`) against `ProspectingListing` (`ProspectingListing.php:15`, `scopeVisibleTo` at 167-181).
- **Outreach & Canvassing** — `app/Models/Outreach/OutreachQueue.php:122-135` (`outreach_canvassing.view`), `OutreachQueue.php:29` carries `BranchScope`. Controller: `app/Http/Controllers/CoreX/OutreachCanvassingController.php:43`.
- **Command Center Tasks** — `app/Models/CommandCenter/CommandTask.php:129-143` (`command_center.tasks.view`), `CommandTask.php:18` carries `BranchScope`. Called from `app/Http/Controllers/CommandCenter/TaskController.php:35-36,195`.
- **Deals V2 pipeline** — `app/Models/DealV2/DealV2.php:300-323` (`deals_v2.view`), `DealV2.php:24` carries `BranchScope`. Called from `app/Http/Controllers/DealV2/DealV2Controller.php:148` (and others).

**Nuance on B/D:** unlike `ContactScope`, `BranchScope`'s own docblock frames `branches.view_all` as the one intended universal bypass, and the identical "all → no extra narrowing" pattern recurs in five independent models — this may be a deliberate architectural convention rather than five separate oversights. But nothing in Role Manager surfaces or enforces the required pairing, so the observable symptom for an agency admin configuring roles is indistinguishable from the `ContactScope` bug: tick the module's "all" permission, get no error, silently see less than promised. **This is a product/architecture call for Johan** — wire explicit bypasses (matching the Core Matches fix), or make Role Manager auto-imply `branches.view_all` wherever these permissions are granted.

## Sibling Core Matches surfaces — checked, not affected

- `app/Http/Controllers/Api/MobileCoreMatchController.php` — every endpoint hard-scoped to `created_by_user_id === $user->id` (`authorizeMatch()`, line 242-245); no manager/"all view" path exists here at all.
- `app/Services/Matching/CoreMatchListPdfService.php` — builds a PDF for one already-resolved contact/match pair, not an oversight-wide export; no scope logic to collide.

## Checked and correctly designed — no action needed

- `app/Http/Controllers/CommandCenter/BuyerPipelineController.php` + `app/Services/CommandCenter/BuyerPipelineScope.php` — explicit three-layer design; the `'agency'` branch adds no filter *by design* ("Layer 2 controls access") — this feature never promises visibility beyond the user's own Contacts-module scope, so `ContactScope` narrowing it is intended, not a bug.
- `app/Http/Controllers/Compliance/WhistleblowController.php:27-30` — `WhistleblowComplaint` only carries `BelongsToAgency` (plain tenant wall), no unrelated role-based scope in play.
- `app/Http/Controllers/Leave/LeaveApplicationController.php` — no separate "all-view" permission; visibility is governed entirely by `BranchScope`'s own `branches.view_all` — the same permission the scope itself checks, no mismatch.
- `app/Models/CommandCenter/CalendarEvent.php` and `app/Models/Prospecting/TrackedProperty.php` — neither uses `BelongsToBranch`; both implement their own inline `branches.view_all`-consistent checks.
- `app/Http/Controllers/Dr2/DealRegisterController.php:1105` — already correctly does `Contact::withoutGlobalScope(ContactScope::class)` for its contact picker.

## Not fully traced — flagged honestly, not guessed

- `app/Http/Controllers/Dr2/DealRegisterController.php` (1600+ lines) and its siblings `DealSettlementController.php`, `DealDistributionController.php` — one `ContactScope` bypass spot-checked as correct, but not every `deals.view`/`deals.edit`-gated query was exhaustively checked against `Deal`'s `DealBranchScope` for the same "all-view expects wider than BranchScope allows" gap. Worth a dedicated follow-up pass before calling these three files clean.
- Whether any role in the live database actually holds one of the mismatched permission combinations (e.g. `oversight_scope=agency` + no `branches.view_all`, or a module's `.all_view`-style permission + no `branches.view_all`) has not been checked — that determines whether B/D are live-exploitable today versus latent. Data question, not a code question.

## Disposition

Item C in the table below (Core Matches itself) is the only one in scope for the cc3 Core Matches
build and has been fixed and re-verified separately (`.ai/specs/core-matches.md`, "The ContactScope
trap" section). **Items A, B, and D above are explicitly out of scope for that build and have not
been touched** — reported here for Johan to rule on, per the conductor's instruction.
