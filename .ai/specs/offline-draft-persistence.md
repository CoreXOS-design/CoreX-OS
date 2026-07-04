# CoreX — Offline Draft Persistence (forms-wide client-side autosave)

> **Status:** SPEC — await Johan approval. NO build until assigned (builder may be Andre or CC; this spec is the
> handoff artifact either way). **Jira:** AT-165.
> **Owner:** Johan (product) · **As-built audit:** `.ai/audits/2026-07-03-form-draft-audit.md`.
> **Pillars:** cross-cutting (protects capture into Property, Contact, Deal, and compliance forms).
> **One-line:** as an agent types, form state continuously persists to the browser and survives
> sleep / disconnect / crash / accidental close; on return the form offers to restore the unsaved work.

## 1. Purpose — the incident this prevents
SA connectivity drops; laptops sleep; sessions expire. Real incident: an agent captured a property, the laptop
slept, the DB session dropped on submit, **an hour of capture was lost**. CoreX's principle is "we do complicated
so the user does simple" — losing typed work is the opposite. This layer makes unsaved work **durable on the
device** until it is either saved to the server or explicitly discarded.

## 2. Scope & boundary (named honestly)
**IN scope:** a single reusable **client-side** draft layer that any long form registers into; per-user/-form/
-record keying; debounced autosave; restore-on-return; clear-on-successful-save; POPIA-safe field allowlisting;
a "clear my local drafts" control; a stale-record conflict warning.

**OUT of scope (explicitly, so this layer does not pretend to be it):** full **offline-first sync** — a service
worker, a background-sync queue of *submissions*, offline reads of server data, or conflict *merging*. This layer
persists **in-progress form input on the device**; it does **not** queue a submit to replay when the network
returns, and it does **not** make CoreX usable with no server. That is a separate, larger programme (name it
"AT-xxx offline-first sync") with its own spec. The draft layer's honesty contract: **it never tells the user
their work is "saved" — only "draft saved on this device."**

## 2A. Alternative solutions considered — "is there a better fix for the internet-loss problem?"
The incident: a connection/session drop **on submit** discarded an hour of typed capture. The full solution space,
ranked honestly. **The disqualifier that shapes everything:** the failure *is the network being gone*, so any fix
that needs the network *at the moment of loss* cannot be the primary defence. That single fact eliminates
server-side approaches as the primary answer.

| Option | What it is | Verdict |
|---|---|---|
| **B. Client-side draft autosave** (this spec) | Writes form state to browser storage as you type; never leaves the device | **Correct primary.** The only approach that works with **zero network**. Weaknesses (single-device, PII-on-device, no cross-device resume) are bounded by TTL + allowlist + clear-on-save. |
| A. Server-side incremental autosave | POST each field/step to a draft record (as e-sign & property wizard already do) | Excellent *complement* (survives device loss/theft, cross-device resume, no PII-on-device) but **useless as the primary** — it needs the very network that failed. Best used as the flush target once connectivity returns. |
| **C. Resilient submit (hold-payload-and-retry)** | On a failed submit (offline / 419 CSRF / 5xx) **don't navigate away**: keep the payload (backed by the client draft), refresh CSRF, show "couldn't save — retrying," auto-retry when `navigator.onLine` returns | **Strongly recommend pairing with AT-165.** The incident's *proximate* cause was a failed POST that blanked the form. Draft autosave *recovers* from that; resilient submit *prevents* it. Cheap, per-form. Together they mean the agent rarely even sees a loss. |
| D. Session-expiry hardening | Keepalive heartbeat on long forms + CSRF token refresh + longer session lifetime on capture routes | Attacks the specific "DB session dropped on submit" (419) failure mode that draft autosave only recovers from. Cheap add-on. |
| E. Connectivity awareness | Online/offline indicator ("You're offline — saving on this device") + `beforeunload` guard | Doesn't save data itself but converts silent loss into a visible, trusted state. Cheap, high-trust, pairs with B. |
| F. Wizardisation / shorter forms | Break long captures into small server-saved steps | Partial mitigation, large UX rework, no help *within* a step. Structural nice-to-have, not a substitute. |
| **G. Full offline-first sync** | Service worker + background-sync queue of submissions + offline reads + conflict merge | The **north star superset** (§2 boundary). A programme, not a layer. AT-165's client draft store is its first load-bearing brick — build the keyed store so the future sync queue can adopt it. |

**Recommendation:** client-side draft autosave (B) is the right primary — nothing else survives a dead network. To
actually kill the *incident class* rather than just recover from it, pair AT-165 with **C (resilient submit)** and
**D/E (session keepalive + connectivity indicator)**. Those three are small, complementary, and each attacks a
different link in the failure chain. The hybrid client→server flush (A) and full offline-first (G) are the roadmap
*beyond* this ticket. This spec builds B and is written so C/D/E slot in without rework.

## 3. The one layer (not per-form hacks)
Three client-side draft precedents already exist in the codebase (audit "Existing client-side persistence"):
`marketing/hub` (localStorage, long-term — the reference), `command-center/calendar` create-event (sessionStorage,
view-switch survival), and `docuperfect/templates/cds-builder` (its own `manualSaveDraft`). The module **generalises
hub and reconciles the other two** so CoreX ships one draft layer with one "draft saved" language, not three dialects.

Generalise the **existing** reference implementation `resources/views/marketing/hub.blade.php:27-58` (per-record
key, `$watch` allowlist, restore, clear-on-publish) into a single Alpine-compatible module. Ship it as an
Alpine plugin/mixin registered globally (e.g. `resources/js/draft-persistence.js`, imported in the app bundle),
exposing a small contract a form's `x-data` composes in:

```
draft({
  form: 'property_capture',              // stable form identifier
  recordId: {{ $property?->id ?? 'null' }}, // null = "new"; an id = edit-this-record
  fields: ['title','suburb','price', …], // ALLOWLIST (POPIA §7) — only these are persisted
  version: 3,                             // server record version/updated_at for the conflict check (§8)
  autosaveMs: 1500,                       // debounce (agency-configurable default)
  ttlDays: 7,                             // expiry (agency-configurable default)
})
```
It provides: `draftRestoreAvailable` (bool), `draftSavedAt` (timestamp), `restoreDraft()`, `discardDraft()`,
`clearDraft()` (call on successful submit), and an internal debounced `_persist()` wired via a single
`$watch`-all over the allowlisted fields. Plain-POST forms (no `x-data`) opt in by wrapping the `<form>` in a
thin `x-data="draft({...})"` that serialises the allowlisted inputs on `input` — no per-field `x-model` rewrite
required (keeps the M-effort forms cheap; see the audit's effort buckets).

**Server touch = none.** This is 100% client. No new tables, no new endpoints. (The optional conflict check in
§8 reuses data already rendered into the form — the record's `updated_at`/version.)

## 4. Storage backend — chosen per payload size
- **`localStorage`** for small forms (≤ ~50 KB serialized: most captures — contacts, deals, presentations,
  rentals, onboarding). Synchronous, simplest, 5–10 MB origin budget.
- **`IndexedDB`** for large forms (property capture ~34 fields, **FICA ~125 fields**, company-settings ~106,
  staff-take-on 8 steps) and any form the module detects exceeds a size threshold, or where multiple large
  drafts could coexist. Async, far larger quota, avoids evicting other localStorage.
- The module **auto-selects** by declared size / measured payload (a `storage: 'auto'|'local'|'idb'` override
  on the contract). One key-space, one API surface, backend chosen internally so forms don't care.

## 5. Draft keying + multi-tab safety
- **Key** = `corex.draft.v1.{userId}.{form}.{recordId|'new'}` — **per user + per form + per record**. Editing
  property 123 and creating a new property are different drafts; two agents on a shared device don't collide
  (userId from the authenticated page context). A wizard keys per-step under the same record namespace
  (`…{form}.{recordId}.step{n}`) so a multi-page form (staff-take-on, property wizard) restores the right step.
- **Multi-tab safety = last-write-wins, coordinated by a `BroadcastChannel`** (fallback: the `storage` event).
  Decision + justification: a hard **tab-lock** (one editor, others read-only) is heavier than the risk — two
  tabs editing the *same* record is rare, and a lock strands a draft if a tab crashes without releasing. Instead:
  each `_persist()` stamps `{tabId, savedAt}`; on write, a tab broadcasts; a peer editing the same key shows a
  quiet "this draft is also open in another tab — newest edit wins" note. Last-write-wins is deterministic
  (timestamp), self-healing (no stuck locks), and matches how the reference `hub` pattern already behaves. The
  restore path always loads the **newest** stamped draft for the key.

## 6. Lifecycle
- **Autosave:** debounced write on allowlisted-field change (`autosaveMs`, default **1500 ms**,
  agency-configurable). Also a `visibilitychange`→hidden and `beforeunload` flush so sleep/close captures the
  last keystrokes.
- **Expiry:** drafts carry `ttlDays` (default **7**, agency-configurable). On module init, any draft past TTL for
  this user is purged. A global sweep purges *all* expired keys (cheap, on load).
- **Clear-on-successful-save:** the form calls `clearDraft()` in its submit-success handler (the one contract
  requirement on each form). A draft is never left behind after the server accepted the data.
- **Explicit discard:** the restore banner (§8) offers **Discard** (removes the key immediately, with a one-step
  undo toast). A "Discard draft" affordance is also available while editing.
- **Storage-quota handling:** all writes are wrapped; on `QuotaExceededError` the module (a) purges expired
  drafts and retries once, then (b) if still full, evicts the **oldest** draft for this user and retries, then
  (c) if still failing, stops autosaving silently and shows a subtle "couldn't save draft — storage full"
  indicator (never throws into the form, never blocks typing). Degraded, never broken.

## 7. POPIA
- **What is stored:** only the allowlisted fields (§3), as JSON, in the browser's origin storage on **that one
  device**. Never sent anywhere; never in the DB or a cookie. It is exactly the data the user is already typing
  into the form on screen.
- **Per-device exposure:** a draft is readable by anyone with access to that browser profile until it is saved,
  discarded, or expires (≤ TTL). This is the same exposure as a half-typed form left on screen, bounded by TTL
  and clear-on-save. On a **shared device** the per-user key prevents cross-user restore, but note (in the UX)
  that local drafts live on the machine — a shared-workstation agency may set `ttlDays` low.
- **"Clear my local drafts" control** — a user-facing action (in My Portal / profile) that enumerates and wipes
  all `corex.draft.v1.{thisUser}.*` keys across both backends. One click, immediate, audited only client-side
  (no server record needed — there's nothing server-side to audit).
- **NEVER stored — enforced by ALLOWLIST, not denylist:** the module persists *only* declared fields, so a field
  omitted from the allowlist is structurally impossible to persist. Fields that must never appear in any
  allowlist: `id_number`, `passport`, `tax_reference_number`, bank (`account_number`, `branch_code`, `bank_name`,
  `account_holder`, `account_type`), `medical_aid_number`, source-of-funds/funding narratives, and any
  `password`/`api_key`/`secret`/`token`/SMTP-credential field. FICA and staff-take-on (the highest-PII forms)
  therefore persist only their non-sensitive fields; their ID/banking/funding steps are excluded entirely.
  A build-time lint (a simple test asserting no allowlist contains a blacklisted key) makes this non-negotiable.

## 8. UX
- **"Draft saved 14:32" indicator** — a subtle, non-intrusive line near the form's save button, updated on each
  persist (mirrors `today.blade.php`'s "Updated HH:MM" stamp pattern). Never a modal, never blocking.
- **Restore banner on return** — when a form loads and a fresh draft exists for its key: a quiet banner
  *"Unsaved changes from {relative time} — Restore / Discard."* Restore repopulates the allowlisted fields (and
  jumps to the saved wizard step); Discard removes the draft (with undo). If the user ignores it and starts
  typing, autosave overwrites the old draft (last-write-wins).
- **Conflict rule (stale server record)** — if the underlying server record's version/`updated_at` (rendered
  into the form, passed as `version`) is **newer** than the draft's captured base version, the restore banner
  becomes a **diff-aware warning**: *"This record was changed by someone else since your draft. Review before
  restoring."* — it lists which allowlisted fields differ (draft vs current server value) and lets the user pick
  per-field or cancel. **Never silently overwrites** newer server data. (For "new" records there is no base
  version, so no conflict path.)

## 9. Per-form registration (from the audit)
**Scope note:** the audit's original 15 forms was **not complete** — a verification sweep found ~11 more qualifying
long forms (commercial evaluation, payroll take-on, admin user create/edit, whistleblow intake, calendar/task create,
doc-pack & ad builders, training content) plus 2 already-drafting precedents (calendar, cds-builder). The real
roll-out surface is **~26 forms**, so §9's phased order matters more than first thought — do NOT wire all at once.
Each form opts in by composing `draft({...})` with its allowlist. Effort buckets (full table in the audit):
- **S (already Alpine):** deals-v2 create, property wizard (bridge pre-server-draft only), prospecting contact,
  FICA (strict allowlist).
- **M (plain POST → thin x-data wrapper + serialize):** property create-edit, presentations create & compute,
  onboarding, rentals, contact match, buyer detail.
- **L (no Alpine, large surface, most PII):** DR1 deals, staff-take-on (per-step keying + sensitive-step
  exclusion), company-settings (exclude credential panels).
- **Skip (server-owned):** e-sign wizard (already server-autosaves) — do not double-persist; property wizard &
  e-sign use the client layer only for the pre-server-draft window.

Roll-out order: land the module + the reference form (property capture) first, prove the pattern, then the S
forms, then M, then L. Each form's PR includes its allowlist and a note of excluded sensitive fields.

**Wired so far (as-built):** property capture (reference), rental capture, presentation builder, commercial
evaluation (create + edit), agent onboarding (id_number excluded), contact match (typed-field subset only — the
Alpine chip/feature state and the external suburb picker are excluded as a form-scan restore cannot rehydrate
Alpine arrays). Each has a `CoreXDraft::clearOnSave()` in its controller success path.
**Deliberately left for later / special handling:** FICA + staff take-on (POPIA — mostly excluded fields; may opt
out), DR1/DR2 deals (wizard/axios), company-settings + admin-user (credential panels), e-sign (server-owned),
calendar/task (own persistence), and any multipart file-upload form (e.g. property capture uses draft only, not
resilient-submit).

## 9A. Complements folded in (from §2A alternatives — approved for this build)
Client-side draft *recovers* work; these two cheap complements *prevent* the loss so the agent rarely sees it. Both
ship in the same increment as the draft layer, as separate opt-in modules so a form can adopt any subset.

**Resilient submit (complement C)** — `resources/js/resilient-submit.js`. Wraps a form's submit: on a failed POST
(offline / 419 CSRF / 5xx) it does NOT blank the form — it holds the payload (the live draft is already the backing
store), refreshes the CSRF token from the `meta[name=csrf-token]`, shows a quiet "Couldn't reach the server — your
work is safe; retrying…" line, and auto-retries when `navigator.onLine` returns (with a manual "Retry now" button).
Opt in with `data-resilient-submit` on the `<form>`. For full-page-POST forms it intercepts submit, POSTs via fetch,
and on success follows the redirect; on failure it stays put with the form intact.

**Session keepalive + offline indicator (complements D + E)** — `resources/js/session-keepalive.js` + a global
indicator. While a registered long form is on screen, a lightweight heartbeat pings `GET /api/v1/session/ping`
(registered, name `api.v1.session.ping`, discoverable in the API catalog per non-negotiable #7) on a configurable
interval (default 4 min, under the session lifetime) so a 40-minute capture doesn't lapse into a 419 on submit. A
global connectivity indicator (driven by `online`/`offline` events) shows "Offline — your work is being saved on this
device" so silent loss becomes a visible, trusted state. The ping is a no-op JSON `{ok:true}` that also refreshes the
session cookie; it is skipped when `navigator.onLine` is false (no point pinging into a dead network).

**Clear-on-save honesty (full-page-POST forms)** — a pure-client layer cannot detect a successful submit *after* the
browser redirects away, so clear-on-save for redirect forms uses a minimal, reusable server signal: on a successful
`store`/`update` the controller calls `CoreXDraft::clearOnSave($form, $recordId)` (helper flashes
`corex_clear_drafts` to the session); the layout renders it into a `meta[name=corex-clear-drafts]` tag; the module
clears those keys on the next load. AJAX forms (deals-v2, e-sign) call `clear()` directly and need no server signal.
This is the one deliberate, honest server touch — everything else remains 100% client. (Supersedes the "server touch
= none" line in §3 for the specific case of redirect-based forms.)

## 10. Doctrine
- **Configurable:** `autosaveMs`, `ttlDays`, storage backend threshold, and per-form allowlists — agency/global
  configurable where it makes sense (autosave interval, TTL); allowlists are per-form code (not user-editable).
- **Never blocks typing / never 500s:** every storage op is wrapped; failures degrade to a quiet indicator.
- **Client-only, honest:** no "saved" language for drafts; the server save is the only thing that says "saved".
- **Allowlist over denylist** — the single most important POPIA rule (a field not listed cannot leak).

## 11. Acceptance criteria
- Typing in a registered form, then sleeping/closing the tab, then reopening → the restore banner appears with
  the correct relative time; Restore repopulates every allowlisted field (and the right wizard step).
- A successful server save clears the draft (reopening shows no banner).
- Editing record 123 and a new record keep separate drafts; a second tab editing the same record resolves
  last-write-wins with the "also open elsewhere" note, no stuck lock.
- No sensitive field (ID/bank/tax/medical/funding/credential) is ever written to storage — verified by the
  allowlist lint on FICA and staff-take-on.
- "Clear my local drafts" wipes all of this user's drafts across localStorage + IndexedDB in one action.
- A stale-record conflict shows the diff-aware warning and never silently overwrites newer server data.
- Storage-full degrades to a quiet indicator; the form remains fully usable.
- e-sign and property wizard are not double-persisted (server draft remains the source once a draft id exists).
