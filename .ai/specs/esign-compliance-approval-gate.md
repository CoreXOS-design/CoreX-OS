# E-sign Compliance Approval Gate + Officer Notifications — Spec

**Status:** Approved for build (all business decisions ruled 2026-09-14)
**Raised by:** Elize (HFC compliance)
**Rulings by:** Andre Roets, 2026-09-14 (recorded in the "Compliance Approval Gate" briefing, rev 4)
**Author:** Claude (senior engineer) from the code-verified briefing
**Date:** 2026-09-14
**Governing doctrine:** `.ai/specs/ESIGN-CANON.md` (canon by Johan's order 2026-07-15). Design reference for the
gate itself: `.ai/specs/esign-v3-complete-spec.md` §4.3 step 6 and §4.5 (`STATUS_APPROVAL_PENDING`).
**Branch:** `esign-compliance-gate` (off `QA2`, Staging merged in). Lands on QA1 only (Non-negotiable #5).

---

## 1. What this does and why (business requirement)

Today a full-status practitioner sends any e-sign document to any outside party with no approval at all.
Elize wants every e-sign document to stop at a compliance officer before it leaves the agency, the way a
FICA pack already does — and the officers need to be told what is waiting, in one place.

Three things ship together:

1. **The gate.** An agency chooses one of two e-sign approval routes. On the RO / CO route every document
   is held at the moment it would leave the agency until a Reporting Officer approves it; the Compliance
   Officer can override any decision.
2. **Officers per module.** Company Settings gets an RO / CO section for e-sign and another for compliance
   reporting (whistleblow), beside FICA's. One CO, any number of ROs, per module.
3. **Telling the officers.** Bell notifications through the AT-235 gateway, sidebar badges (house style), an
   "Approvals" hub page with three separately counted groups, and a polling toast for new items.

Alongside, compliance reporting gets the ruled expansion (ROs may send onward to the PPRA when the agency
allows it), submissions notify the approvers, "changes requested" actually notifies the filer, and the
report page applies the same own / branch / all visibility rule as the list.

### Why it makes CoreX *best*, not merely *working*

The system is the compliance officer's deputy (V3 §1 principle 3). The gate is enforced by the system, the
officers are told without going looking, and the same one-CO-many-ROs shape is used in every module, so an
agency configures it once per module and never learns a second mechanism.

---

## 2. The rulings (business decisions — locked, do not re-litigate)

| # | Question | Ruling |
|---|---|---|
| 1 | Which spec governs? | ESIGN-CANON. V3 draft is the design reference for the gate. |
| 2 | Which documents? | **Every e-sign document.** The template already declares its allowed uses (e-sign / download / wet-ink). Anything on the e-sign route is gated when the RO / CO route is on. OTP / sale / deeds are wet-ink only by law and never enter. |
| 3 | Officer model? | **One CO + unlimited ROs, per module** (e-sign, compliance reporting), configured in Company Settings. FICA is the worked example of RO / CO behaviour, **not** the design to copy line by line. |
| 4 | No officer? | Ruled out as a scenario. Engineering call: the RO / CO route cannot be switched on without an e-sign CO; the last e-sign CO cannot be ended while the route is on. |
| 5 | Who sees what? | The standing CoreX rule: **agent sees own, branch manager sees branch, admin sees all.** Governs officer queues and reading compliance reports. |
| 6 | Switchable? | Yes, one agency setting with two routes. **Route 1 (default, legal floor):** full status sends without approval; a candidate always submits to a full-status practitioner for authorisation (law, never off). **Route 2 (HFC):** the RO stands where the full-status practitioner stood — every send waits for an RO; the CO overrides all. |
| 7 | Compliance reporting? | Already: anyone files, only the CO sends to the PPRA. Expansion: an agency option to let ROs send onward too. |
| 8 | Approvals go stale? | **No.** An approval never expires. |
| 9 | Documents in flight? | **Untouched** when route 2 switches on. Only new sends are gated. |
| 10 | Candidate on route 2? | The RO who approves a candidate's document **must be full status**, and that approval **is** the legal co-signature. One approval, one signature. |

---

## 3. Pillar connections

| Pillar | Reads | Writes back |
|---|---|---|
| **Agent** (`User`) | officer appointments, designation (full status), branch, data scope | `officer_appointments` rows; notifications |
| **Contact** | signing parties on the held document (for the queue row) | — |
| **Property** | property on the held document (queue row) | — |
| **Deal** | — (a held mandate delays the deal; surfaced via existing document state) | — |

Documents pillar (`signature_templates`) gains the `approval_pending` / `approval_declined` states and an
`esign_approvals` decision ledger. Cross-pillar reactivity uses domain events (Non-negotiable #9):
`App\Events\Esign\ComplianceApprovalRequested`, `ComplianceApprovalDecided`,
`App\Events\Compliance\WhistleblowReportSubmitted`, `WhistleblowReportReturned` — all auto-audited via
`AbstractDomainEvent` → `RecordDomainEvent`.

---

## 4. Current state (verified in code 2026-09-14)

- **Dispatch.** The agent signs first, in-app. The release to the first outside party happens in
  `SignatureService::handlePartyCompletion()` (agent branch, `:1653-1664`): `chainHasAuthoriser()` →
  `advanceToSupervisor()` (candidate) else `advanceToNextParty()` (plain). The direct "send for signature"
  path `SignatureService::sendForSigning()` (`:1134`) advances immediately only when the agent pre-signed.
- **Candidate supervision** (PPA §35) is a real co-signature inside the ceremony: `supervisor` party injected at
  `ESignWizardController.php:3447-3470`; pool = `CandidatePractitionerService::getEligibleAuthorisers()` (branch
  BMs + branch full-status + agency admins); reciprocal `canAuthoriseFor()`.
- **No compliance-officer concept in e-sign.** No `requires_co_approval`, no `approval_pending` anywhere.
- **FICA** = `fica_officer_appointments` (primary CO + MLROs), reviewer/officer queues, referral with reason,
  `NotificationDispatcher::send('fica.referred_to_co', …)`.
- **Whistleblow** = `whistleblow_approver_user_ids` JSON list on `agencies` (+ role fallback); `submit()` fires
  nothing; `requestChanges()` flashes "agent has been notified" and sends nothing; `show()` has no scope check
  while `index()` narrows to own unless `view_all_agency`.
- **Sidebar** badges: 12 live counts; FICA has none; Compliance Reporting has one.
- **No real-time layer.** Reminder toast polls 60s (`components/reminder-toast.blade.php`); bell loads on page.

---

## 5. Data model / migrations

### 5.1 `officer_appointments` (new) — per-module RO / CO registry

```
id, agency_id (FK agencies, NOT NULL), branch_id (FK branches, nullable — informational stamp of the user's
branch at appointment; scope is decided by the data-scope rule, not this column),
user_id (FK users, nullable on delete), module VARCHAR(32) ('esign' | 'whistleblow'),
role VARCHAR(16) ('ro' | 'co'), full_name, email (historical copies), appointed_on DATE, ended_on DATE null,
appointed_by (FK users null), notes TEXT null, timestamps, softDeletes
idx (agency_id, module, role, ended_on); idx (user_id, module, ended_on)
```
Model `App\Models\Compliance\OfficerAppointment` — `BelongsToAgency`, `SoftDeletes`. `booted()`: creating a
`co` auto-ends the incumbent CO of the same agency + module (one CO invariant, mirrors
`FicaOfficerAppointment`). Scopes `active()`, `forModule()`, `co()`, `ro()`.
Service `App\Services\Compliance\OfficerRegistry`: `currentCo($agencyId,$module)`, `activeRos(...)`,
`officers(...)` (CO + ROs as Users), `isCo(User,$module,$agencyId)`, `isRo(...)`, `isOfficer(...)`,
`saveRos(...)` (diff-set, never deletes), `appointCo(...)`, `endCo(...)` (guarded).
FICA's own table is **not** migrated — FICA is untouched.

### 5.2 `agencies` (two columns)

- `esign_approval_route VARCHAR(16) NOT NULL DEFAULT 'full_status'` — `'full_status' | 'ro_co'`.
- `whistleblow_ro_can_submit BOOLEAN NOT NULL DEFAULT false` — ROs may approve and send onward to the PPRA.

### 5.3 Backfill — whistleblow approvers → appointments (same migration as 5.1)

For each agency with a non-empty `whistleblow_approver_user_ids`: first id → `co`, remaining ids → `ro`, and
`whistleblow_ro_can_submit = true` when there was more than one (preserves today's behaviour: everyone who
could send still can). The JSON column is left in place (retention) but is **no longer read** by any gate.

### 5.4 `signature_templates.status` enum → 28 values

Additive `ALTER TABLE … MODIFY` following `2026_08_06_000001_*` verbatim: + `'approval_pending'`,
`'approval_declined'`. `down()` parks both on `'ready'`.
`SignatureTemplate::STATUS_APPROVAL_PENDING`, `STATUS_APPROVAL_DECLINED`.

### 5.5 `esign_approvals` (new) — decision ledger, one row per hold

```
id, agency_id, signature_template_id (FK), document_id (FK docuperfect_documents), requested_by_user_id,
status VARCHAR(16) ('pending' | 'approved' | 'declined'), decided_by_user_id null, decided_at null,
decision_note TEXT null, is_override BOOL default false, timestamps, softDeletes
idx (agency_id, status); idx (signature_template_id, status)
```
Model `App\Models\Docuperfect\EsignApproval` — `BelongsToAgency`, `SoftDeletes`.

### 5.6 `notification_event_types` (seeder rows — `NotificationEventTypeSeeder`, syncable)

`esign.approval_requested`, `esign.approval_decided` (pillar `document`, group Compliance),
`whistleblow.report_submitted`, `whistleblow.changes_requested`, `whistleblow.rejected` (pillar `contact`,
group Compliance). All in-app + email, no push.

Schema snapshot: re-run `DB_DATABASE=hfc_dash_test php artisan schema:dump` + DEFINER strip (perl) and commit.

---

## 6. The gate — behaviour

### 6.1 Where it sits
One private helper `SignatureService::complianceGateHolds(SignatureTemplate, string $completedParty): bool`
called at exactly two points:
1. `handlePartyCompletion()` agent branch — **before** the `chainHasAuthoriser()` check (`:1658`).
2. `sendForSigning()` — the "agent pre-signed" branch, **before** `advanceToNextParty()` (`:1172`).
It returns true (and holds) when `EsignApprovalService::gateApplies($template)`:
`agency.esign_approval_route === 'ro_co'` **and** `!chainHasAuthoriser($template)` (candidate documents are
gated by the supervisor step instead — §6.5). `sendForSigning()`'s status guard additionally accepts
`approval_pending` / `approval_declined` so a release can re-drive it.

### 6.2 Hold
`EsignApprovalService::hold()` — inside the caller's transaction:
- status → `approval_pending`; `document_hash` refreshed;
- `esign_approvals` row `pending`, `requested_by_user_id = template.created_by` (the sender);
- `SignatureAuditLog::log(… 'compliance_approval_requested', ACTOR_SYSTEM …)`;
- event `ComplianceApprovalRequested`; listener `NotifyOfficersOfApprovalRequest` (sync, idempotent by
  approval id) sends `SignatureActivityNotification::complianceApprovalRequested()` through
  `NotificationDispatcher::send($officer, 'esign.approval_requested', $approval, …)` to every e-sign officer
  (CO + ROs) whose data scope covers the document (§6.4). Sender excluded.
- Nothing is emailed to any outside party. No PDF. The ceremony link for external parties is not issued.

### 6.3 Decide
`EsignApprovalService::approve(template, officer, ?note)` / `decline(template, officer, reason)`:
- Officer must be an active e-sign RO or CO of the document's agency **and** the document must be inside the
  officer's data scope. Else 403 with a plain message.
- **Self-approval** is blocked for the requester (the sender) unless they are the CO (FICA's primary-CO rule).
  The blocked attempt is audited (`compliance_self_approval_blocked`).
- Approve: ledger row → `approved`, `decided_by/at`; audit `compliance_approved`; release =
  `SignatureService::releaseAfterComplianceApproval()` which runs the exact code the gate pre-empted
  (`chainHasAuthoriser` → `advanceToSupervisor`, else `advanceToNextParty(template,'agent')`). Sender notified
  (`esign.approval_decided`).
- Decline: reason **required** (min 3 chars); status → `approval_declined`; audit `compliance_declined` with
  the reason; sender notified with the reason. The sender's own signature stays intact (signed stays signed,
  the returned-doc doctrine). The sender may **Request approval again** (→ `approval_pending`, new ledger
  row) or **Cancel** (existing cancel path).
- **CO override:** a CO may approve a `approval_declined` document; reason required; ledger `is_override=true`;
  audit `compliance_override_approved`. "CO overrides all" is exactly this plus the CO's self-approval exemption.
- Nothing expires (ruling 8). Documents already past the gate on the day the route switches on are untouched
  (ruling 9) — the gate only fires on the two dispatch points above.

### 6.4 Visibility (ruling 5)
New permission key `esign_approvals.view` (type access, module `esign_approvals`) with default scopes
`admin=all`, `branch_manager=branch`, `agent=own` via `scope_defaults`. `EsignApproval::scopeVisibleTo()`
copies `CommandTask::scopeVisibleTo()`: `all` → untouched; `branch` → `signature_templates.branch_id` (via
the creator's branch stamped on the approval row as `branch_id`) equals the officer's effective branch;
`own` → `requested_by_user_id` is the officer. Consequence (stated in the briefing, accepted): an RO who is an
ordinary agent sees only their own documents; ROs are in practice branch managers or admins.

### 6.5 Candidate documents on route 2 (ruling 10)
`CandidatePractitionerService::getEligibleAuthorisers()` and `canAuthoriseFor()` consult the candidate's
agency route. On `ro_co` the pool is **e-sign officers (RO or CO) who are full status** (`isFullStatus()`),
still subject to the existing branch rule (admin agency-wide, otherwise the candidate's branch). Their
co-signature inside the ceremony **is** the approval; no second `approval_pending` hold is created. Route 1
is byte-for-byte today's pool. The existing "no eligible authoriser" `RuntimeException` becomes a
user-facing message at the wizard: "No full-status Reporting Officer is appointed for e-sign — appoint one
under Company Settings › Compliance officers, or switch the approval route."

### 6.6 Documents already gated, and the two "nobody home" guards (ruling 4)
- `saveEsignRoute('ro_co')` is refused with a plain message when the agency has no active e-sign CO.
- `endCo('esign')` is refused while `esign_approval_route === 'ro_co'`. Appointing a **new** CO is always
  allowed (auto-ends the previous).

---

## 7. Officers per module — Company Settings

Section **Compliance officers** (existing FICA section, `settings.blade.php:1077-1245`) gains two sibling
accordions in the same pane, same markup shape:

**E-sign approval** — (a) route radio: *Full-status practitioners send without approval (default)* /
*Reporting Officer approves every send; the Compliance Officer overrides*; (b) CO select (one) with
appointed-on date; (c) RO checkbox list. Warning banner when route 2 is on and no CO / no RO.
**Compliance reporting** — (a) CO select; (b) RO checkbox list; (c) toggle *Reporting Officers may also send
reports onward to the PPRA*. The existing "Approval Authority" checkbox list in the Whistleblower section is
**removed** (replaced by this); the CC email + tier recipients + lawyer pack stay.

Controller `App\Http\Controllers\Compliance\OfficerAppointmentsController`: `saveCo(module)`,
`saveRos(module)`, `saveEsignRoute`, `saveWhistleblowSubmitPolicy`. All gated `permission:manage_compliance_officer`
(the FICA officers key — same administrator). All user ids validated with an **agency-scoped exists** rule.
Boolean writes guarded by `_present` markers + `$request->has()` (§6.1 of the onboarding spec).

Routes (`routes/web.php`, beside the FICA officer routes ~3017):
```
POST /settings/officers/{module}/co     corex.settings.officers.co
POST /settings/officers/{module}/ros    corex.settings.officers.ros
POST /settings/esign-approval-route     corex.settings.esign-approval-route
POST /settings/whistleblow-submit-policy corex.settings.whistleblow-submit-policy
```
`{module}` constrained to `esign|whistleblow`.

### 7.1 Setup Wizard (Non-negotiable #10a)
The existing `compliance` step (`config/agency-onboarding-copy.php` ~506, partial
`agency-setup/steps/compliance.blade.php`) renders: e-sign route (radio, explain + affects), e-sign CO select +
RO checkboxes, compliance-reporting CO select + RO checkboxes + the RO-may-send toggle. Savers added:
`OfficerAppointmentsController@onboardingSave` (one narrow saver that only writes what is posted, every
boolean guarded). The wizard's old approver checkbox list is removed with the settings-page control.

---

## 8. Telling the officers

### 8.1 Bell
All through `NotificationDispatcher::send()` with the event keys in §5.6. Payloads carry `title`, `body`,
`action_url` (the keys the header bell reads). `SignatureActivityNotification` gains factories
`complianceApprovalRequested()` and `complianceApprovalDecided()`; new
`App\Notifications\Compliance\WhistleblowReportSubmittedNotification` and
`WhistleblowReportReturnedNotification` (database + mail).

### 8.2 Sidebar (house style — inline count, uncached, per viewer)
- **Approvals** (new, top of the Compliance panel) → `/corex/approvals`; badge = total of the three groups the
  viewer can act on. Gated `@permission('approvals.view')`.
- **FICA** gains a badge = reviewer queue + officer queue for the viewer (reusing the same `visibleTo` queries
  as `FicaController`, via `ApprovalQueueCounts`). FicaController itself is not modified.
- **Documents › Approvals** (new subitem in the Documents panel) → `/corex/documents/approvals`; badge = pending
  approvals in scope.
- **Compliance Reporting** badge stays, now computed by the shared scope rule (§9.3).
All counts from one service `App\Services\Compliance\ApprovalQueueCounts::forUser(User): array`
`['fica'=>['ro'=>n,'co'=>n], 'esign'=>n, 'whistleblow'=>n, 'total'=>n]`.

### 8.3 Approvals hub — `/corex/approvals`
`App\Http\Controllers\Compliance\ApprovalsHubController@index` → `corex/approvals/index.blade.php`. Three
separately labelled, separately counted groups — **FICA**, **Documents awaiting release**, **Compliance
reports** — each with its count, its five newest items and a link to its own queue. Never one merged list.
Empty states explain ("You are not an officer for … / nothing waiting").

### 8.4 E-sign approvals queue — `/corex/documents/approvals`
`App\Http\Controllers\Docuperfect\EsignApprovalController` — `index` (pending + declined in scope, search,
paging), `approve`, `decline`, `override`, `resubmit` (sender). Route middleware `permission:esign_approvals.view`;
decisions re-checked in the service (officer membership + scope). The sender's My Documents page gets an
**Awaiting compliance approval** / **Declined by compliance** section with the reason and the two actions.

### 8.5 Toast — `components/approvals-toast.blade.php`
Copy of `reminder-toast.blade.php`. Polls `GET /api/v1/approvals/pending` (named
`api.v1.approvals.pending`, in the Admin › API catalog) every 60s + on focus. Returns the viewer's actionable
items created in the last 24 hours across the three groups (`{items:[{id,kind,title,body,url,created_at}]}`).
Dismissal is client-side (`localStorage` of dismissed ids); no server "seen" state — honest polling, nothing
promised as instant.

---

## 9. Compliance reporting (whistleblow) changes

### 9.1 Who may decide
`WhistleblowComplaintService::validateApproverPermission()` and `WhistleblowController::canApprove()` collapse
to one rule in `OfficerRegistry`: the whistleblow **CO**; ROs **only when** `whistleblow_ro_can_submit`.
**Absorb:** an agency with no whistleblow CO appointed keeps today's fallback (roles admin / branch_manager /
super_admin) so no existing agency breaks; the settings section shows a warning until a CO is appointed.

### 9.2 Notifications
- `submit()` → event `WhistleblowReportSubmitted` → listener notifies the CO (+ ROs when they may submit)
  whose scope covers the report — `whistleblow.report_submitted`.
- `requestChanges()` → `WhistleblowReportReturned` → filer notified with the approver's notes —
  `whistleblow.changes_requested`. The flash message becomes true.
- `reject()` → same event, `whistleblow.rejected`, reason included.

### 9.3 Visibility (ruling 5)
`WhistleblowComplaint::scopeVisibleTo(User)`: owner or `compliance.whistleblow.view_all_agency` → all
(explicit grant wins); else `PermissionService::getDataScope($user,'compliance.whistleblow') ?? 'own'`:
`branch` → `branch_id = effectiveBranchId()`, `own` → `reported_by_user_id`. Applied to `index()`, `show()`
(404 outside scope) and the sidebar badge. `BranchSplitIsolationTest`'s whitelist is untouched (no global
`BranchScope` is added).

---

## 10. Permissions (Non-negotiable #5)

New keys in `config/corex-permissions.php`:
- `approvals.view` — access — module `approvals` — "See the Approvals hub".
- `esign_approvals.view` — access — module `esign_approvals` — "See documents awaiting compliance approval"
  (scoped: own / branch / all).
Role defaults: admin + branch_manager + agent include both (agents may be officers; `own` scope keeps a plain
agent's view to their own documents). Deciding is by officer appointment, never by permission alone.
Deploy: `php artisan corex:sync-permissions` (config-driven, idempotent).

---

## 11. Input space / prevent-or-absorb (BUILD_STANDARD §2–3)

| Input | Decision |
|---|---|
| Route set to `ro_co` with no e-sign CO | **Prevent** — refused with message naming the fix. |
| End the only e-sign CO while route is `ro_co` | **Prevent** — refused; appoint a replacement or switch route. |
| RO list saved empty | **Absorb** — allowed (CO alone approves). Warning shown. |
| Decline without reason / < 3 chars | **Prevent** — validation message. |
| Officer decides a document outside scope / not an officer / other agency | **Prevent** — 403 plain message; route-model binding 404s cross-agency. |
| Sender approves own document (not CO) | **Prevent** — blocked + audited. |
| Approve twice / stale form | **Absorb** — idempotent: already-released document returns "already approved", no second release. |
| Route switched on with documents mid-flight | **Absorb** — untouched (ruling 9). |
| Candidate on route 2, no full-status RO | **Prevent** — wizard message at send (§6.5). |
| User id posted from another agency | **Prevent** — agency-scoped `exists` rule. |
| Notification gateway throws | **Absorb** — logged, never blocks the hold/decision (FICA precedent). |
| Whistleblow: no CO appointed | **Absorb** — legacy role fallback (§9.1). |
| Deleted sender / deleted officer on a ledger row | **Absorb** — nullable FKs, names rendered from historical copies or "a former user". |

---

## 12. Acceptance criteria

1. Route 1 (default): sending behaves byte-for-byte as today; no `approval_pending` ever appears.
2. Route 2: a full-status agent's document stops at `approval_pending` after the agent signs; no outside party
   receives an invitation; every e-sign officer in scope gets a bell notification; the sidebar Documents
   badge and the Approvals hub show it.
3. An RO approves → the first outside party receives the invitation exactly as it would have; ledger +
   audit rows exist; the sender is notified.
4. An RO declines with a reason → `approval_declined`; sender sees the reason on My Documents; can resubmit or
   cancel; CO can override with reason.
5. The sender cannot approve their own document unless CO; the attempt is audited.
6. Scope: a branch-manager RO sees only their branch's held documents; an admin RO sees all; an agent RO sees own.
7. Candidate on route 2: authoriser pool = full-status e-sign officers; co-signature completes it; no second hold.
8. Settings: route cannot switch on without a CO; last CO cannot be ended while on; RO diff-set never deletes.
9. Wizard compliance step shows and saves all new controls; posting a subset never wipes other settings.
10. Whistleblow: submit notifies the CO; changes-requested notifies the filer with notes; ROs may approve only
    when the agency allows; `show()` 404s outside scope; badge uses the same rule.
11. FICA sidebar badge equals the FICA screen's own two queue counts for the same user.
12. `/api/v1/approvals/pending` is named, listed in Admin › API, returns only the viewer's items.

---

## 13. Test matrix (targeted files only — Non-negotiable #13)

- `tests/Feature/Docuperfect/SigningView/ComplianceApprovalGateTest.php` — route 1 passthrough; route 2 hold
  (no invitation sent, status, ledger, audit, notification recipients); approve releases (invitation sent
  once); decline (reason required, status, sender notified); self-approval blocked / CO exempt; CO override;
  resubmit; scope own/branch/all; idempotent double-approve; candidate doc not double-held.
- `tests/Feature/Docuperfect/Candidate/CandidateAuthoriserRouteTwoPoolTest.php` — route 2 pool = full-status
  officers only; route 1 unchanged.
- `tests/Feature/Compliance/OfficerAppointmentsTest.php` — one-CO invariant; RO diff-set; route guard; end-CO
  guard; agency-scoped user ids; backfill of legacy approvers.
- `tests/Feature/Compliance/WhistleblowScopeAndNotificationTest.php` — scope own/branch/all on index + show;
  submit notifies CO; changes-requested notifies filer; RO decide gated by toggle; legacy fallback.

---

## 14. Files to create / modify

**Create**
- `database/migrations/2026_09_14_100001_create_officer_appointments_table.php` (+ backfill)
- `database/migrations/2026_09_14_100002_add_approval_settings_to_agencies.php`
- `database/migrations/2026_09_14_100003_add_compliance_approval_states_to_signature_templates.php`
- `database/migrations/2026_09_14_100004_create_esign_approvals_table.php`
- `app/Models/Compliance/OfficerAppointment.php`, `app/Models/Docuperfect/EsignApproval.php`
- `app/Services/Compliance/OfficerRegistry.php`, `app/Services/Compliance/ApprovalQueueCounts.php`
- `app/Services/Docuperfect/EsignApprovalService.php`
- `app/Events/Esign/ComplianceApprovalRequested.php`, `ComplianceApprovalDecided.php`
- `app/Events/Compliance/WhistleblowReportSubmitted.php`, `WhistleblowReportReturned.php`
- `app/Listeners/Esign/NotifyOfficersOfApprovalRequest.php`, `NotifySenderOfApprovalDecision.php`
- `app/Listeners/Compliance/NotifyOfficersOfWhistleblowSubmission.php`, `NotifyFilerOfWhistleblowReturn.php`
- `app/Notifications/Compliance/WhistleblowReportSubmittedNotification.php`, `WhistleblowReportReturnedNotification.php`
- `app/Http/Controllers/Compliance/OfficerAppointmentsController.php`, `ApprovalsHubController.php`
- `app/Http/Controllers/Docuperfect/EsignApprovalController.php`
- `app/Http/Controllers/Api/ApprovalsController.php`
- `resources/views/corex/approvals/index.blade.php`, `resources/views/docuperfect/approvals/index.blade.php`
- `resources/views/components/approvals-toast.blade.php`
- tests per §13

**Modify**
- `app/Models/Docuperfect/SignatureTemplate.php` (constants), `app/Models/Agency.php` (fillable/casts),
  `app/Models/Compliance/WhistleblowComplaint.php` (scopeVisibleTo)
- `app/Services/Docuperfect/SignatureService.php` (gate at the two dispatch points; `releaseAfterComplianceApproval`)
- `app/Services/CandidatePractitionerService.php` (route-2 pool)
- `app/Services/Compliance/WhistleblowComplaintService.php` (officer rule, events)
- `app/Http/Controllers/Compliance/WhistleblowController.php` (scope on index/show, canApprove)
- `app/Http/Controllers/Docuperfect/ESignWizardController.php` (My Documents groups; wizard message §6.5)
- `app/Http/Controllers/Docuperfect/DocumentController.php` (blocked statuses + labels)
- `app/Notifications/SignatureActivityNotification.php` (two factories)
- `app/Providers/AppServiceProvider.php` (explicit `Event::listen` — discovery is OFF)
- `config/corex-permissions.php`, `config/agency-onboarding-copy.php`, `database/seeders/NotificationEventTypeSeeder.php`
- `resources/views/corex/settings.blade.php`, `resources/views/agency-setup/steps/compliance.blade.php`
- `resources/views/layouts/corex-sidebar.blade.php`, `resources/views/layouts/corex.blade.php`,
  `resources/views/layouts/corex-app.blade.php` (toast include)
- `resources/views/docuperfect/esign/my-documents.blade.php`, `resources/views/docuperfect/documents/index.blade.php`
- `routes/web.php`, `routes/api.php`
- `database/schema/mysql-schema.sql`

**Deliberately NOT in this build (report-only, each needs Johan's separate go):**
- FICA gate lifting on `submitted`/`under_review` (canon divergence #6) — `SigningController.php:142-146`,
  `ESignWizardController.php:3602`.
- The `FicaReferredToCoNotification` `deep_link` key (bell renders `#`) — `app/Notifications/FicaReferredToCoNotification.php:45`.
- `FicaController` / `CommandCentreService` count duplication — left as is; the badge reuses the query shape.
