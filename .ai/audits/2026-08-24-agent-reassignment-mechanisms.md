# Agent reassignment mechanisms — what actually exists, 2026-08-24

**Read-only. No code changed.** Written at Johan's request while live is frozen for the
server migration — a reference document to have on paper, not a task queue.

**Context**: the public-link resilience audit
(`.ai/audits/2026-08-24-public-link-resilience-audit.md`) described "reassign to another
agent, typically a BM, when the original agent leaves" as one existing, reusable concept.
It isn't. It's four separate, non-unified implementations, built at different times for
different problems, that only partially overlap with each other — and by the end of today's
fix work, arguably five. This document names each one precisely: what triggers it, what it
resolves to, who calls it, and whether the four are actually solving the same problem or
just sound like they are.

---

## The four mechanisms, named

### 1. `AgentDeletionService::reassignAndCleanup()`
`app/Services/Admin/AgentDeletionService.php:132`

**Triggers on:** hard delete only, via `UserManagementController::delete()`
(`app/Http/Controllers/Admin/UserManagementController.php:1115`) — the internal admin
"delete this agent" flow. Never fires on deactivation.

**What it does:** reassigns the agent's **internal business records** — properties,
contacts, deals, tasks — to a target user picked manually by the admin performing the
deletion, from a flat alphabetical list of every active agency user. No branch-manager
filter, no suggested default, no automatic resolution at all — a human chooses every time.

**Who uses it:** nobody but the admin delete flow. Not called from anywhere near a public
route.

**What problem it actually solves:** "who owns this CRM record now that the agent is
gone" — an internal data-integrity question. It has nothing to do with what a member of
the public sees when they follow a link. This is the one mechanism that does **not**
belong in the same conversation as the other three, despite Johan's framing grouping them
together — it answers a different question entirely.

### 2. `User::resolveByQrSlug()` + `qr_reroute_user_id`
`app/Models/User.php` (resolver), `qr_reroute_user_id` column on `users`

**Triggers on:** originally, hard delete only — mandatory at delete time per
`agent-qr-onboarding.md`. **As of today, also deactivation**: a new listener,
`App\Listeners\Agent\SetQrRerouteOnDeactivation` (`app/Listeners/Agent/
SetQrRerouteOnDeactivation.php`), fires on the existing `AgentDeactivated` domain event
and sets the pointer if it isn't already set (see mechanism 2a below).

**What it does:** a **stored, chained pointer**. `resolveByQrSlug()` follows
`qr_reroute_user_id` from agent to agent until it lands on someone active, or runs out of
chain. Once set, the pointer doesn't re-evaluate itself — it's a fact recorded at the
moment of departure, not a live computation.

**Who uses it:** the agent business-card link (`/corex/agents/{nameSlug}/{tag}`) and its
legacy QR alias (`/r/a/{slug}`) — both public, unauthenticated routes.

**2a. Today's addition — `User::resolveBranchManagerOrAdminFallback()`**
`app/Models/User.php` (new static method, commit `d59d19236`)

Built today specifically to feed the new deactivation listener. Two-tier resolution:
branch manager for the departing agent's branch first, then earliest active
admin/super_admin in the agency. This is a **new, fifth piece of logic** — not a reuse of
mechanism 4 below, though it's deliberately modeled on mechanism 4's query shape (see "Is
this really a fifth mechanism?" below). It only feeds the stored pointer once, at
deactivation time; it does not become part of every future lookup.

### 3. `SharedLinkReengagementService::agencyFallbackContact()`
`app/Services/Leads/SharedLinkReengagementService.php`

**Triggers on:** every page load — this is a **live computation**, not a stored pointer.
Checks the currently-assigned agent's `is_active` and `deleted_at` at request time; if the
agent is gone, falls through to a generic agency-level contact (phone/email), not a
specific successor person.

**Who uses it, as of today:**
- `SharedMatchController` — the wishlist/shared-match link (cc3's original fix).
- `SellerLinkController::showUnavailable()` — the seller live-link fix (this session,
  earlier today).
- `PublicAgencyPropertiesController::showUnavailable()` — the agency property-listing fix
  (this session, earlier today).

This is now the **most-reused** of the four mechanisms, and the only one three
independently-built fixes converged on today without being told to share code — each
found it already solved their exact problem and called it directly.

### 4. `SellerOutreachLandingService::resolveBranchManagerFallback()`
`app/Services/SellerOutreach/SellerOutreachLandingService.php:206`, called from the same
class's line 55 — private, single-caller.

**Triggers on:** every page load of `/m/{shortcode}` (the outreach landing page) when the
send's original agent is gone.

**What it does:** despite the name, does **not** filter by `role = 'branch_manager'` at
all. Resolves to the earliest-created `super_admin`/`admin` in the agency (no `is_active`
check, unlike mechanism 2a's version of the same idea), and if nobody qualifies, fabricates
a placeholder `User` object named "The team" with `id = 0` — a display-only stand-in, never
a real successor.

**Who uses it:** only its own controller. Not shared with anything else.

---

## Which ones public links actually rely on, today

| Mechanism | Public route(s) actually using it right now |
|---|---|
| 1 — `AgentDeletionService` | **None.** Internal admin flow only. |
| 2 — `qr_reroute_user_id` chain | Business card, legacy QR alias |
| 2a — new branch-mgr/admin resolver | Feeds mechanism 2's pointer on deactivation only |
| 3 — `agencyFallbackContact()` | Wishlist/shared-match, seller live-link, agency property listings — **3 routes** |
| 4 — outreach's own resolver | Outreach landing page only |

Mechanism 3 is the de facto standard for "live-computed agency-level fallback." Mechanism
2/2a is the only one that resolves to a **specific named successor person** rather than a
generic agency contact — which is exactly what a business card needs (a card is supposed
to look like it belongs to *someone*, not a department) and exactly why it couldn't just
reuse mechanism 3 as-is.

---

## Is this really a fifth mechanism?

Yes, honestly. The original audit's own recommendation, written this morning, said "don't
invent a fifth mechanism — compose one shared resolver instead." Today's business-card fix
did not do that. `User::resolveBranchManagerOrAdminFallback()` is a new method, in a new
place, with its own query — it borrows mechanism 4's *shape* (branch manager, then earliest
admin) but is not the same code, and mechanism 4 was deliberately left untouched (documented
in that method's own docblock at the time) rather than pointed at the new shared method,
specifically to keep today's fix small and scoped under the "one concern per prompt" rule.

That was the right call for today — a same-day point fix for a reproducible bug Johan had
hit personally, not the moment to also refactor a working, unrelated controller. But it
means the tally is now honestly **five** implementations of "who's the fallback when an
agent's gone," not four, and this document should say so plainly rather than let the
original "four" framing stand uncorrected.

---

## Is unifying them real work, or tidying?

**Split answer, because it's two different questions wearing one label.**

**Mechanism 1 should not be unified with the other three/four at all.** It answers "who
owns this database record now" — an internal admin concern, manually directed, with no
public-facing behaviour. Folding it into a shared "public link fallback" resolver would be
conflating two different problems that happen to share the word "reassign." Leave it
separate; it's already correctly scoped to what it does.

**Mechanisms 2/2a, 3, and 4 genuinely are the same question, asked four times.** All four
answer "the person a link/page pointed to is gone — who does the visitor see or contact
instead?" They differ only in *how much* they resolve to (a specific successor vs. a
generic agency contact) and *when* they compute it (stored pointer vs. live check on every
request). Live-checking is very likely the more correct model going forward — it self-heals
automatically when staffing changes, where a stored pointer (mechanism 2) can silently go
stale if the chosen successor later leaves too, unless something re-runs the resolution.

**Unifying those is real, scoped work, not tidying** — it is not a rename or a formatting
pass:
- It touches at least five files with independently-shipped, independently-tested
  behaviour as of today: `SellerLinkController`, `PublicAgencyPropertiesController`,
  `SharedMatchController`, `SellerOutreachLandingService`, and the new
  `SetQrRerouteOnDeactivation` listener / `User::resolveBranchManagerOrAdminFallback()`.
- It requires an actual product decision, not just a refactor: should the business card's
  stored-pointer model be replaced with mechanism 3's live-check model (self-healing, but a
  behaviour change for an existing, working feature), or should mechanism 3's callers move
  to a specific-successor model like the business card's (more work, since it needs the
  branch-manager/admin resolution mechanism 2a just built, not just an agency contact card)?
  That's Johan's call, not an engineering default.
- It would mean re-testing every one of those five call sites, several of which now carry
  today's fresh, staging-verified fix behaviour.

**Recommendation: defer, and treat as its own spec-first prompt once things are stable
post-migration** — not squeezed in now, and not attempted piecemeal inside an unrelated
fix. This document is what makes that a scoped, well-understood follow-up instead of a
surprise discovery next time someone touches one of these five call sites.
