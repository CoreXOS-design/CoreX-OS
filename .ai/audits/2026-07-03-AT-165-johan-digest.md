# AT-165 Offline Draft Persistence — Digest for Johan

> **2026-07-03. SPEC + investigate only — NO build until you assign it** (builder = Andre or CC; the spec is the
> handoff either way). Full spec: `.ai/specs/offline-draft-persistence.md`. As-built audit (verified):
> `.ai/audits/2026-07-03-form-draft-audit.md`.

## The one-paragraph version
When the SA line drops, a laptop sleeps, or a session expires mid-capture, an agent loses everything they typed —
we've already lost an hour of real property capture this way. The fix is a **single reusable client-side draft layer**
any long form registers into: as the agent types, form state is continuously saved to the browser; on return the form
offers *"Unsaved changes from 14:32 — Restore / Discard."* It works with **zero network** (that's the whole point),
clears itself on a successful server save, and **never persists sensitive PII** (ID/tax/bank/medical/credentials —
enforced by a per-form allowlist, not a denylist). No new tables, no new endpoints — 100% client-side.

## The 5 decisions I need you to confirm
1. **Client-side draft is the primary — confirmed correct.** I stress-tested it against every alternative (server
   autosave, session hardening, full offline-first). Only a client-side store survives a dead network, which is the
   actual failure. **See the alternatives table (spec §2A).**
2. **Pair it with two cheap complements** to kill the incident *class*, not just recover from it:
   - **Resilient submit** — on a failed POST (offline / 419) don't blank the form; hold the payload, retry when back
     online. This directly prevents the *proximate* cause of the original incident.
   - **Session keepalive + an offline indicator** — stops the "session expired on submit" 419 and shows the agent
     their work is safe. Both small. **Do you want these folded into AT-165, or split into a fast-follow ticket?**
3. **Scope is bigger than the first audit said.** The original list was 15 forms; verification found **~26** (payroll
   banking, admin-user PII, whistleblow, commercial eval, etc.). Recommend a **phased roll-out** (module + property
   capture first, then S, then M, then L) — not a big-bang wiring.
4. **FICA may opt OUT of drafting.** It's 125 fields but almost all are ID/tax/VAT/funding/signature PII. After
   excluding those, the safe residue is tiny. Cleaner to **not draft FICA at all** than to half-draft it. Your call.
5. **Multi-tab = last-write-wins** (BroadcastChannel-coordinated), not tab-locking — locks strand drafts when a tab
   crashes. Matches how the existing `hub` pattern already behaves. Justified in spec §5.

## What's honestly OUT of scope (named so we don't pretend)
Full **offline-first sync** — service worker, a background-sync queue that replays *submissions* when the network
returns, offline reads, conflict merging. That's a separate programme (its own ticket). This layer persists
*in-progress input on the device*; it does **not** queue a submit to replay, and it **never tells the user their work
is "saved"** — only "draft saved on this device." The client store we build is the first brick of that future system.

## POPIA in one line
Drafts hold personal data in the browser on one device, so: an **allowlist** (only declared fields persist — a field
not listed is structurally impossible to leak), a **"clear my local drafts"** control in My Portal, TTL expiry
(default 7 days, configurable), and a hard exclusion of every ID/passport/tax/VAT/bank/medical/credential/signature
field — with the exact field names verified per form in the audit.

## Verification note (why you can trust the audit)
Every load-bearing claim was independently re-checked 2026-07-03: the reference impl anchors (confirmed exact), the
FICA/staff-take-on sensitive-field anchors (confirmed, with 2 corrections — FICA `:797` was a tooltip not a field;
staff take-on has **no** ID field, contrary to the first draft), and a completeness sweep that caught the 11 missed
forms. Corrections are marked `[CORRECTED]` in the audit.

## Recommended next step
Approve the spec (with your calls on #2 and #4 above), commit to the spec branch per the spec-sync rule, then assign
the build. First PR = the shared module + property capture as the proving form; nothing else until that pattern is
proven in the browser.
