# Spec: Refresh All Portals + Post-Save Syndication Prompt

**Status:** Built — awaiting Johan's review
**Drafted:** 2026-07-13
**Drafted by:** Andre (via Claude)
**Ticket:** AT-252

---

## Overview

Two tightly-related changes to the shared syndication surface, both aimed at the same
failure mode: **an agent edits a live listing and the portals silently keep serving the
old version.**

1. **Refresh all portals** — one button in the syndication panel (directly above *Live
   preview*) that re-pushes the open listing to **every portal it is currently live on**,
   instead of the agent clicking *Refresh* separately on Company Website, Property24 and
   Private Property.

2. **Post-save syndication prompt** — when a listing is **compliant** *and* its **Status is
   Active**, saving it opens the syndication panel automatically, so the agent is shown the
   push-to-portals control at the exact moment their change would otherwise go stale.

Neither change adds a new portal integration, a new endpoint, or a new permission. Both are
pure reuse of the existing per-portal Refresh paths.

## Why

The syndication panel already exposes a per-portal *Refresh* button, and each one works. The
gap is that **nothing connects the act of saving to the act of pushing.** An agent drops the
price on a live listing, saves, sees "Property updated.", and leaves — while Property24,
Private Property and the company website all still advertise yesterday's price. The listing
is now lying to the public, and CoreX let it happen quietly.

Both halves of this spec close that loop:
- the prompt makes the push *visible* at save time (it is no longer something you have to
  remember), and
- Refresh All makes the push *one click* rather than three (it is no longer something you
  do partially and abandon halfway).

This is the CoreX Operating Principle applied literally: we absorb the complexity (which
portals carry this listing, which of them need a re-push, what each one's API needs) so the
agent does the simple thing (press one button).

## Pillars

- **Property** — the listing whose portal copies are refreshed. Read + write
  (`*_syndication_status`, `*_last_submitted_at`).
- **Agent** — the practitioner saving the listing; the prompt is the surface they act on.

No new pillar tables, no new models, no migrations.

---

## Part 1 — Refresh All Portals

### UI placement

`resources/views/corex/properties/partials/syndication-panel.blade.php`, in the `main` step,
in its own bordered block **immediately above the *Live preview* block**. Because there is
exactly ONE syndication panel in CoreX, this single insertion delivers the button to both
callers at once:

- the **property page** (`show.blade.php`, rendered inline in the modal), and
- the **Properties index** (fetched per property via
  `GET /api/v1/properties/{property}/syndication-panel` and injected into the shared modal).

```
┌─ Syndication — 12 Marine Drive, Uvongo ──────┐
│  Websites                                    │
│    HFC Website        ● Active   [Refresh]   │
│  Portal Syndication                          │
│    Private Property   ● Active   [Refresh]   │
│    Property24         ● Active   [Refresh]   │
│  ──────────────────────────────────────────  │
│  [  ↻  Refresh all portals              ]    │  ← new
│     Live on: HFC Website · Private Property  │
│              · Property24                    │
│  ──────────────────────────────────────────  │
│  [  ◉  Live preview                     ]    │
└──────────────────────────────────────────────┘
```

### Behaviour

Clicking *Refresh all portals* re-pushes the listing to every portal that currently carries
it. It does **not** enable a portal, does not submit a listing for the first time, and does
not touch a portal that is off, deactivated or not yet published — those are deliberate
states an agent chose, and a bulk button must never silently undo them.

The button reuses each portal's **existing** refresh path verbatim:

| Portal | Refresh path (unchanged) |
|--------|--------------------------|
| Company Website | `POST /corex/properties/{p}/website-syndication/{key}/refresh` → `WebsiteSyndicationService::resend()` |
| Private Property | `POST /corex/properties/{p}/syndication/submit` → `PrivatePropertySyndicationService::submitListing()` |
| Property24 | `POST /corex/properties/{p}/p24-syndication/submit` → queues `SubmitListingToProperty24` |

Each portal keeps its own spinner, status badge, error panel and (for P24) its
`sync-state` polling. Refresh All is a **dispatcher**, not a second implementation — there is
no new endpoint, no new service, and no second copy of the readiness/authorisation logic.
Every guard that protects a single Refresh (`enforceListingNotDraft`,
`enforceMarketingReadiness`, `checkReadiness`, `authorizeProperty`) therefore protects the
bulk press too, for free.

### The client-side bus

The three portal panels are Alpine **siblings** (`websiteSyndication`, `ppSyndication`,
`p24Syndication`), each with its own `x-data`. A bubbling `$dispatch` from the Refresh All
button travels *up*, never sideways, so it cannot reach them. The panel therefore uses a
small `window` event bus. This is safe because **exactly one syndication panel exists on a
page at a time** — the property page renders it for one property; the index injects one into
the modal and clears `synBody.innerHTML` on close.

| Event | Direction | Payload |
|-------|-----------|---------|
| `corex-syndication-census-request` | Refresh All → portals | — |
| `corex-syndication-portal-state` | portal → Refresh All | `{ key, label, live }` |
| `corex-syndication-refresh-all` | Refresh All → portals | `{ acked: [] }` — each portal that fires pushes its label |

**Why the census-request event exists (the init-order trap):** the portal panels appear
*before* the Refresh All block in the DOM, so Alpine initialises them first. Their opening
`x-effect` announce therefore fires while the Refresh All component does not yet exist and
its listener is not yet attached — the initial census would be lost and the button would
render hidden on a listing that is live on three portals. Refresh All's `init()` closes the
hole by asking for a census once it is itself listening. Subsequent announces (a toggle, a
status change) land normally, because by then both sides are alive.

`acked` is read back synchronously after `dispatchEvent` — DOM event listeners run
synchronously — so the button can report exactly which portals it fired without guessing.

### Single source of truth: `isLiveOnPortal()`

Each portal component gains one predicate — *does this portal currently carry this listing?*

| Component | `isLiveOnPortal()` |
|-----------|--------------------|
| `websiteSyndication` | `enabled && status ∈ {active, submitted}` |
| `ppSyndication` | `enabled && ppRef && status ∈ {active, submitted}` |
| `p24Syndication` | `enabled && p24Ref && status ∈ {active, submitted, submitting}` |

These are **exactly** the conditions that already gated each panel's *View · Refresh ·
Deactivate* action row. The `x-show` on those rows is rewritten to call `isLiveOnPortal()`,
so the visible Refresh button and the bulk press can never disagree about what "live" means
(BUILD_STANDARD §6 — fix the class, not the instance). It also drives the census, so the
Refresh All button's visibility and its "Live on:" line stay reactive to a toggle made in
the panel without a page reload.

P24's `submitting` counts as live (the listing IS on the portal; a queued push is merely in
flight). A portal mid-push is skipped at fire time by the `!loading` guard rather than by
hiding it — so the button never vanishes underneath the agent mid-sync.

### Prevent-or-absorb

| Input / state | Decision | Behaviour |
|---------------|----------|-----------|
| No portal is live | **Prevent** | Button is hidden (`x-show="liveCount() > 0"`). No dead button. The panel already explains each portal's state directly above. |
| Exactly one portal live | Absorb | Button shows and works. Redundant with that portal's own Refresh, but it is the affordance the post-save prompt points at, so it must work at N=1. |
| Portal toggled off after render | Absorb | The census is reactive; the button hides itself and `acked` stays honest. |
| A portal is mid-push (`loading`) | Absorb | Skipped — no double-push. P24 additionally dedupes at the queue (`SubmitListingToProperty24` is `ShouldBeUnique`). |
| Every live portal is mid-push | Absorb | `acked` is empty → "Already syncing — watch each portal above." Never a silent no-op. |
| A portal's push fails | Absorb | That portal renders its own error panel, exactly as a single Refresh does. The others still fire — one bad portal never blocks the rest. |
| Listing is draft / not marketable | Prevent | The panel itself is unreachable — `PropertySyndicationPanelController` 403s and the index only renders the trigger for `is_marketable && has_syndication`. |

### Permissions

**None added.** The button lives inside the syndication panel, which is already gated:
`permission:access_properties` on the panel endpoint, plus `authorizeProperty()` (data-scope)
on every underlying refresh route. Anyone who can press *Refresh* on a portal today can press
*Refresh all portals*; anyone who cannot, cannot reach the panel at all.

---

## Part 2 — Post-save syndication prompt

### Trigger

On **save** of an existing property (`PropertyController@update`), the redirect to the
property page carries an `open_syndication` flash when **both** hold:

```php
$property->compliance_snapshot_at !== null      // marked compliant
&& strtolower($property->status) === 'active'   // Status * = Active
```

`compliance_snapshot_at` is the canonical "marked as compliant" record — there is no boolean
compliance flag; the timestamp *is* the flag (written by
`MarketingReadinessService::snapshotCompliance()`, surfaced as `marketing_status = 'live'`).
`'active'` is the literal value the Status select and the publish wizard write.

Both conditions are required. A compliant listing that is **Sold** must not nag the agent to
re-push it to the portals, and a non-compliant listing has nothing it is allowed to publish.

### Effect

`show.blade.php` opens the existing syndication modal from the flash:

```blade
x-data="{ …, synOpen: {{ session('open_syndication') && !$isNew ? 'true' : 'false' }}, synStep: 'main', … }"
```

The agent lands on the saved property with the syndication panel already open, sees which
portals are live, and presses *Refresh all portals*. That is the whole loop: **save → prompt
→ one click → portals current.**

The prompt fires on **every** qualifying save, by design — it is not dismissed-once-and-
forgotten. A save is the exact event that makes the portal copies stale, so every save is a
moment the agent should be shown the push control. Closing the modal is a single click and
never blocks the page.

### Why the controller owns the rule, not the view

The compliant + Active test lives in **one** private helper on `PropertyController`
(`shouldPromptSyndication()`). The view only reads the flash. Putting the rule in Blade would
make it untestable and would duplicate it the moment a second save path needs it.

### Deliberately NOT wired

- **`store()`** — a brand-new property cannot carry a compliance snapshot (it is stamped later,
  by *Go Live*), so the test can never pass on create. Wiring it would be dead code. It is also
  the wrong channel: the >20-image create path returns JSON and then fires several
  `upload-images` requests before it navigates, any one of which would consume the flash.
- **`PropertyWizardController@finalize`** — same reason. The wizard publishes with
  `status = 'active'`, but there is no snapshot yet, so the condition can never be true.
- **`goLive()`** — marking a property compliant is not a *save* of the property form. The rule
  is explicitly about saving. Left alone.

## Settings

**None.** This adds no agency-configurable setting, so Non-negotiable #10a (every new setting
reaches the Setup Wizard) does not apply — there is nothing to surface in
`config/agency-onboarding-copy.php`.

---

## Files

### Modified
- `resources/views/corex/properties/partials/syndication-panel.blade.php` — Refresh All block
  above *Live preview*; `x-effect` announce + census/refresh listeners on the three portal
  roots; `x-show` of each action row switched to `isLiveOnPortal()`; website config gains
  `key`.
- `resources/views/corex/properties/partials/syndication-scripts.blade.php` —
  `isLiveOnPortal()` + `announceSyndicationState()` on all three components;
  `refreshListing()` alias on `websiteSyndication`; new `syndicationRefreshAll()` component.
- `resources/views/corex/properties/show.blade.php` — `synOpen` seeded from the
  `open_syndication` flash.
- `app/Http/Controllers/CoreX/PropertyController.php` — `shouldPromptSyndication()` helper,
  wired into `update()`.

### Created
- `tests/Feature/Properties/SyndicationRefreshPromptTest.php`

### No-touch
- Every portal service, job, mapper and controller. Refresh All dispatches into the existing
  paths; it does not reimplement them.

---

## Acceptance criteria

1. A listing live on Website + PP + P24 shows *Refresh all portals* above *Live preview*, with
   all three named on the "Live on:" line.
2. Pressing it fires exactly one refresh per live portal, each hitting the same endpoint its
   own *Refresh* button hits; each panel shows its own syncing state and resolves independently.
3. A listing live on **no** portal does not render the button at all.
4. A portal that is off / deactivated / never-submitted is **not** pushed by the button, and is
   not listed on the "Live on:" line.
5. Toggling a portal off inside the open panel removes it from the "Live on:" line without a
   reload; toggling the last one off hides the button.
6. A portal already mid-push is skipped (no double-push); if all live portals are mid-push, the
   button says so rather than doing nothing silently.
7. Saving a property with `compliance_snapshot_at` set **and** Status = Active lands on the
   property page with the syndication panel already open.
8. Saving a compliant listing whose Status is **not** Active does **not** open the panel.
9. Saving a non-compliant listing (no snapshot) does **not** open the panel, whatever its status.
10. The Properties index modal and the property page render the identical Refresh All control —
    one file, two callers.
11. `php -l` clean on every changed PHP file; the targeted test file passes.
