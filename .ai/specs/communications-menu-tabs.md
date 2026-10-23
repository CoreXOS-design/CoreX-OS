# Communications menu — headings removed, links become tabbed items

> Status: **BUILT on branch AT-501-Style-Updates (2026-10-22, Andre: "now with communications section"). Not committed.**
> Removes the AT-161 sub-section headings (Capture — what gets imported / Archive & Review / WhatsApp / Email / My Setup). Johan must
> see this before it leaves QA2.
> Owner: Andre · Same mechanism and rules as `.ai/specs/real-estate-menu-tabs.md` (§2 there applies word for word).

## 1. The new Communications menu (12 links under 5 headings → 3 items, no headings)

| # | Menu item | Tabs (in order) — each is today's page on today's route |
|---|---|---|
| 1 | **Messages** | Review Incoming Messages · Message Archive · Flagged Messages · Archive Access Requests · To File (attorney emails) |
| 2 | **WhatsApp** | My WhatsApp Consent · WhatsApp Consent — Review · WhatsApp Capture (Browser Extension) · Link My WhatsApp |
| 3 | **Setup** | My Communication Setup · Email Mailboxes (import) · Email Capture Setup |

Every label is unchanged (the AT-161 / AT-195 wording Johan settled on). "Link My WhatsApp" was a menu link to My Portal › Tools (the
self-link QR lives there); it stays exactly that — a tab that jumps to My Portal › Tools (`fragment`, `match => []`), and My Portal
shows no WhatsApp bar.

### 1.1 Gates per tab (copied verbatim — not changed). All tabs: feature `communications` (the panel's outer switch).

| Hub | Tab | Route | Permission(s) |
|---|---|---|---|
| Messages | Review Incoming Messages | `communications.triage.index` | `triage_communications` |
| Messages | Message Archive | `compliance.comm-archive.index` | `access_communication_archive` |
| Messages | Flagged Messages | `compliance.comm-flags.index` | `view_communication_flag_register` |
| Messages | Archive Access Requests | `corex.comms-access.inbox` | `communications.view` |
| Messages | To File (attorney emails) | `corex.comms-suspense.index` | `deal_comms_suspense.view` |
| WhatsApp | My WhatsApp Consent | `communications.capture.my` | `access_communication` |
| WhatsApp | WhatsApp Consent — Review | `communications.capture.review` | `communications.capture_review` AND `access_communication` |
| WhatsApp | WhatsApp Capture (Browser Extension) | `communications.wa-devices.index` | `access_communication` |
| WhatsApp | Link My WhatsApp | `agent.portal#tools` | `access_communication` AND `access_my_portal` |
| Setup | My Communication Setup | `my-portal.comm-capture.index` | `access_communication` |
| Setup | Email Mailboxes (import) | `compliance.comm-mailboxes.index` | `manage_communication_mailboxes` |
| Setup | Email Capture Setup | `settings.email-setup.index` | `manage_communication_mailboxes` |

The old `$canSeeCommunication` / `$canCapture` / `$canWa` wrappers are each implied by the tab's own permission(s), so dropping them
changes nothing (proved by the equivalence test).

## 2. No data, settings or new list screens

Navigation only — no pillar data, migration, permission/feature key or agency setting (nothing for the Setup Wizard); each tab keeps
its own search/sort/filter/scoping. Labels are neutral.

## 3. As built / proof

- `App\Support\Navigation\Hubs\{Messages,WhatsApp,CommsSetup}Tabs`, registered in `TabHubs`; landing routes `corex.hubs.messages`,
  `corex.hubs.whatsapp`, `corex.hubs.comms-setup` (resolve to the Communications panel). `TabHub` gained `fragment` support.
- Menu search: every old label stays findable (data-nav-extra). The sidebar's hub search lists are now JSON with unescaped unicode
  so "WhatsApp Consent — Review" reads as written (`CommsNavIaTest` asserts that exact text).
- `tests/Feature/Navigation/CommunicationsMenuTabsTest.php` — equivalence (5 user types × 12 tabs), three items and no headings,
  landing (incl. an archive-only user landing on the archive), every tab screen opens with its bar and its own tab marked, the Link
  tab goes to My Portal › Tools. `TabHubSwitchesTest` covers these hubs too (feature / Role Manager off hides each tab, on returns it).
- `CommsNavIaTest` (the AT-161/AT-195 wording) passes unchanged. The `agent.portal` sidebar allow-list entry came off — the double
  match it explained (My Portal + the old Link My WhatsApp link) no longer exists.
- Guided tours: comms-triage, comp-comm-archive, comms-wa-devices, portal-comm-capture — all 33 targets checked on the rendered pages
  before and after; none lost (4 missing both before and after: rows / tokens the local data does not have).
