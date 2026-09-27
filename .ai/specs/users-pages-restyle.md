# Users pages restyle — Ledger list + Roster edit page (AT-422)

> Status: BUILT on QA2 2026-09-19 (awaiting Johan's testing). Approved by Johan 2026-09-19 (chosen from five options on the design canvas
> "User Pages Restyle Options"): **Option 2 "Ledger" for the Users list** and
> **Option 1 "Roster" for the User edit page**. Two decisions recorded from the same
> session: (1) tick-boxes + bulk actions ARE wanted on the list; (2) the daily digest
> switch lives in BOTH the profile panel and the Actions tab, kept in step.

## 1. What this is and why

Johan has never liked the look of Admin → Users (list) and Admin → Users → edit. This is a
**restyle**: every action either page offers today keeps working exactly as it does now. Only the
layout, density and visual style change, plus the two additions Johan chose (bulk actions on the
list; the profile panel on the edit page).

Pillar: **Agent** (`User`). No new tables. Permission: unchanged — `manage_users` on every route.

## 2. Users list — "Ledger" (Admin → Users, `admin.users`)

A compact table replaces the stack of expandable rows.

**Header:** page title + count; buttons `Refresh P24`, `PP Agents` (same condition as today),
`Archived (n)`, `Add user`. Existing PPRA-verification-due banner and flash messages are kept.

**Columns** (default sort: **Name A→Z**; every column below except the checkbox and actions is
sortable, click the header to sort, click again to reverse):

| Column | Source |
|---|---|
| ☐ select | — |
| Name (avatar/initials, name, email) | `users.name`, `users.email` |
| Role | `users.role` |
| Branch | `users.branch_id` → branch name |
| Status | Active / Invite pending (`is_active` and no `email_verified_at`) / Inactive |
| FFC expiry | `users.ffc_expiry_date` — green (>60 days), amber (≤60 days), red (expired), "—" when none recorded |
| Property24 | P24 agent id when known, else the existing **Sync to P24** action |
| Listings | count of the agent's **on-market** properties (`Property::onMarket()`, `agent_id`) |
| Last seen | latest `login_histories` row with `event = 'login'` |
| Actions | `Edit` (link to the edit page) and a chevron that expands the **quick-edit panel** |

**Search** (client-side, instant): name and email. **Filters:** Role, Branch, Status
(All / Active / Invite pending / Inactive), FFC (Any / Valid / Expiring ≤60 days / Expired / None recorded).
**Pagination:** none — the directory is agency-sized and is already loaded whole and filtered in the
browser today; the footer shows "Showing X of N users". **Empty states:** "No users yet" (agency has none)
and "No users match these filters" with a *Clear filters* link.

**Quick-edit panel — kept, not removed.** The expandable panel each row has today (role, branch,
designation, rentals/branch-split flags, commission cut & PAYE, phones, FFC number, website, photo,
FFC certificate, Activate/Deactivate with the seat-hold rules and override modal, Delete, Save Changes)
moves *inside the table*, under its row, byte-for-byte the same forms and endpoints.

**Scoping:** unchanged — the same agency-scoped query (`User::agencyMembers()`, assistants excluded).

### 2.1 Tick-boxes and bulk actions (new)

Tick one or more rows (a header tick-box selects everything currently shown after filtering). A bar
appears: **"N selected"** · `Resend invitation` · `Deactivate` · clear.

* **Resend invitation** — eligible: active users who have not yet set up their account. Everyone else
  is skipped with a reason. Sends the same `UserInviteMail` the single action sends.
* **Deactivate** — eligible: active users other than the acting admin. Asks for confirmation first
  ("N people will lose access immediately and stop being billed…"). It runs **exactly** the single
  Deactivate's side effects — seat release (`AgentSeatLockService::release`), access revoked, Property24
  updated, `AgentDeactivated` event — by sharing one method with it, never a copy.
* **Result:** one summary message ("Deactivated 2 people. Skipped 1: already inactive.") with each
  skipped person named and why.
* **Safety:** `manage_users` only; the ids are re-queried through the same agency-scoped query as the
  list, so an id from another agency is never touched; the acting admin can never deactivate themself.

**Not built** (in the mock-up, not chosen or not existing today): column chooser, "Change branch" and
"Send digest test" bulk actions, per-row ⋯ menu, drag-between-branches.

## 3. User edit page — "Roster" (Admin → Users → edit, `admin.users.edit` / create)

* **Header:** breadcrumb `Users / <name>` with `Cancel` and `Save changes` on the right (the
  existing sticky Save bar at the bottom stays, so *Communication Capture — Actions tab, directly above
  the Save bar* still holds).
* **Left profile panel (edit mode):** avatar (photo if set), name, role · branch, status pill; facts —
  email, cell, FFC valid to, last login; switches — **Show on Property24**, **Show on website** (only when
  the agency has a website), **Daily digest email**; quick actions — *Resend invitation* (only while the
  invite is pending), *View as this user*, *Deactivate/Activate* (opens the Actions tab where the existing
  confirmation lives). Create mode has no panel (there is no user yet).
* **Right side:** the five existing tabs (Profile · Role & Access · Finance · Compliance · Actions) as a
  segmented control; every existing card and field kept, restyled (section headings in sentence case with a
  one-line description, roomier fields).
* **Daily digest switch in both places** (profile panel + Actions tab): same endpoint
  (`admin.users.toggle-daily-digest`); flipping one updates the other on the page.
* The **Property24** and **website** switches keep their immediate-save behaviour and messages; they move
  to the panel (single place each), out of the Role & Access tab.

## 4. Acceptance criteria

- [ ] List: Ledger table with all columns; sort on each sortable column; search + 4 filters; count footer;
      both empty states.
- [ ] Quick-edit panel works from inside the table exactly as before (role/branch save, deactivate,
      activate incl. seat-hold refusal, delete, file uploads).
- [ ] Bulk Resend invitation / Deactivate work, obey the single-action rules, skip ineligible people with
      a stated reason, never act on another agency's users or on the admin themself.
- [ ] Edit page: header, profile panel, segmented tabs, restyled cards; every field still saves.
- [ ] Digest switch in both places, in step; P24/website switches work from the panel.
- [ ] Both pages render in dark and light themes.
- [ ] No behaviour of either page lost (checked against the list in §2 and the five tabs in §3).

## 5. Files

`resources/views/admin/users/index.blade.php`, `resources/views/admin/users/create-edit.blade.php`,
`app/Http/Controllers/Admin/UserManagementController.php` (list data, bulk action, shared deactivate),
`routes/web.php` (`admin.users.bulk`), `tests/Feature/Admin/UsersLedgerBulkTest.php` (new).

## 6. Setup wizard (CLAUDE.md 10a)

No new agency setting — layout and per-user switches only. Nothing to add to the wizard.

## 7. As built — notes

* The list's FFC filter offers Valid / Expiring within 60 days / Expired / None recorded. "Expiring" = 60 days.
* The expanded quick-edit panel keeps its existing look on purpose (zero behaviour risk); restyling it is a
  separate, optional follow-up.
* "View as this user" (profile panel) is a NEW entry point to the existing, permission-gated impersonation
  route. It only shows when the server would allow it (same guards as `ImpersonateController::start`).
* The panel's "Show on website" switch only renders when the agency has a website (same rule as before).
* Known, unchanged: the quick-edit panel's *Remove photo / Remove certificate* buttons are `<form>`s nested
  inside the panel's own form (as before), which browsers do not honour reliably. Carried over as-is; not fixed here.
