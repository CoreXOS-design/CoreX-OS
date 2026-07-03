# Dashboard Tile — AS-BUILT Audit (for the unified Tile Library)

> **AT-164** (Calendar noise redesign). Read-only audit feeding the Tile Library design in
> `.ai/specs/calendar-interactive.md` §15. No code changed. Investigated 2026-07-03 against
> the `corex-dev` working tree (calendar/dashboard files predate the DR2 branch divergence, so
> as-built here == Staging for these files). File:line anchors given.

## Headline
The live dashboard **already is a unified, data-driven tile system.** `/dashboard` → `corex.dashboard`
(`routes/web.php:1118`) → `DashboardController::today()` (`:27-41`) → `CommandCentreService::assembleForUser()`
→ `resources/views/command-center/today.blade.php`. Every "tile" is a **PHP array of a fixed shape**,
and ONE shared Alpine template renders all of them. The Tile contract we need already exists in embryo —
it just isn't extracted into a Blade component or shared with the Calendar. There is an AJAX refresh twin
(`DashboardController::todayCards()` `:46-52` → `{cards:[...]}` JSON at `command-center.today.cards`) and a
**60-second poll + reactive re-render** already wired (`today.blade.php:237-248`) — the freshness primitive
the "live RAG loop" (§15.7) reuses.

## The as-built tile contract (embryonic)
Each card is an array (built by `CommandCentreService`, rendered by `today.blade.php:71-176`):
```
card_id, title, icon (string key → inline SVG), urgency (critical|high|medium|low),
count (int → badge), items[] (list rows), view_all_url (click-through), always_visible?
```
- Grouped by urgency into 3 sections (`today.blade.php:222-227`): Action Required / Today / Snapshot.
- Card shell uses DS tokens (`cardStyle()` `:229-231`: `var(--surface-2)`, `var(--border)`, urgency-coloured `border-top`).
- Header = icon chip + title + urgency label + count (`:100-116`). Body = 3 render modes: keyed-breakdown list, generic item list (capped 4), or bespoke (invitations/agency-health). Footer = "View all →" → `view_all_url`.
- **Gaps vs the target Tile contract (§15.4):** no independent scroll area (body is capped-not-scrolled), no RAG-accent variant (only urgency border-top), click-through is per-tile footer not per-row-new-tab, no collapse, no per-slot/drag/layout memory. These are the additive deltas the Tile Library introduces.

## Tile-by-tile reusability verdict
Verdict = how cleanly each maps to a generic header+count+**scroll list**+**per-row new-tab** Tile. All are
produced by `CommandCentreService.php` (builder line noted); none is a standalone partial today.

### Agent tiles (`getAgentCards()` :40-129)
| card_id | Title | Data source | Builder | Verdict |
|---|---|---|---|---|
| today_appointments | Today's Schedule | CalendarEventService today+tomorrow | :182 | clean |
| pending_invitations | Pending Invitations | CalendarEventInvitation | :231 | needs-refactor (inline Accept/Tentative/Decline actions) |
| overdue_items | Overdue & Unresolved | CommandTask::overdue + CalendarEvent | :256 | clean |
| buyers_follow_up | Buyers Needing Follow-up | buyer_lost_risk_scores + stale contacts | :301 | clean |
| buyer_portal_activity | Buyer Portal Activity | buyer_property_responses | :359 | clean |
| listings_attention | Listings Needing Attention | properties + marketing activities | :391 | clean |
| esign_activity | E-Sign Activity | signature_templates (3 buckets) | :1333 | clean (breakdown) |
| fica_review | FICA Review Queue | fica_submissions | :495 | clean |
| my_fica_submissions | My FICA Submissions | fica_submissions | :799 | needs-refactor (synthetic info row) |
| active_buyer_pipeline | Buyer Pipeline | contacts by buyer_state | :1137 | clean (breakdown) |
| my_compliance | My Compliance | ffc_expiry + rmcp_entries | :528 | clean |
| my_deal_steps | Deal Steps | deal_step_instances + deals_v2 | :852 | clean (DR2 — see §15 flag) |
| prospecting_activity | Prospecting | prospecting_claims + matches | :1380 | clean (breakdown) |
| listings_pending_marketing | Listings Pending Marketing | Property + MarketingReadinessService | :1414 | needs-refactor (per-item url, heavier compute) |
| draft_presentations | Draft Presentations | presentations draft | :1220 | clean |
| my_leave | My Leave Applications | leave_applications | :901 | clean |
| sales_docs_return | Documents Awaiting Return | sales_document_sends | :960 | clean |
| my_training | Training & Qualifications | training_* | :989 | clean |
| events_feedback | Feedback Not Captured | calendar_events w/o feedback | :1062 | clean |
| unread_notifications | Unread Notifications | notifications | :1249 | clean |
| recent_activity | Recent Activity | buyer_activity_log | :567 | clean |

### Branch-manager tiles (`getBranchManagerCards()` :131-160)
| card_id | Title | Source | Builder | Verdict |
|---|---|---|---|---|
| branch_agent_watch | Agent Watch | users + daily_activity_entries | :600 | clean |
| branch_listings_review | Branch Listings Review | properties + users | :635 | clean |
| branch_compliance | Branch Compliance Queue | fica_submissions + contacts | :664 | clean |
| leave_approvals | Leave Awaiting Approval | leave_applications + users | :929 | clean |
| branch_lost_value | Lost Value (30 days) | buyer_lost_records | :685 | needs-refactor (value+top_reasons shape) |

### Admin tiles (`getAdminCards()` :162-178)
| card_id | Title | Source | Builder | Verdict |
|---|---|---|---|---|
| agency_health | Agency Snapshot | users/properties/contacts/lost | :724 | needs-refactor (2×2 stat grid, not a list) |
| agency_compliance | Compliance Flags | ffc_expiry | :753 | clean |
| strategic_insights | Strategic Insights | ReportingService | :780 | needs-refactor (free-text rows, count=0) |

**Tally: 22 clean · 7 needs-refactor · 0 not-suitable** (dormant builders exist — esign*/prospecting* variants at :422/:464/:1105/:1186/:1286 — superseded by the unified tiles; leave out).

### Not a data tile
`resources/views/dashboard.blade.php` (the static launcher link-grid) is pure navigation (title+emoji, no count/list/data) and is **dead** (the `/dashboard` route redirects away before it renders). **Not-suitable** for the Tile Library. (Also has a broken `<a>` at line 170 — flag for cleanup, not part of this work.)

## Existing reusable pieces to build the Tile Library on
| Piece | File | Role in the contract |
|---|---|---|
| Today card renderer | `command-center/today.blade.php:71-176` | **Closest full tile** (header+count+list+view-all, token-styled) — extract as `<x-tile>` |
| `<x-corex-kpi-card>` | `components/corex-kpi-card.blade.php` | header+big-count portion; no list body |
| `task-card` partial | `command-center/partials/task-card.blade.php` | **list-row renderer** (pillar tag, priority, title link, due, actions) — reuse inside the tile body |
| `timeline-row` partial | `command-center/partials/timeline-row.blade.php` | alternate list-row |
| `<x-list-header>` | `components/list-header.blade.php` | header with count |
| DS §3.3 Generic Card | `UI_DESIGN_SYSTEM.md` | surface spec: `rounded-md`, `var(--surface)`, `1px var(--border)`; `.ds-badge` for counts |

**Prior art:** a worktree `.claude/worktrees/feature+comms-tiles-from-archive/` (branch `feature/comms-tiles-from-archive`) exists — diff before finalising the contract.

## To-dos tile source (`CommandTask`, table `command_tasks`)
Model `app/Models/CommandCenter/CommandTask.php`: statuses `todo/in_progress/awaiting/done/dismissed`; priority `critical/high/normal/low`; scopes ready for the tile — `forUser($id)` `:111`, `visibleTo($user,$scope)` `:128`, `open()` `:140`, `overdue()` `:145`, `dueToday()` `:152`, `thisWeek()` `:158`. Indexes `[assigned_to,status]` + `[assigned_to,due_date]`. Click-through resolves to property/contact/deal (`task-card.blade.php:7-9`); `view_all_url` = `command-center.tasks`. Pattern to copy: the `overdueItems()` builder (`CommandCentreService.php:256`). **No standalone "My To-dos" tile exists yet** (tasks only fold into `overdue_items`).

## Recommendation
Extract `today.blade.php:71-176` into an `<x-tile>` component consuming the existing `CommandCentreService`
card array shape, **plus** the four additive deltas (independent scroll, RAG-accent, per-row new-tab, collapse).
Standardise on the DS §3.3 card surface. Reuse `task-card.blade.php` as the list-row renderer. Dashboard
migrates its 22 clean tiles into the component in place (no data change); the 7 needs-refactor tiles keep a
bespoke body slot within the same shell. The Calendar Deck (§15.4) consumes the identical component.
