# AT-395 — Staging promotion handover

Prepared 2026-09-07, QA1 only. Updated same day after a third fix round (outgoing/SMTP fields
were missing from 2 of the 4 mailbox-configuration surfaces). Nothing here has been pushed to
Staging. Every line below was verified directly against git and the running QA1 checkout at the
time of writing — nothing is from memory.

---

## 1. Branch and commits

**Branch:** `at395-hotfix-2026-09-07`
**Pushed to origin:** yes, confirmed — `origin/at395-hotfix-2026-09-07` == local branch tip
(`cb8b23158af1aabbdda2497b42cccca4fb5414af`).

AT-395 is exactly **4 commits** (a 5th, docs-only, is the earlier version of this handover — see
note below), in order:

| # | Hash | One-line message |
|---|------|-------------------|
| 1 | `75cf670efbcd4be82b56e76edcf8ca21b7f52571` | feat(communications): AT-395 Phase A — send e-sign mail through the agency's own mailbox |
| 2 | `7cc7b33deb9ea9473ac8ec6da4f1c7da634f6103` | fix(esign): AT-395 real-send failures — missing To header + false-Sent audit/UI gaps |
| 3 | `b4f3bb76d9cd7ff373118a865da0edf46a5ede6e` | fix(esign): AT-395 second sweep — false-Sent bug class across the full advance chain |
| 4 | `f619230b951576ffe649985c1609984839bffd54` | docs(esign): AT-395 Staging promotion handover (earlier version of this file) |
| 5 | `cb8b23158af1aabbdda2497b42cccca4fb5414af` | fix(esign): AT-395 third sweep — outgoing SMTP fields missing from 2 of 4 mailbox surfaces |

**Uncommitted state:** nothing AT-395-related is uncommitted in the shared `/corex-qa1` checkout.
The checkout currently has other lanes' unrelated uncommitted/untracked files (reconstructed
migrations from an earlier DB-restore task, some `web-templates/cds/template-*.blade.php` files,
two `render_step3_*.mjs` scratch scripts, and pre-existing drift in `.ai/*.md`/`CLAUDE.md`) — none
of these touch any AT-395 file and none were created by this work.

**Cut from:** QA1 at commit `fa4a5ef79` (merge of `fix/esign-whatsapp-click-dead-2026-09-07`).
Commits 2–5 were then added directly, with the branch pointer fast-forwarded to track QA1's tip
after each commit so the running QA1 site always had the fix live.

**Rebasing needed?** Not against QA1 — the branch currently equals QA1's exact tip (`cb8b23158`,
confirmed identical, no divergence). **Against Staging, yes, sequencing matters** — see the
critical note below.

**⚠️ This branch is NOT an isolated AT-395 diff.** Because QA1 has been a single shared trunk all
night, `at395-hotfix-2026-09-07` descends from — and therefore *contains* — everything already
merged into QA1 before 14:41 today, which is **77+ commits**, not 5. That includes the WhatsApp
click-fix, the e-sign identity/reauth work, the e-sign identity-binding work, and a large amount of
`rental-applications` (AT-392) work. Verified: `git merge-base --is-ancestor 79a54fcf5
origin/Staging` → **NO**, and same for `eee164534` → **NO**. Neither the identity/reauth merge nor
the identity-binding merge is on `origin/Staging` yet. **If this branch is merged into Staging
as-is, all of that lands, not just the 5 AT-395 ones.** If the other chat wants AT-395 in
isolation, they should cherry-pick exactly `75cf670ef 7cc7b33de b4f3bb76d cb8b23158` onto Staging
in that order (commit 4 is this doc, optional to carry — it changes nothing runtime), not merge
the branch wholesale. Given the brief says "promotion for ALL of tonight's work," this may be
intended — flagging it so it's a decision, not a surprise.

---

## 2. The migration — the known trap

**File:** `database/migrations/2026_09_07_134131_add_outgoing_smtp_fields_to_communication_mailboxes_table.php`
**Class:** anonymous (`return new class extends Migration { ... }` — standard Laravel 9+ syntax,
no named class to reference).

**What it does — two tables:**
- `communication_mailboxes`: adds 15 new columns, all guarded by `Schema::hasColumn()` checks
  (idempotent, safe to run twice) — `outgoing_enabled` (bool, default false),
  `use_imap_credentials_for_smtp` (bool, default true), `smtp_host` (string, nullable),
  `smtp_port` (int, default 587), `smtp_encryption` (enum tls/ssl/none, default tls),
  `smtp_username` (string, nullable), `smtp_encrypted_password` (text, nullable),
  `smtp_from_name` (string, nullable), `outgoing_active` (bool, default true), `last_send_error`,
  `last_send_error_at`, `consecutive_send_failures` (default 0), `send_failure_notified_at`,
  `last_sent_at`, `last_sent_folder_append_error`, `last_sent_folder_append_at`.
- `agencies`: adds one column, `communication_send_failure_alert_threshold` (nullable unsigned
  smallint), also `hasColumn`-guarded.
- **Purely additive.** No existing column renamed, retyped, or dropped. No data transformation.

**Ordering — this is the trap, stated exactly:** the code in commit 1 (`75cf670ef`) reads
`outgoing_enabled` as a raw SQL `WHERE` clause (`CommunicationMailbox.php:128`,
`->where('outgoing_enabled', true)`) inside the mailbox-resolution path that runs on **every**
e-sign send attempt. Verified directly in the model. **If the code deploys before the migration
runs, this is not a soft failure or a silent fallback — it is a `QueryException: Unknown column
'outgoing_enabled'` thrown on every single e-sign send, immediately.** Run `php artisan migrate
--force` before restarting php-fpm/queue workers on the new code, in the standard CoreX deploy
order (CLAUDE.md §"How to Build Something New" step h): git pull → migrate --force → cache
clears → reload php-fpm → restart queue worker.

**Reversible?** Yes, cleanly — `down()` drops exactly the 16 columns it added, nothing else. Since
every new column is either nullable or has a default, and the columns aren't referenced by any
other migration or seeder, rolling back loses only whatever outgoing-mailbox configuration was
entered after the migration ran (mailbox rows themselves are untouched — they just lose these
columns). No IMAP-read/import functionality is affected by a rollback; that's on the pre-existing
columns this migration never touches.

**Conflicts with anything else landing tonight?** No collision found. Checked every migration file
in `origin/Staging..at395-hotfix-2026-09-07` (14 total): the other 13 are all
`rental_applications`/`rental_application_*` tables or `docuperfect_esign_settings` columns —
none touch `communication_mailboxes` or `agencies`. No filename-timestamp collision either
(`2026_09_07_134131` is unique in the migrations directory).

---

## 3. Files touched — full list

Authoritative list, built from `git show --name-status` on each of the 4 code commits
individually (not a diff against Staging, which would pull in the other unrelated commits
described in §1; the 5th commit is this doc only).

**Events (new):**
- `app/Events/Communications/OutgoingMailFellBackToSharedMailer.php`
- `app/Events/Communications/OutgoingMailSentViaOwnMailbox.php`

**Exceptions (new):**
- `app/Exceptions/Communications/OutgoingMailboxSendFailedException.php`

**Services:**
- `app/Services/Communications/ImapMailboxPoller.php` (modified)
- `app/Services/Communications/ImapSentFolderAppender.php` (new)
- `app/Services/Communications/PerMailboxMailTransportBuilder.php` (new)
- `app/Services/Docuperfect/SignatureService.php` (modified — touched by all 3 commits, by far
  the largest single file in this change set)

**Controllers:**
- `app/Http/Controllers/Compliance/CommunicationMailboxController.php`
- `app/Http/Controllers/CoreX/AgencySetupWizardController.php`
- `app/Http/Controllers/Docuperfect/ESignWizardController.php`
- `app/Http/Controllers/Docuperfect/SignatureController.php`
- `app/Http/Controllers/Settings/EmailSetupController.php`
- `app/Http/Controllers/MyPortal/CommunicationCaptureController.php` (added in commit 5 — the
  self-service surface never had outgoing-field support at all until this round)

**Mail:**
- `app/Mail/Signatures/BaseSignatureMail.php`

**Models:**
- `app/Models/AgencyOnboardingSetup.php`
- `app/Models/Communications/CommunicationMailbox.php`

**Config:**
- `config/agency-onboarding-copy.php`
- `config/communications.php`
- `config/corex-permissions.php` (adds one new permission key, `communication_mailboxes.view` —
  see §5 for whether anything else needs to happen for it to take effect)

**Also (commit 5):**
- `routes/web.php` — added `settings.email-setup.test-connection`'s response now flashes a mailbox
  id too, plus the new `my-portal.comm-capture.test-connection` route.

**Migration:**
- `database/migrations/2026_09_07_134131_add_outgoing_smtp_fields_to_communication_mailboxes_table.php`
  (unchanged since commit 1 — commit 5 added no new migration, only used the existing columns)

**Views:**
- `resources/views/compliance/communication-archive/mailboxes/form.blade.php`
- `resources/views/compliance/communication-archive/mailboxes/index.blade.php`
- `resources/views/docuperfect/amendments/review.blade.php`
- `resources/views/docuperfect/compiler/studio.blade.php`
- `resources/views/docuperfect/esign/my-documents.blade.php`
- `resources/views/docuperfect/esign/signing-complete.blade.php`
- `resources/views/docuperfect/importer/review.blade.php`
- `resources/views/docuperfect/rental/dashboard.blade.php`
- `resources/views/docuperfect/signatures/external/consent.blade.php`
- `resources/views/docuperfect/signatures/review.blade.php`
- `resources/views/docuperfect/templates/edit.blade.php`
- `resources/views/sales-documents/upload.blade.php`
- `resources/views/settings/email-setup/_mailbox-fields.blade.php` (commit 5 — outgoing/SMTP
  fields added; shared by 3 of the 4 surfaces, see §5)
- `resources/views/settings/email-setup/_user-mailbox.blade.php` (commit 5 — Test Connection
  button + result panel added)
- `resources/views/my-portal/communication-capture/index.blade.php` (commit 5 — wired to pass the
  new Test Connection route name)

**Tests:**
- `tests/Feature/Communications/OutgoingMailPerMailboxTest.php` (new, then extended twice — 9
  tests total, unchanged by commit 5, re-run clean)
- `tests/Feature/Communications/EmailSetupTest.php` (commit 5 — 6 new tests added to the
  pre-existing AT-37 test file, 12 total, all passing)

**Spec:**
- `.ai/specs/at395-outgoing-mail-per-mailbox-smtp.md` (new, then extended three times — §15.6
  covers commit 5's fix in full)

### Overlap with WhatsApp work

**One shared file, low risk.** Checked the WhatsApp merge commits landing on QA1 tonight
(`fix/esign-whatsapp-click-dead-2026-09-07`, `fix/esign-whatsapp-followup-2026-09-07`,
`feature/esign-whatsapp-send`). Their files: `.ai/specs/esign-v3-complete-spec.md`,
`resources/views/docuperfect/signatures/partials/_whatsapp-resend-button.blade.php`, and —
found while re-checking for commit 5 — `routes/web.php` (`a0280e415`, "AT-385/AT-332 — WhatsApp
resend for signing links," +2 lines). AT-395 commit 5 also adds lines to `routes/web.php` (two new
route registrations). Both changes are pure line additions in a large, route-per-line file — the
kind of change git merges cleanly without a real conflict — but naming it here since `routes/web.php`
is a common collision point on any multi-branch night.

### Overlap with the identity/ID-gate work — real, named collision

**Yes — three files, named exactly.** The `feature/esign-identity-and-reauth` merge (`79a54fcf5`,
already on QA1 since 05:29 today, i.e. it landed *before* AT-395 was built and AT-395 was built on
top of it) touched:

- `app/Http/Controllers/Docuperfect/ESignWizardController.php` — **also touched by AT-395 commit 3.**
- `app/Http/Controllers/Docuperfect/SignatureController.php` — **also touched by AT-395 commits 2 and 3.**
- `app/Services/Docuperfect/SignatureService.php` — **also touched by AT-395 commits 1, 2, and 3.**

(It also touched `AmendmentController.php`, `SigningController.php`,
`Concerns/EnforcesReauthorisationBinding.php`, `Contact.php`, `SignatureRequest.php`, two
migrations, and `wizard.blade.php` — none of those are shared with AT-395.)

**Risk assessment:** because AT-395 was built and both hotfix rounds were written *on top of*
this already-merged identity/reauth code (it is an ancestor of `at395-hotfix-2026-09-07`, verified
in §1), there is **no unresolved conflict inside this branch** — git already reconciled both sets
of changes when I built on top of QA1. The risk is only if the other chat's promotion plan applies
the identity/reauth code and the AT-395 code as two *separate, independently cherry-picked* units
rather than via this branch's actual linear history — in that scenario a textual merge conflict on
these 3 files is plausible, since both features made real, nearby edits inside them. Safest
sequencing: promote via the existing QA1 ancestry (this branch, or QA1 itself) rather than
re-deriving each feature as an isolated patch.

---

## 4. The identity gate — answered directly

**Did AT-395 touch `assertRecipientsHaveIdentityForSend` in any way?** **No.** Verified: the
function is defined once, at `app/Http/Controllers/Docuperfect/ESignWizardController.php:5297`,
and called at lines 2776, 7577 (plus referenced in a comment at 3693). `grep` for that function
name across all three AT-395 commit diffs returns zero matches. AT-395's one edit to
`ESignWizardController.php` (in commit 3, `b4f3bb76d`) is at line ~8129 — inside
`wetInkAgentApprove()`, a wet-ink upload-on-behalf approval action — nowhere near the identity
gate function or any of its three call sites.

**Did AT-395 touch the `EsignSettings` model, or any identity/reauth setting?** **No.** Verified:
`app/Models/Docuperfect/EsignSettings.php` does not appear in any of the three AT-395 commits'
file lists (checked via `git show --stat` on each). AT-395 never reads or writes
`requireIdentityBeforeSend`, `strictReauthorisationBinding`, or any other `EsignSettings` field.

**Could anything AT-395 changed cause the identity gate to reappear, become conditional, or be
bypassed?** **No, plainly.** AT-395 is entirely about *how* an already-approved-to-send invitation
email is transported (which SMTP server it goes out through, and whether a copy lands in the
sender's own Sent folder) — it sits strictly downstream of `assertRecipientsHaveIdentityForSend()`
in the call chain and never touches the settings table that gate reads. The one place AT-395
changed *when* an email attempt happens (the false-Sent sweep in commit 3) only changes whether a
send that already passed every prior gate is honestly reported as sent-or-failed after the
attempt — it adds no new path around the gate and removes none of the existing ones. The other
chat's own re-check of the gate being unconditional after landing should find it byte-identical
to before AT-395, since no AT-395 diff touches that file or that settings model at all.

**Re-checked after commit 5** (the mailbox-surface-parity fix): that round touched only
`EmailSetupController.php`, `MyPortal\CommunicationCaptureController.php`, `routes/web.php`,
`config/agency-onboarding-copy.php`, and 3 blade views under `settings/email-setup/` and
`my-portal/communication-capture/` — none of which are anywhere near the e-sign signing/identity
code. `git show --stat` on commit 5 confirms zero mention of `EsignSettings` or
`ESignWizardController`. This answer holds unchanged.

---

## 5. Manual configuration required on Staging after the merge

**Outgoing mailbox settings are per-environment data, not code.** They live in individual rows of
the `communication_mailboxes` table (per agent/mailbox), which do **not** travel with a code
deploy or a migration (the migration only adds the columns — it does not populate them). **No
e-sign invitation will go out through an agency's own mailbox on Staging until someone configures
at least one mailbox there with real outgoing settings and a working password.** Until that
happens, `resolveOutgoingMailboxFor()` finds no `outgoing_enabled=true` row for the sending agent
and the send silently uses the pre-existing shared CoreX mailer exactly as it did before AT-395 —
this is the designed no-mailbox fallback, not a bug, but it means AT-395's actual improvement
(Gmail SPF/DKIM pass) will not be observable on Staging until configured.

**Exactly what to enter, and where — verified against the actual form fields, not assumed:**

As of commit 5, all **four** surfaces that write `communication_mailboxes` render the same
outgoing/SMTP fields and support the same Test Connection action — confirmed by grep across every
view directory and a passing test for each surface (`EmailSetupTest.php`). Configure from
whichever is most convenient for who's doing it:

- **Compliance → Communications → "Email Mailboxes (import)"** (`compliance.comm-mailboxes.*`,
  requires `manage_communication_mailboxes`) — the original, agency-wide admin list. Edit an
  existing mailbox row (or create one).
- **Settings → Email → "Email Capture Setup"** (`settings.email-setup.*`, same permission,
  per-user grouped view) — same fields, added in commit 5. Also reached from **Admin → Users →
  edit an agent → "Communication Capture"** (shares the identical partial, no separate work
  needed there).
- **My Portal → Communication Capture** (`my-portal.comm-capture.*`, gated by
  `access_communication` — an ordinary agent's own self-service page, no admin permission
  required) — an agent can now configure outgoing mail for their own mailbox directly, added in
  commit 5. Ownership-enforced: an agent can only ever touch their own row.
- **Agency Onboarding Setup Wizard** (first-run only) — asks for the same fields, plus an optional
  IMAP host (commit 5 fix; see below).

On any of the first three, fill in exactly as named in the form:
- Checkbox `outgoing_enabled` — turn outgoing sending ON for this mailbox.
- Checkbox `use_imap_credentials_for_smtp` — checked by default; if the mailbox's existing IMAP
  credentials are also valid for SMTP (usually true for a normal mailbox account), leave it
  checked and the SMTP host/port/username/password fields below can stay blank. Uncheck only if
  SMTP needs different credentials/host than IMAP.
- If unchecked: `smtp_host`, `smtp_port` (defaults 587), `smtp_encryption` (tls/ssl/none,
  defaults tls), `smtp_username`, `smtp_password` (write-only, stored encrypted).
- `smtp_from_name` — optional, defaults to the mailbox's user's name if left blank.
- Use the **Test Connection** button on the same screen after saving — it exercises both the SMTP
  send leg and the IMAP Sent-folder-append leg independently and reports each separately, on all
  three of these surfaces now (My Portal and Settings gained it in commit 5).

**The onboarding wizard fix (commit 5):** the wizard step previously only asked for the outgoing
(SMTP) server and silently reused that same value for the mailbox's IMAP host too — wrong for any
provider where incoming and outgoing mail use different hostnames (e.g. Gmail). It now asks for an
optional "Incoming mail server (IMAP host)" field, defaulting to the SMTP host above when left
blank. If Staging onboarding was already run through this step before commit 5 landed, any mailbox
it created should be spot-checked — `communication_mailboxes.imap_host` may have been set from the
SMTP host when it should differ.

**Mail config differences — QA1 vs Staging:**

QA1's `.env` (verified directly): `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`
(a local catch-all, not a real server), `MAIL_FROM_ADDRESS=system@hfcoastal.co.za`. This is why,
*before* any mailbox is configured for outgoing, e-sign mail on QA1 goes to a local catcher, not a
real inbox — and why configuring a real per-mailbox SMTP row (as was done for
`johan@hfcoastal.co.za` during testing) was necessary to prove real delivery at all.

**I cannot verify Staging's actual current `.env` mail values from this QA1 checkout** — this
session has no filesystem access to the Staging host, and the only documents found in `.ai/`
referencing Staging mail config are a June 2026 deploy-checklist template (with a placeholder
`MAIL_HOST=...`, not a real value) and an August 2026 investigation of a *different* box
(`live-testing`, a separate physical/DNS environment, not the `Staging` git branch's own target).
Neither is a reliable source for Staging's mail config **today**. **The other chat should check
Staging's actual `.env` directly before assuming its default-mailer behaviour** — if it currently
points at a real SMTP server (rather than a local catcher like QA1), any e-sign send from an agent
with no mailbox configured will go out for real through whatever `MAIL_FROM_ADDRESS` Staging uses,
exactly as it did before AT-395 existed. AT-395 does not change that fallback path at all.

**Other environment-specific steps after deploy** (standard CoreX deploy order, CLAUDE.md
step h): `php artisan migrate --force`, then `view:clear`, `route:clear`, `config:clear`, reload
php-fpm, restart the queue worker. No new queue is introduced by AT-395 — sends happen
synchronously in the request/controller flow, same as before. The one new permission key
(`communication_mailboxes.view`, added to `config/corex-permissions.php`) is config-driven, not
DB-seeded — no `permissions:sync`-type command was found in this codebase for it; a plain
`config:clear` after deploy is what makes it visible, consistent with how the rest of
`corex-permissions.php` already works.

---

## 6. What to test on Staging, in order — shortest path to proving it works

1. Confirm the migration ran: `communication_mailboxes` has the new columns (e.g.
   `php artisan tinker` → `Schema::hasColumn('communication_mailboxes','outgoing_enabled')` →
   `true`).
2. Configure one real mailbox for outgoing (§5) and click **Test Connection** on the Communications
   → Email Mailboxes screen. Both legs (SMTP send, IMAP Sent-folder append) should report success
   independently.
3. Send a real e-sign invitation from an agent whose mailbox was just configured, to a real test
   inbox. Confirm `invite_send_status = sent` on the resulting `signature_requests` row and
   `sent_at` is stamped.
4. Confirm the email actually arrived at the test inbox, and that a copy appears in that mailbox's
   own real Sent folder (not just the CoreX database).
5. Deliberately break the mailbox (wrong password, or disable `outgoing_active`) and attempt
   another send. Confirm it fails **loudly**: `invite_send_status = failed`, the request is never
   marked sent, and the error is genuinely visible on the My E-Sign Documents screen (this was the
   exact defect class AT-395's two hotfix commits fixed on QA1 — worth re-proving fresh on
   Staging rather than assuming it carries over).
6. Test **Resend** from My E-Sign Documents against both a healthy and a broken mailbox — confirm
   each shows the correct outcome.
7. Repeat steps 2–3 configuring the SAME mailbox from a **different** surface than the first pass —
   e.g. configure via Settings → Email Setup if step 2 used the Compliance screen, or via My Portal
   as the agent themselves. Confirm the result is identical regardless of which screen was used
   (this is the exact parity commit 5 fixed — worth proving fresh on Staging, not assumed).
8. Only after 1–7 pass: check an agent with **no** mailbox configured still sends via the
   pre-existing shared mailer exactly as before (the no-mailbox fallback must be unaffected).

---

## 7. Known limitations carried into Staging

- **Phase B (OAuth-based mailbox auth) is not built.** Only static SMTP host/port/username/password
  is supported in Phase A. This was always out of scope for AT-395 Phase A — not a regression.
- **`EmailSetupController::update()` and `MyPortal\CommunicationCaptureController` have no
  explicit cross-agency ownership assertion beyond the route's permission gate** (the agency
  surface's own `store()` calls `assertSameAgency()`; its `update()` doesn't; the self-service
  surface's `update()` does call `assertOwn()`). Found while building commit 5, reported rather
  than fixed — pre-existing from the original AT-33/AT-37 build, not introduced or touched by any
  AT-395 work, and out of this round's scope per the standing anti-drift rule.
- **One narrow instance of the false-Sent bug class was found but deliberately left unfixed,
  and reported rather than rushed:** `SignatureService::submitInspection()` (the wet-ink *physical*
  document inspection/decision flow, distinct from the digital invitation flow that was the actual
  reported and reproduced Staging-bound bug) has the same shape — a discarded advance result — but
  its return type is a different model (`WetInkInspection`) that can't carry the outcome without a
  larger return-shape change. Full detail in spec §15.5. Do not treat this as a new bug if found —
  it's a known, named, already-scoped gap.
- **SPF/DKIM verification was done by Johan directly in Gmail** ("Show original" → SPF PASS, DKIM
  PASS) for the QA1 test send. This session had no way to check headers itself and did not
  re-verify SPF/DKIM as part of this handover — that check is specific to the sending domain's DNS
  records (SPF/DKIM are configured at the domain/DNS level, not per-environment code), so it
  should hold on Staging too provided Staging sends through the same real mailbox/domain, but this
  has not been independently re-tested on Staging and should be per §6 step 4.
- **All 21 regression tests pass** (9 in `OutgoingMailPerMailboxTest.php`, 12 in
  `EmailSetupTest.php`, the latter including 6 new tests added in commit 5), but per CLAUDE.md
  Rule 13 the full suite was never run this session (single most relevant file only, per standing
  instruction) — the other chat's own promotion checks should decide whether a broader suite run
  is warranted before Staging goes live with this.
