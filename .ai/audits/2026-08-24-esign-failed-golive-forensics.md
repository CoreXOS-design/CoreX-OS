# E-sign failed go-live — forensic investigation (2026-08-24)

> ## ⚠️ SUPERSEDED — DOES NOT DESCRIBE CURRENT BEHAVIOUR
>
> **Added 2026-08-24, same day as the rest of this document. E-sign has since been
> completely redone (Johan's words: "esign was completely redone - so do not touch it").
> Everything below describes the PRE-REBUILD implementation — the code this document
> traces (`SignatureService::sendSigningRequestEmail()`, the `completeWeb` submit floor,
> the identity-verification gate, the recipient-setup flow) no longer exists in its
> investigated form.**
>
> Do not treat the "Fixed" / "Still present" determinations below as applying to
> current code, do not open work against the "Pre-1-September list," and do not use
> this document as a reference for how e-sign behaves today. It is a historical record
> of why one agent's go-live attempt failed on the OLD implementation — nothing more.
> If you're reading this because you're about to touch e-sign: read the current code,
> not this file.

**Read-only investigation.** Nothing restored, resent, or re-triggered. Grew out of the
stranded-signed-document scoping (`perf-sweep-and-blank-pdf-findings-2026-08-23.md` §4,
corrected `2026-08-24`), which itself grew out of a false alarm — but the false alarm
surfaced a real, previously un-investigated fact: **agent Maggie Venter attempted a real
e-sign go-live in March–May 2026, it failed badly enough that she abandoned it for manual
paperwork, and nobody had ever asked why.** That failure is a preview of what happens at
scale on 2 September unless the mechanisms behind it are understood. This document is that
investigation.

## The dataset

63 `signature_templates` created Feb–Jun 2026, agency 1 (Home Finders Coastal, the real
flagship tenant — not a demo agency). 37 of them created by Maggie Venter (`users.id=29`):

| Status | Count |
|---|---|
| completed | 8 |
| cancelled | 20 |
| rejected | 3 |
| expired | 6 |

Of the 20 cancelled, **9 are Maggie's own internal QA testing from 2026-03-06 to 03-09**,
identifiable by their `rejection_reason` at the time (`"testing"`, `"Test run"`, `"Testing
system"`, `"testing documents"`) even though several were later bulk-relabeled with a
`cancellation_reason` of "Signed with wet ink" during a 2026-05-04 cleanup pass — that
later label describes what eventually happened to the *cleanup*, not what happened at the
time, and these should not be read as real failed attempts. Excluding those 9 (#9, 10, 11,
12, 13, 15, 17, 18, 19, 25, 26 — 11 total once #13/18/26, three more with no timestamped
rejection reason at all, are folded in as the same class of same-day zero-engagement test
row), **the real production attempts are: 8 completed, 3 wrong-email cancellations, 6
expired, 3 rejected (by the agent, not the recipient), 1 stuck-submission cancellation, and
2 more cancellations with non-technical reasons** ("Already received the documents",
"Received wet ink documents" — the client provided a physical copy through another channel
before or during the e-sign attempt, not a technical failure).

## Stage-by-stage funnel, real attempts only

| # | Outcome | Request created | Invite sent | Recipient viewed | Recipient signed | Reached agent approval | Completed |
|---|---|---|---|---|---|---|---|
| 8 completed | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| #34,35,36 (wrong email) | ✅ | ✅ (`sent_at` populated) | ❌ never | ❌ | ❌ | ❌ | Cancelled after 2 reminders + team alert, ~9 days |
| #41,44,45,47,48 (expired, zero engagement) | ✅ | ✅ (`sent_at` populated) | ❌ never | ❌ | ❌ | ❌ | Expired after reminders + team alert |
| #49 (expired, partial engagement) | ✅ | ✅ | ✅ | partial (`identity_verification_failed` ×2, then verified) | ❌ never signed | ❌ | Expired |
| #53 (identity verification failures) | ✅ | ✅ | — | `identity_verification_failed` ×5 in a row | ❌ never | ❌ | Cancelled, "Received wet ink documents" |
| #57 (stuck submission) | ✅ | ✅ | ✅ | ✅ signed everything (marker-based capture) | **saved but never reached `pending_agent_approval`** | ❌ | Cancelled, "Client can't submit after completion" |
| #38,39,42 (agent-rejected) | ✅ | ✅ | ✅ | ✅ | ✅ reached `pending_agent_approval` | **rejected by agent** | Rejected — "your wife needs to sign too" |

**Where they die, by count**: 5 never got past "invite sent, nobody engaged" (silent
delivery failure, most likely explanation below). 1 died at the identity-verification gate
(5 straight failures — an agent data-entry error, not a code defect). 1 died at the final
submit button despite a fully complete signing (a real server-side bug). 3 died at the
agent's own final review (a real product gap, not a technical failure — see below). 3 never
had a chance because the email address was wrong from the start.

## Mechanism 1 — silent invite-send failures (highest-confidence finding, explains the largest bucket)

**What Maggie experienced**: click "Send", the request shows as sent, the recipient's copy
never arrives, nobody — including Maggie — has any way to know it failed.

**Root cause, confirmed via git history, not inferred.** The code live during Maggie's
attempts (`SignatureService::sendSigningRequestEmail()`, as it existed before
`f5d606a66`) stamped `sent_at = now()` **before** attempting `Mail::to(...)->send(...)`,
and on failure only logged server-side (`Log::error`) — no status change, no
agent-visible error, no retry path:

```php
// Pre-AT-294 (live March–May 2026)
$request->update(['sent_at' => now(), ...]);   // stamped unconditionally, BEFORE the send
...
try {
    Mail::to($request->signer_email)->send(...);
} catch (\Throwable $e) {
    Log::error('Failed to send signing request email', [...]);   // and that's it
}
```

A `signature_requests` row with `sent_at` populated and `status=pending` looked
*identical* whether the email genuinely sent or silently failed. This matches "sent docs
out that never landed" precisely — and matches the 5-6 "expired, zero engagement" rows in
the dataset, all of which show `sent_at` populated with zero subsequent recipient activity
despite reminder escalations.

**Status: FIXED.** `f5d606a66` (`feat(AT-294): resend recipient e-sign emails + surface
send failures honestly`, 2026-07-22 — ~2 months after Maggie's attempts) rewrote this to
only stamp `sent_at`/`invite_send_status=sent` **after** confirmed send success, set
`invite_send_status=failed` + the actual error message on failure, and surface "Send
failed — {reason}" to the agent with a Resend action
(`SignatureService.php` current `sendSigningRequestEmail()`, ~line 4635).

**Confidence: high.** The mechanism is proven from the diff itself, not inferred from
symptoms. What is *not* independently confirmed: whether the underlying mail-transport
problem that caused the original sends to fail (SMTP config, rate limiting, spam filtering)
is itself fixed, or only the *visibility* into failure is fixed. AT-294 means a September
failure will be seen and retryable — it does not by itself guarantee fewer failures happen.
Worth a live send-success-rate check before 1 September, not assumed clean from this fix
alone.

## Mechanism 2 — "submit floor" false-positive on marker-based signatures (explains #57 exactly)

**What the client experienced, in the agent's own words at the time**: "Client can't
submit after completion" — they'd signed everything, the UI said ready to submit, and
submission failed.

**Root cause, found via a later fix describing the identical symptom.** Commit
`c7c80647b` (`fix(esign recipient sign): P0 submit floor false-positive...`, 2026-08-07,
cc6) — commit message: *"'Ready to submit' but Submit failed 'no signature was
captured'."* Root cause per that commit: the `completeWeb` floor check
(`SigningController.php`, `AT-293`) read only the POST body's inline signature/initial
captures — but a recipient whose signature places are positioned DB markers signs each via
a *separate* earlier `POST /capture/{id}` request that persists directly to a `Signature`
row, never entering the `completeWeb` POST body at all. The client-side enable-gate
(DOM-derived) correctly counted these as signed and enabled the Submit button; the
server-side floor check didn't, and 422'd with "no signature was captured" on a genuinely
complete signing.

`#57`'s audit trail (`signed` ×8 via marker capture → `fields_saved` ×2 while retrying →
never reaches `pending_agent_approval`) is the exact shape this bug produces.

**Status: FIXED.** `c7c80647b`, 2026-08-07 — the floor now also checks
`$signingRequest->signatures()->exists()` (the authoritative persisted-evidence source),
not just the POST body. Shipped with its own regression test
(`WebCompletionRequiredGateTest`).

**Confidence: high** on the mechanism match (identical symptom, identical component,
identical timeframe of being broken). Not 100% certain this is *the* cause of #57
specifically (no direct log correlation survives from May), but strong enough that treating
it as resolved is reasonable.

## Mechanism 3 — no confirmation step for agent-entered recipient email/ID number (still present, not a code defect)

**What happened**: 3 cancellations explicitly reason "Wrong email" (#34, 35, 36 — same
mistyped address, `emclememtz@mweb.co.za`, reused across 3 separate documents, meaning it
wasn't corrected between attempts). Separately, #53 shows 5 consecutive
`identity_verification_failed` events before the agent gave up and went to wet ink —
`SigningController::verify()` is a plain case-insensitive string match against
`signer_id_number`, which the agent enters at request-creation time; 5 straight failures
from a real recipient strongly implies the agent entered the wrong ID number, not that the
recipient forgot their own.

**Root cause**: neither the email field nor the ID-number field has any confirmation,
double-entry, or verification step before the invitation is dispatched. A typo is
undetectable until the recipient (or the identity gate) fails, by which point a reminder
cycle and team alert have already fired.

**Status: STILL PRESENT.** No confirmation/verification UI found in the current recipient
setup flow (`ESignWizardController.php`) or the identity-verification code path
(`SigningController::verify()`, current). This is not a bug in the sense of broken logic —
the code does exactly what it's told — it's a missing safeguard against a data-entry
mistake that has already, demonstrably, cost real signing attempts.

## Mechanism 4 — no co-signer/spousal-signer support at the time (lower confidence, likely addressed since)

3 rejections (#38, #39, #42) show full recipient completion followed by the *agent*
rejecting at final review with the same reason each time: "Your wife needs to sign and
initial as well, please." — the flow at the time apparently had no way to add a required
second signer (a spouse/co-owner) to a mandate, forcing a full agent-review catch-and-reject
cycle rather than preventing the gap at setup.

**Status: likely addressed, not independently confirmed to the same evidentiary standard
as 1–2.** Current code (`SignatureService.php`, `ESignWizardController.php`) contains a
"candidate flow" / authorising-practitioner-parity mechanism (co-signer routing,
authoriser marks, full-parity signer support per its own docblocks) that reads like it
covers exactly this gap, but confirming it would have prevented #38/#39/#42 specifically
needs a closer look at that feature's scope, not done as part of this pass.

---

## Pre-1-September list

| # | Finding | Severity | Status | Proposed action |
|---|---|---|---|---|
| 1 | Invite-send failures were silent, no agent visibility | High (was) | **Fixed 2026-07-22** (AT-294) | None needed for the code fix. **Do**: run a live send-success-rate check before 1 Sept — the fix makes failures visible, it doesn't prove fewer failures happen. |
| 2 | "Submit floor" false-positive rejected genuinely complete marker-based signings | High (was) | **Fixed 2026-08-07** (`c7c80647b`), regression-tested | None — verify `WebCompletionRequiredGateTest` is still green as part of normal pre-launch checks. |
| 3 | No confirmation step for agent-entered recipient email/ID number | Medium | **Still present** | Add a "confirm recipient email" step (re-type or a review screen before send) and consider surfacing the identity-verification failure count to the agent (not just the recipient) after 2+ failures, so a typo'd ID number is caught before the recipient gives up. Scope + build after 1 Sept unless trivial. |
| 4 | No co-signer/spousal-signer support caused avoidable agent-review rejections | Low–Medium | **Likely addressed** (candidate flow), not independently confirmed | Confirm the candidate-flow feature actually covers the spousal-co-owner case before relying on it; if it does, no further action — if not, scope as its own item. |

## What this does NOT establish

- Whether the mail-transport layer itself (not just the visibility into its failures) had
  a real reliability problem in March–May that could recur — out of scope for a code-only
  read; would need mail-server-side logs from that window, which were not checked.
- Whether mechanism 4 genuinely covers the March–May gap — flagged as lower confidence
  above, not confirmed.
- Whether any OTHER agency would hit these same mechanisms differently — this investigation
  is scoped to Maggie's attempts specifically, since that's the only real data available.
