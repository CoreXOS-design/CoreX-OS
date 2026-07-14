# AT-113 — Viewing-Pack guided tour: loop-readiness investigation

_INVESTIGATION ONLY. No code changed. Night shift 2026-07-14. m3. For Johan._
_Question: is the viewing-pack loop complete enough to build the tour (AT-113 is "build LAST")?_

## Verdict: DO NOT BUILD THE TOUR YET. The loop is not complete.

AT-113's own gate is "build LAST, after AT-110 **and AT-111 and AT-112** are finished and stable."
**Two of those three are not in the code** — regardless of what Jira says.

## ⚠️ Jira vs code drift (read this first)
**All four tickets (AT-110, AT-111, AT-112, AT-113) show status "Production / Done" in Jira.** Per CoreX
board-hygiene doctrine (AT-205 — verify by code + data, never by ticket status), I checked the code.
The statuses are **misleading**: AT-111 and AT-112 are not built, and AT-113 (the tour) is itself
marked Done while **no viewing-pack tour exists in the registry**. Recommend reopening AT-111/AT-112
(and AT-113) so the board reflects reality before anyone treats the loop as finished.

## Per-ticket code reality

| Ticket | Jira | Code reality | Evidence |
|---|---|---|---|
| **AT-110** doc pipeline / redaction / in-place | Done | **BUILT** ✓ | redaction routes (`redaction-data`, `redact`, `redacted-file`, `routes/web.php`), in-place region (`show.blade.php:87` "AT-110 Bug 3 — in-place region"), included redacted docs embedded in the PDF (`ViewingPackBuyerPdfService.php:60` gates on `$vpd->included` + `redacted_file_path`). |
| **AT-111** viewing-pack ↔ calendar two-way link + download-from-event | Done | **NOT BUILT** ✗ | No `calendar_event_id`-driven launch/update/download on ViewingPack anywhere (the only `calendar_event_id` hits are unrelated — activity-points, reconcile). **No pack download buttons on any calendar-event panel** (grep of calendar views = 0). The route group comment states the pack-side schedule route + `ViewingPackCalendarService` were **removed**; only the forward pack→calendar *prefill handoff* exists. The reverse direction (event→pack, "Update appointment" in place, download-on-event) — the heart of AT-111 — is absent. |
| **AT-112** role/branch permission gating | Done | **NOT BUILT** ✗ | The `viewing-packs` route group has **no `permission:` middleware**; its own comment says "Tenancy via AgencyScope on the model" — i.e. **agency-level only**. No viewing-pack permission keys in `config/corex-permissions.php` or `RoleProvisioningService`. `ViewingPack` has `agent_id` (owner) but **no `BelongsToBranch`/`BranchScope`/`scopeVisible`**. The "agent sees own / BM sees branch / admin sees all" role visibility AT-112 specifies does not exist. |
| **Discoverability** (main list + buyer pipeline + contact-record entry points) | (sub-task) | **Partial / unconfirmed** | An index route + a command-centre `_packs-section` exist, but grep found **no** viewing-pack entry point on the contact record or buyer-pipeline views. AT-113's step list ("finding/editing existing packs", buyer/contact entry points) has anchors that may not exist yet. |
| **AT-113** the tour | Done | **NOT BUILT** ✗ | No viewing-pack tour in `App\Support\Tours` (registry or defs). |

## Why building now would be wrong (exactly the ticket's stated risk)
AT-113 says: *"A tour built before these are settled would need constant rewriting as buttons/entry
points move. Tour the finished feature."* Concretely, a tour built today would try to walk:
- **the calendar link / download-from-event** — which has no buttons to anchor to (AT-111 unbuilt);
- **permission-gated pack visibility** — which doesn't exist, so the tour would imply a gating model
  that isn't there (AT-112 unbuilt);
- **entry points from buyer pipeline / contact record** — not confirmed present.
Every one of those anchors would move or appear when AT-111/112/discoverability actually land, forcing
a rewrite. This is the case for **not** building it.

## Recommendation
1. **Hold AT-113.** Do not build the viewing-pack tour this cycle.
2. **Reopen / correct AT-111 and AT-112 on the board** — they are marked Done but are not in code; they
   are the real remaining work before the tour.
3. **Confirm discoverability entry points** (contact record, buyer pipeline) are built + stable.
4. Once AT-111 + AT-112 + discoverability are genuinely in code and stable, AT-113 is a **~1 session**
   build on the existing tour engine (`App\Support\Tours\TourRegistry` + driver.js — the same framework
   the MIC buyer-prospecting tour was just built on tonight): add a `viewing-pack` def keyed to the pack
   builder route + `data-tour` anchors on the (by-then-settled) build UI, calendar link, and entry points.
   The framework is ready; the feature is not.

_AT-110 is the one prerequisite that IS done — good, but insufficient on its own._
