# Three findings from Johan's live test pass, 2026-09-14 — investigation only, no code

Requested by the conductor, order 3 → 2 → 1. Read-only throughout; no fixture writes
except one disposable-scoped DB read used to settle Finding 3's Mailpit check.

## Finding 3 — "Approved, ready to send. How do I send it?"

**Answer: approval does NOT auto-send to the applicant, by design — this was Johan's
own prior ruling. "Ready to send" is the correct, honest wording. The bug is that
the list view offers no working path to the control that actually sends it.**

- `sendApproved()` (the applicant-facing congratulations mail) is called from
  exactly one place: `RentalApplicationReviewController::send()`
  (`app/Http/Controllers/CoreX/RentalApplicationReviewController.php:551-572`),
  an explicit agent action, idempotent (`applicant_notified_at` guard — 422 on a
  second attempt). `RentalApplicationAuthorisationController::approve()`
  (`:404-406`) has an explicit comment: *"AT-392 — Johan changed the flow: approval
  no longer auto-emails the applicant. 'no, agent gets back and upon them being
  happy it gets sent out.'"*
- Confirmed against real data: 28 of 31 approved applications on QA1 have
  `applicant_notified_at = NULL`. Nothing has auto-sent for any of them.
- What DOES fire automatically at approval: `notifyAgentOfDecision()`
  (`RentalApplicationAuthorisationController.php:404`) — an **internal** email to
  the agent ("Approved — {applicant name}"), not to the applicant. Confirmed via
  the outbound-mail-guard capture table: two of these landed in `johan@hfcoastal.co.za`
  today, timestamps matching applications 4 and 427 exactly. This is very likely
  the direct source of the confusion — Johan sees "Approved — X" arrive in his own
  inbox and reasonably reads that as confirmation something went to the applicant.
- **The real bug, two parts, both confirmed by file:line:**
  1. **No path to the real send button.** The correct action
     (`RentalApplicationReviewController::send()`) is wired to a button on
     `review.blade.php:1438` — but the list view's own "Review & Assess" link
     (`index.blade.php`) only shows for `RentalApplicationController::REVIEWABLE_STATUSES`
     (`in_progress, returned, reopened, under_assessment` — `RentalApplicationController.php:282`),
     which excludes `approved`. The review screen's own controller *does* allow
     access for "approved and not yet notified" (`RentalApplicationReviewController.php:200`,
     `$canStillActHere`) — the destination is reachable, the list just never
     links to it for this status.
  2. **A decoy button on the same row.** What the list *does* show —
     `index.blade.php:408`, labelled "Resend" for any non-draft status — posts to
     a completely different route/controller: `corex.rental-applications.send` →
     `RentalApplicationController::send()` → `RentalApplicationMailer::sendInvite()`
     (`RentalApplicationController.php:1257-1279`). That resends the **original
     application invite link**, not an approval notification. It renders on every
     status as long as `recipientEmail()` exists — no status check at all — and
     its label ternary (`status === 'draft' ? 'Send' : 'Resend'`) never checks
     `applicant_notified_at`. For an approved, never-notified row, this button
     looks exactly like what Johan wants and does something unrelated.

**Decline comparison, as asked:** decline's actual send mechanism is well-built —
`sendDecline()` has the amber hold, the visible `To:` line, the no-email guard,
idempotency — all real, all confirmed present in earlier work tonight. But
reachability has its own, different gap: `index.blade.php` has **no equivalent
amber "declined, ready to send" indicator at all** (the special-cased badge only
checks `status === 'approved'`), and the decline-send drawer only exists inside
`review.blade.php`, which for a **declined** application requires override tier
(`$canStillActHere`'s declined branch: `status === 'declined' && isRentalApplicationOverrideTier(...)`).
A regular agent viewing a declined application lands on `view-readonly.blade.php`
instead (via `show()`'s `AGENT_EDIT_LOCKED_STATUSES` branch) — which has **no**
decline-send control at all (confirmed: zero references). So decline isn't the
clean model to copy — it has a related, narrower gap of its own (a regular agent
may not be able to reach their own decline-send action at all).

## Finding 2 — agent's note not visible to the authoriser

**Not settled to a single confirmed root cause. Investigated three real candidates
in depth; disproved each with code-level evidence rather than assuming. Needs one
fact from Johan — which control he means — to close.**

1. **Document note-pin (`type: 'note'` marks, the "N" pin on a highlighted
   document)** — the most likely candidate on first read. Traced the full path:
   both `RentalApplicationReviewController` and `RentalApplicationAuthorisationController`
   share the identical `HandlesRentalApplicationDocumentMarks` trait
   (confirmed: `use` on both, same method bodies, not two implementations).
   `highlightFirstPage()`/`highlightRemainingPages()` call the same
   `RentalApplicationDocumentHighlightService`, which loads marks by
   `document_id` alone — no role filter anywhere in the query.
   `notesFor(page)` in the highlighter script filters `this.marks` by
   `type === 'note' && page === p` only — no role filter. The rendering
   template (`document-highlighter-pages.blade.php:486-500`) has no
   highlighter/role lookup dependency either. **Every layer checked is
   symmetric.** Ruled out, with evidence, not assumed.
2. **The assessment's own free-text `notes` field**
   (`rental_application_assessments.notes`) — found it's wired into the
   Alpine state (`fields.notes`, initialized from `$assessment->notes`,
   included in the autosave payload) but **there is no textarea bound to it
   anywhere in `review.blade.php`** (grepped every `textarea` in the file —
   six total, none for this field). If this is what Johan means, the actual
   defect is different from what he described: there's currently no way for
   an agent to write into this field at all through the UI, for either role.
3. **Status-history notes** (the "Note (optional)" field on the list view's
   status-change dropdown, written via `RentalApplicationStatusHistory::record()`)
   — checked whether the authoriser's screen displays these. It does: `RentalApplicationAuthorisationController.php:256`
   loads the FULL `statusHistory()` collection (uncapped), while the agent's
   own review screen only loads `latest('created_at')->first()`
   (`RentalApplicationReviewController.php:276`) for two narrow, specific
   purposes (surfacing the authoriser's "more info requested" note and the
   decline reason back to the agent) — not a general note display. If
   anything, this mechanism gives the **authoriser** more visibility than the
   agent, the opposite direction from Johan's complaint. Ruled out as the
   mechanism he's describing.

No note matching his description exists in the current data (checked
`rental_application_document_marks` for any `type='note'` row created today —
zero). The 13 existing note-type marks in the database are all test artifacts
from 2026-09-10/11 sessions, not from today's walk.

**What's needed to close this:** which control, exactly. If it's the document
"N" pin, the code says it should already work — worth a direct repro (agent adds
a note on a real document, authoriser opens the SAME document, does it appear) to
either catch something this trace missed or reclassify it as unreproducible/already
fixed. If it's something else — a different screen or control entirely — need to
know which one before spending more time on any of the three traced above.

## Finding 1 — no warning that submitting loses unsaved highlighter work

**Confirmed: work genuinely CAN be lost. Not a missing-warning UX gap — a real,
reproducible data-loss path. This is the most serious of the three, exactly per
Johan's own framing, and the team already solved the identical problem in one
place but never extended it to this one.**

- Marks (highlights, notes, capture-ledger lines) are **explicit-save only** —
  confirmed by the feature's own docblock comment (`review.blade.php:421-423`):
  *"if I edit / highlight anything on the PDF will it automatically save? It did
  not autosave, and answering that ambiguity is the fix: highlighting/notes are
  EXPLICIT-save only."* Each per-document highlighter component tracks its own
  `dirty` flag (`document-highlighter-script.blade.php:199`), set on every mark
  add/edit/remove.
- There's already a real, deliberate solution for ONE transition: switching
  which document is expanded within the continuous view. `openHighlighter()`
  (`document-highlighter-script.blade.php:735-748`) checks `dirty` and — in the
  team's own words — *"this USED to auto-save silently here, which is exactly
  the ambiguity Johan flagged... Never lose the marks either way."* It prompts
  ("You have unsaved highlights or notes on this document. Save them before
  switching?"), saves on confirm, and refuses to switch away silently.
- **"Submit for approval" never asks this question.** `submitForApproval()`
  (`review.blade.php:2740`) checks only `incompleteAssessmentReasons()` — nothing
  about mark state. `doSubmitForApproval()` is a plain `fetch()` POST, no page
  navigation — so the app's OTHER safety net, a `beforeunload` listener guarding
  on the same `dirty` flag (`document-highlighter-script.blade.php:670`), never
  fires either, because the page never unloads for this action. On a successful
  submit the page reloads 900ms later (`setTimeout(() => window.location.reload(), 900)`)
  — wiping the client-side `this.marks` state on any document that still had
  unsaved marks at that moment.
- Confirmed there is no cross-component signal that could catch this another
  way: grepped for any `$dispatch`/window-event carrying dirty state from a
  per-document highlighter instance up to the outer `rentalReviewLayout()`
  component that owns `submitForApproval()` — found none (`rental-markup-view-toggled`
  is the only event, and it carries open/closed state, not dirty state). The
  outer component structurally has no way to know a highlighter instance is
  dirty even if it wanted to check.

**Why this is the one to prioritise:** the fix for document-switching already
exists, is already correctly designed (ask, don't guess; never lose silently),
and is sitting one component away from Submit. This isn't a design question —
it's applying a pattern the team already built and proved correct to the one
place it was left out.
