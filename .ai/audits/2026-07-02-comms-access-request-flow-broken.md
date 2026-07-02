# Investigation — per-thread comms access-request flow broken/incomplete

**Date:** 2026-07-02 · **Type:** investigation ONLY (no code; Johan approves fix + policy) · **Env:** staging (`hfc_staging`) · **Relates:** AT-118/AT-132 (comms access gate), AT-152 (noise filter), AT-137 (owner display)
**Scenario:** Barbara (agent, user 36) requested access to Elize's (contact 10298) WhatsApp thread `222758646611979@lid`. Her UI shows "Requested — awaiting approval". Johan, viewing `/comms-access/inbox` as the **System Owner** account, sees "No pending requests". The thread's agent shows "Unassigned".

## The actors (staging data)

| user | name / email | agency_id | role | note |
|---|---|---|---|---|
| 36 | Barbara Jackson `barbara@hfcoastal.co.za` | 1 | agent | the requester |
| 22 | Johan Reichel `johan@hfcoastal.co.za` | 1 | admin | agency-1 Johan — `grant_access` = **true** |
| 46 | Johan Reichel `johan@corexos.co.za` | **NULL** | super_admin | platform System Owner — **owns the thread** (owner_user_id=46) |

- Contact 10298 (Elize): `agency_id=1`, `agent_id=NULL`.
- Request row `comms_access_requests.id=15`: agency 1, contact 10298, requester 36, `thread_key=222758646611979@lid`, **status=pending**, `expires_at` today 23:59 (valid). **The request was written correctly.**
- All 22 comms in the `@lid` thread carry `owner_user_id=46`.

---

## 1. REQUEST NOT ROUTING — root cause (NOT a write bug; a canAuthorize agency-gate + wrong-account issue)

**Path:** `CommsAccessRequestController::store` (`:28`) → `CommsAccessGrantService::requestAccess` (`:138`) writes the row (agency = `$contact->agency_id` = 1) and calls `notifyApprovers` (`:460`) → `eligibleApproverIds` (`:440`). On staging `eligibleApproverIds(10298) = [46, 22, 23, 43, 45]` (owner 46 ∪ agency-1 `grant_access` holders). **So the request routed and notified the correct agency-1 approvers.**

**The inbox** (`CommsAccessRequestController::inbox`, `:168`) lists `pending()` requests then `->filter(fn($r) => canAuthorize($user,$r))` (`:178`).

**`canAuthorize`** (`CommsAccessGrantService::426-437`):
```php
if ((int) $user->agency_id !== (int) $req->agency_id) return false;   // FIRST — hard reject
if ($user->hasPermission('communications.grant_access')) return true; // never reached for a null-agency owner
```
Verified on staging (`canAuthorize`):
- **user 22** (admin, agency 1) → **TRUE** (`grant_access=true`) — request 15 IS in his inbox.
- user 36 (requester) → false (correct).
- **user 46** (System Owner, agency **NULL**) → **FALSE**, even though `grant_access=true` and he owns the thread — because `(int)NULL = 0 !== 1` rejects him on the agency line **before** the grant/owner branch, and it uses raw `$user->agency_id` (not `effectiveAgencyId`, no owner-role bypass).

**Exact break:** Johan viewed the inbox as the **System Owner account (user 46, agency NULL)** — the very account that OWNS the thread — and `canAuthorize` filters request 15 out of his inbox on the strict agency-equality check → "No pending requests". The request is fully valid and IS visible/approvable to the agency-1 admin account (user 22, `johan@hfcoastal.co.za`). So it's (a) an account/identity confusion (two Johan accounts: agency admin vs platform owner) compounded by (b) a real gap: `canAuthorize`/`canRevoke` give the platform owner no way through the agency gate even though he bypasses `AgencyScope` everywhere else and holds `grant_access`.

The "Unassigned" agent is a **contributing cause but not the routing break** — see §2/§4.

---

## 2. OWNER IDENTITY NOT SHOWN — root cause

The thread list is built in `ContactController::show` (`:391-443`); the owner label is
`'owner_name' => $latest->owner?->name` (`:426`). The gated row renders `_comm-thread-meta.blade.php:28` → `Agent: {{ $thread->owner_name ?: 'Unassigned' }}`, and the gated block (`show.blade.php:2020`) adds only the text **"Private to the owning agent"** — no name, no contact affordance.

**Why "Unassigned":** `owner` is `belongsTo(User, owner_user_id)`, and **`User` has `AgencyScope`** (confirmed). The owner is user 46 (`agency_id=NULL`). Loading `$latest->owner` **as Barbara (agency 1)** returns **NULL** (proven via tinker: `owner_user_id=46`, `owner?->name → NULL → "Unassigned"`; user 46's real name is "Johan Reichel"). A null-agency owner is invisible to an agency-1 viewer through the scoped relation.

**Gap:** even when a thread HAS an owner, the requester is shown neither the owner's name nor any way to contact them — the owner info exists (`owner_user_id`) but is (a) not resolved when the owner is agency-hidden, and (b) never surfaced as an actionable "ask X for access". This is the exact place the requester needs the owner's identity.

---

## 3. MULTI-AGENT-SAME-CONTACT — the current visibility model

The comms tab (`show.blade.php:1856-2045`) lists **every** thread linked to the contact, grouped by `thread_key` (`ContactController` groups the contact's comms; `commsTabVisible` = any comms-capable user, `:448`). Per thread it renders `_comm-thread-meta` (SAFE metadata) and gates the BODY via `is_visible`.

Per thread, a viewing agent CAN see:
- **(a) that the thread exists** — YES (every linked thread is listed, visible or not).
- **(b) whose it is** — the owning agent's NAME via `owner_name` (`:426`) — **unless** the owner is agency-hidden/null-agency, then "Unassigned" (§2). So identity is *intended* to show but leaks nothing beyond the name; a hidden owner shows nothing.
- **(c) the content** — **GATED**. `is_visible` (`:435`, from `applyArchiveVisibility`/`visibleTo`) must be true; otherwise the row shows "Request access" and the body is never rendered. Verified: bodies are protected by the same `applyArchiveVisibility` used by the archive (`CommunicationArchiveController::thread` also filters + 404s).

Additionally EXPOSED as safe metadata: channel, latest date, message count, attachment flag, and **subject** — but the owner can hide the subject from non-readers (`subject_hidden`, hide-subject toggle, `CommsThreadSetting`). GATED: message bodies, attachments/audio, raw payloads.

**So the model is:** metadata surface is shared across agents (existence + owner name + date/count/attachment + subject-unless-hidden); message content is per-thread gated. Multiple agents each keeping a thread with Elize each get their own `thread_key` row; each sees the others' thread metadata but must request access to read a thread they don't own/participate in.

---

## 4. UNASSIGNED / NULL-AGENCY OWNER edge case

If a thread's `owner_user_id` is NULL (truly unassigned) **or** the owner is a null-agency super_admin (this case):
- `eligibleApproverIds` (`:440-456`): `ownerIds` uses `whereNotNull('owner_user_id')` → a NULL owner contributes no approver; a null-agency owner (46) IS included but can't authorise. Approval then rests entirely on **agency `grant_access` holders**.
- `canAuthorize` for the owner fails the agency check (§1). So the owner cannot self-approve when they are the null-agency System Owner.
- **Net:** the flow HAS an answer *iff* the agency has ≥1 `grant_access` holder (here: users 22/23/43/45 — approvable by them). It has **no answer** when a thread's only "owner" is the null-agency System Owner (or NULL) AND no agency `grant_access` holder exists → dead-end, request can never be actioned.

**Upstream data cause:** the WhatsApp capture that ingested Elize's thread stamped `owner_user_id=46` because the capture device/session was registered under the **platform super_admin** (`johan@corexos.co.za`), not a real agency-1 agent. That single fact drives the "Unassigned" display (§2), the owner-can't-approve gap (§1), and this edge case (§4).

---

## Recommended fix approach (NO code — for Johan's approval)

**A. Routing / `canAuthorize` (bug 1).** Let an owner-role / `grant_access` holder through regardless of a null/`raw` agency_id: reorder `canAuthorize` (and `canRevoke`) to grant `grant_access` holders first, or treat `isOwnerRole()` as a break-glass authoriser, or compare on `effectiveAgencyId()`/active-agency instead of raw `agency_id`. **POLICY for Johan:** should the platform System Owner be able to authorise within an agency (he already bypasses AgencyScope and owns the thread)? Recommended **yes** (break-glass, audited). Independent of that, the practical answer today: approve as the **agency-1 admin account (user 22)**, whose inbox already shows request 15.

**B. Owner identity (bug 2) — POLICY DECISION Johan flagged.** Resolve `owner_name` without `AgencyScope` for *display of the owning agent's name only* (e.g. load the owner `withoutGlobalScope(AgencyScope)` or denormalise a name), and name the owner on the gated row ("Private to **{owner}** — request access" / "ask {owner}"). **Johan must approve** exposing the owning agent's identity (name, maybe email) to a requester while message **content stays gated**. Recommended: show name (so the requester can ask), keep bodies gated.

**C. Multi-thread model (bug 3).** No change needed to the gating model (metadata safe, bodies gated is correct). Only the owner-name resolution (B) needs fixing so "whose thread" is reliably shown.

**D. Unassigned/null-agency owner (bug 4).** Define the fallback explicitly: when a thread has no valid *agency* owner, route approval to agency `grant_access` holders (admins/BMs) and make that visible ("no owning agent — request goes to your manager"); ensure every agency has ≥1 `grant_access` holder; optionally allow the System Owner as an always-eligible break-glass (ties to A). **Upstream:** re-own capture threads to a real agency agent — the WA capture device should be registered to an agency agent, not the platform super_admin, so `owner_user_id` is a real agency-1 agent going forward (and consider a remediation to re-point the existing 46-owned rows to the correct agent — separate approved step).

**No code will be written until Johan approves — including the policy call on showing the owning agent's identity to a requester while content stays gated.**

---

## RESOLUTION — approved + shipped to STAGING (2026-07-02, AT-153)

Johan approved: **A = YES** (null-agency owner / platform owner may authorise, audited break-glass), **B = YES** (show owning agent's NAME on the gated row; bodies stay gated). Shipped on branch `AT-153-comms-access-fix` → **Staging merge `dfad1b18`**, deployed `/corex-staging` (php8.2-fpm reloaded, worker restarted). NOT promoted to live.

**FIX A — `CommsAccessGrantService::canAuthorize` + `canRevoke`.** A platform owner / super-admin (`isOwnerRole`, agency NULL) now passes as an audited break-glass, regardless of the raw/NULL agency. Non-platform users are gated on `effectiveAgencyId()` — ordinary cross-agency users AND cross-agency `grant_access` holders stay blocked (tenancy not weakened). Verified on deployed staging: `canAuthorize(user 46 System Owner, req 15) = TRUE`, `canAuthorize(user 22 agency-1 admin, req 15) = TRUE`.

**FIX B — owner identity.** `ContactController::show` builds an owner-name map `withoutGlobalScope(AgencyScope)` (name-only) so a null/other-agency owner resolves; the gated row now reads "Private to {agent} — request access to read it" (fallback: "no owning agent on record; your request routes to a communications manager"). Verified: Barbara (agency 1) sees owner name **"Johan Reichel"** on the gated Elize row, and the **body is NOT visible (gated)**.

**FIX C — gating model unchanged** (was already correct).

**FIX D — re-own + recurrence guard.** New audited command `communications:reassign-capture-owner --from --to [--dry-run]` (EVENT_OWNERSHIP_TRANSFER per thread; `--to` must be a real agency agent; owner_user_id only — bodies/links untouched). Ran on staging: **80 messages across 3 threads re-owned from platform user 46 → agency-1 agent 22** (3 ownership_transfer audit rows). `WaDeviceController::store` now **refuses** a platform/owner-role/no-agency registrant.

**Device→owner attribution (reported).** Capture stamps `owner_user_id = $device->user_id` (`WaArchiveIngestor`); the device is created by `WaDeviceController::store` with `user_id = Auth::id()`. **Rule (now enforced):** a WhatsApp capture device — extension OR the AT-149 WAHA `waha_session` row — MUST belong to a real agency agent, never the platform super-admin. **Live-cutover precondition (FLAGGED):** before live capture, ensure the WAHA session / device is registered under the correct agency agent, and run `communications:reassign-capture-owner` if any live rows were captured under a platform account.

**Test:** `CommsAccessRequestFlowFixTest` 9/9 (15 assertions) — break-glass authorise+revoke, same-agency admin, cross-agency + cross-agency grant-holder blocked, re-own audited + body untouched + owner-role target refused, device guard both directions.
