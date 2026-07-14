# AT-236 — FICA multi-officer + self-approval + Refer-to-CO — JOHAN LOOKS HERE

_2026-07-14. m3. Branch `AT-236` (off Staging), pushed. MODE: BUILD. QA1-only per governance._
_Spec = the AT-236 investigation (`.ai/investigations/AT-236-fica-multi-officer.md`) + Johan's build word._

## Status: BUILT + TESTED (8/8). QA1 host deploy BLOCKED — see §Blocker.

Six commits on `AT-236` (`d0de758c` → tip). All `php -l` clean; all blades compile
(`view:cache`); the FICA workflow test is **8/8 green** and exercises the real routes/
controllers (HTTP-level), the durable audit, and the queue scoping.

## Spec-conformance (Johan's rulings → what shipped)

1. **Self-approval guard** (his "same person cannot approve their own fica unless it's the CO",
   composed with primary-only): `FicaController::complianceApprove` blocks when the approver is a
   participant (`requested_by` OR `agent_verified_by`) UNLESS they are the **primary CO**. Server-side,
   before validation; the block is written to the durable audit (`self_approval_blocked`, actor tier
   captured). ✔ Test: secondary self-approve blocked+audited; primary self-approve allowed; secondary
   approves another's pack allowed.
   **Board note (his to loosen):** the exception is PRIMARY-only — say the word to widen to any-CO
   (one line: drop the `isPrimaryComplianceOfficer` check to `isComplianceOfficer` in the guard).
2. **Refer-to-CO** ("promote to CO button for any person doing the CO approval"): a "Refer to CO"
   third action (mandatory reason) on **every** review surface — the agent review (`show`) and the CO
   screen (`compliance-review`, for a secondary who can't self-approve). → state `referred_to_co`
   (enum migration, no side flags) → the **primary CO's queue** → CO can approve / reject / **return
   to referrer** with comments → **every hop in the durable `fica_status_history` ledger**. CO notified
   via the **AT-235 gateway** (`fica.referred_to_co` event key registered, migration + seeder). ✔
3. **Quick links** (his expansion): "FICA — Awaiting CO Review" is now **strictly the CO queue**
   (`agent_approved` + `referred_to_co`), **scoped to designated COs** (a non-CO sees count 0 → card
   skipped; it previously mis-counted `submitted` for everyone). **NEW** "FICA — Awaiting My Review"
   authorized-reviewer card (the reviewer's own packs in a review state) — identical count / item /
   click-through shape (shared `ficaCardItems`). FICA index page got the `referred_to_co` tab + counter
   and the co_queue tab now includes referrals. ✔ Test: CO-queue counts agent_approved+referred CO-only;
   reviewer queue counts own.
4. **Ruled decisions:** role-enum used (no redundant `is_primary`) ✔; **durable audit table**
   `fica_status_history` (append-only, immutable — the FIC-Act ledger) ✔; button on **all** review
   surfaces ✔; **referral on/off + recipient = agency settings** (`agencies.fica_referral_enabled`
   default ON, `fica_referral_recipient_user_id` default = primary CO), Settings-page control + saver ✔.

## Deliberate omission (needs your nod — #10a)
The **onboarding wizard** does not surface the referral toggle. The onboarding compliance step already
**defers officer appointments to the Compliance module** (`agency-setup/steps/compliance.blade.php:51`),
and Refer-to-CO is inert until officers exist — so it belongs with the deferred officer settings, not
the wizard. Recorded here as a decision on the record per #10a. Defaults (ON / primary CO) mean it ships
working regardless. Say the word to add a wizard step.

## Files
- Audit: `app/Models/FicaStatusHistory.php`, migration `..._000001_create_fica_status_history_table`.
- State: migration `..._000002_add_referred_to_co_to_fica_submissions`; `app/Models/FicaSubmission.php`.
- Guard + actions: `app/Http/Controllers/Compliance/FicaController.php` (complianceApprove guard,
  referToCo, returnToReferrer, index queue, audit at every hop).
- Refer service + notify: `app/Services/Compliance/FicaReferralService.php`,
  `app/Notifications/FicaReferredToCoNotification.php`, migration `..._000003_register_...` + seeder.
- Buttons: `resources/views/compliance/fica/partials/refer-to-co.blade.php` + show/compliance-review/index.
- Quick links: `app/Services/CommandCenter/CommandCentreService.php`.
- Settings: `agencies` migration `..._000004`, `Agency` model, `FicaOfficerAppointmentsController::saveReferralSettings`, route, `settings.blade.php`.
- Test: `tests/Feature/Compliance/FicaMultiOfficerWorkflowTest.php` (8/8).

## Verify chain
- `php -l` clean on every changed PHP file; `view:cache` clean (all blades compile);
  `route:list` shows the new fica routes (refer-to-co, return-to-referrer, fica-referral).
- **Test 8/8** — the real HTTP endpoints for the guard block, refer round-trip, and both queues.
- Migrations proven to apply cleanly on top of the committed snapshot (RefreshDatabase ran all four
  new migrations for the test). Schema snapshot NOT re-dumped from this lane (per the AT-246 rule —
  refresh belongs at the Staging integration point).

## ⛔ Blocker — QA1 host deploy + browser verify not done
The shared QA1 checkout (`/corex-qa1`) has **another lane's UNCOMMITTED AT-264 work** (secure-doc PACK:
modified `routes/web.php`, `SecureDocumentController`, `Dr2DistributionSendService` + untracked
blades/tests). Merging AT-236 conflicts on `routes/web.php`, and the SHARED-QA1 rule forbids me from
stashing/clobbering another lane's checkout. **I did not touch it.** The QA1 deploy + the browser-level
"both quick links + guard + full refer round-trip as different roles" verification are therefore pending
that lane committing/clearing its work — then the deploy is a clean `merge origin/AT-236 → migrate →
clears → reload`. The HTTP-level equivalents are already proven by the 8/8 test. Awaiting conductor
coordination to land on QA1.
