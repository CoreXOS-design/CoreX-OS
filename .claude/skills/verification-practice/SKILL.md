---
name: verification-practice
description: How to prove a fix or feature honestly - prove the click arrived, check raw HTML for removed data, capture the BEFORE, ask whether data is real before a backfill, never leave artefacts on demo records, plus View-As vs Switch User. Use when verifying, reporting a change as proven, designing a backfill, or testing visibility-scoped features.
---
# Verification practice

- **Endpoint proof is not feature proof.** A PHPUnit POST proves the server contract, not that a human can reach the control (a disabled button shipped dead with 6 tests / 35 assertions green). Use the render gate / click-through for controls (skill `render-gate`).
- **Dead-looking control? Prove the input arrived first.** Pre-flight: `document.hasFocus()` true and `document.visibilityState === "visible"` (a background tab receives no synthetic input - Johan shares the browser). Proof: a capture-phase `document.addEventListener('mousedown', e => console.log(e.clientX, e.clientY, e.target), true)`. Nothing logged = input never arrived (not a code bug); logged on the wrong target = aiming; logged on the right target and still nothing = genuine defect.
- **Removed data: check the raw HTML / raw API response**, not the rendered text (hidden fields, data attributes, unauthenticated side-channel routes can still carry it).
- **Capture the BEFORE** (screenshot, saved response, raw query) before changing anything, when possible. If unreachable, say so and name the indirect evidence; never claim a baseline you didn't take.
- **Ask whether the data is real before designing a backfill.** QA/dev rows are fixtures: a recalculation fix needs no backfill for them. This is NOT a licence to skip backfills on live data.
- **Where a gate belongs:** name what a gate protects and whether the environment in front of it needs that. Finished, tested work lands on QA1 without waiting for a browser walk; the walk gate is at Staging.
- **Never create verification artefacts on a demo record** (property 5792, 1 Kenmuir Road, Uvongo, or any record demoed from). Use a throwaway record of your own and archive it afterwards (soft delete / `is_retired`). A distinctive name ("CC1 Verify") is not cleanup.
- **Vary both axes** (transport x data state) and never perform the automatic behaviour under test yourself (BUILD_STANDARD 5a/5b).
- **View-As vs Switch User:** "View As" (owner header role dropdown) swaps role + branch_id in session only - `Auth::user()` is unchanged, so visibility SCOPES (ContactScope, CalendarVisibilityResolver) still see the original user. To test visibility-scoped features use "Switch User" (impersonation: full `Auth::login($target)`; `ImpersonateController`, routes `POST /admin/impersonate/{user}` and `/stop`, permission `impersonate_users` or owner, logged in `impersonation_logs`). View As is only for permission/UI gating. Wrong results under View As: switch method before suspecting the scope.
- Tinker/QA data recipes and rolled-back proofs: skill `qa-deploy-and-deploy-dir`.
