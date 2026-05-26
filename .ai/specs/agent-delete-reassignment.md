# Spec — Agent Delete with Reassignment

**Status:** Draft, awaiting approval
**Owner:** Andre
**Created:** 2026-05-09
**Pillars touched:** Agent (User), Property, Contact

---

## Why

Today, deleting an agent at [`UserManagementController@delete`](../../app/Http/Controllers/Admin/UserManagementController.php#L671) soft-deletes the user but leaves their properties and contacts pointing at a now-inactive person. Records become orphaned in the UI and the workload doesn't get picked up by another agent.

## What

Before an admin can delete an agent who still owns properties or contacts, present a modal that:
- Shows counts of what's attached to the agent.
- Forces the admin to pick a destination agent (any active user in the same agency).
- For properties with a secondary agent, lets the admin choose whether the secondary is promoted to primary, or whether the chosen target replaces the primary and the secondary stays as secondary.
- Bulk-reassigns properties + contacts in one operation.
- Deletes calendar events and command tasks owned by the agent.
- Logs the operation to the activity log.
- Then proceeds with the existing soft-delete + P24 inactivation flow.

**QR rerouting (added 2026-05-17, mandatory):** Every agent owns a QR code (`qr_code_slug`) that may be printed on cards/signage. On *every* delete the modal also forces the admin to choose a **QR reroute target** — an active agent in the same agency — so scans of the departed agent's QR onboard clients to a live agent instead of dead-ending. This applies even when the agent has zero attached records (so the "skip the modal" case below no longer fully applies: the modal still opens to collect the QR reroute target). The QR picker defaults to the properties/contacts reassignment target but is independently changeable. The slug never moves — a `qr_reroute_user_id` pointer is written on the source agent before soft-delete; resolution is chained. Full mechanics: `.ai/specs/agent-qr-onboarding.md`.

If the agent has zero attached records, the reassignment section is skipped but the modal still opens for the mandatory QR reroute choice.

## Pillar connections

| Pillar | Reads | Writes |
|---|---|---|
| Agent | source agent + target agent (same `agency_id`, `is_active=true`) | source `users.deleted_at` (existing behaviour) |
| Property | `properties` where `agent_id = source` or `pp_second_agent_id = source` | `agent_id` and/or `pp_second_agent_id` |
| Contact | `contacts` where `created_by_user_id = source` | `created_by_user_id` |
| Deal | none — deals keep the deleted agent on record (per business decision) | none |

## Data model

No migrations required. Uses existing columns:
- `properties.agent_id` (primary), `properties.pp_second_agent_id` (secondary)
- `contacts.created_by_user_id`
- `command_tasks.assigned_to`, `calendar_events.user_id`

(Note: surfacing `created_by_user_id` as an "Agent" field on the Contact view is a related UI improvement — tracked separately, not in this spec.)

## UI

### Entry point
The existing Delete button on `/admin/users/{id}` (the agent edit/list page).

### Flow
1. Admin clicks **Delete**.
2. Backend pre-check counts:
   - properties where `agent_id = X`
   - properties where `pp_second_agent_id = X`
   - contacts where `created_by_user_id = X`
   - calendar events + command tasks owned by X
3. **If all counts are zero** → run the existing delete (no modal).
4. **Otherwise** → modal opens with:
   - Heading: *Delete {agent name} — reassign their work*
   - Summary list of counts (e.g. "12 properties as primary agent, 3 properties as secondary agent, 47 contacts, 8 calendar events, 4 tasks").
   - Dropdown: **Reassign properties + contacts to** — populated with active users in the same agency, excluding the agent being deleted.
   - Radio (only shown if the agent is secondary on any properties): **For properties where this agent is the secondary agent:**
     - (a) *Promote the secondary agent to primary* (default) — clears `pp_second_agent_id`, moves the existing primary to nothing? No: secondary becomes primary only if the agent being deleted **is** the primary. See "Behaviour" below.
     - (b) *Keep them as secondary; assign chosen agent as primary*
   - Note: "Calendar events and tasks owned by {agent} will be deleted. Deals are kept on record."
   - Buttons: **Cancel** / **Delete and reassign**

### Behaviour (precise)

For each property where the deleted agent appears:

| Case | `agent_id == deleted` | `pp_second_agent_id == deleted` | Action |
|---|---|---|---|
| A | yes | no | `agent_id = target` |
| B | yes | yes (impossible — same id in both slots) | n/a |
| C | yes (deleted is primary) AND a different secondary exists | secondary is someone else | If radio = *promote secondary*: `agent_id = pp_second_agent_id`, `pp_second_agent_id = null`. If radio = *keep secondary*: `agent_id = target`, secondary unchanged. |
| D | no | yes | `pp_second_agent_id = target` (or `null` if target equals existing primary, to avoid duplicate). |

For each contact where `created_by_user_id == deleted`: set to `target`.

For calendar events / command tasks owned by deleted agent: soft-delete (per project rule #1 — no hard deletes ever, including for tasks/events).

## Permissions

Reuses existing `manage_users` permission. No new permission keys.

## Audit

Single activity-log entry per delete operation:
- `actor_user_id` = admin
- `action` = `agent.deleted_with_reassignment`
- `payload` = `{ source_user_id, target_user_id, secondary_handling: 'promote'|'replace', counts: { properties_primary, properties_secondary, contacts, events_deleted, tasks_deleted } }`

## Acceptance criteria

1. Deleting an agent with zero records works exactly as today (no modal).
2. Deleting an agent with records opens the modal and blocks deletion until a target is chosen.
3. After confirming, all properties + contacts are reassigned in a single DB transaction.
4. Secondary-agent radio behaves per the table above.
5. Calendar events and tasks owned by the deleted agent are soft-deleted.
6. Deals are unchanged.
7. Activity log entry is written.
8. Existing P24 inactivation + soft-delete still happens after reassignment.
9. Target dropdown only lists active users in the same agency, excluding the agent being deleted.
10. Self-delete is still blocked (existing check).
11. Permission check (`manage_users`) still applies.
12. Operation is idempotent if interrupted (transactional — either all changes apply or none).

## Files to create / modify

- **Modify:** [`app/Http/Controllers/Admin/UserManagementController.php`](../../app/Http/Controllers/Admin/UserManagementController.php) — split current `delete()` into:
  - `GET deletePreview(User)` → returns counts as JSON for the modal
  - `DELETE delete(User)` → accepts `target_user_id` + `secondary_handling`, runs the reassignment service, then existing flow
- **Create:** `app/Services/Admin/AgentDeletionService.php` — the bulk reassignment + delete logic, transactional.
- **Modify:** the user list/edit Blade view that holds the existing Delete button — wire it to fetch the preview and render the modal (Alpine.js).
- **Modify:** `routes/web.php` — add `admin.users.delete-preview` route (under `/api/v1/admin/users/{user}/delete-preview` per non-negotiable #7, with `->name()` so it appears in `/admin/api`).
- **No migration.**

## Out of scope

- Adding a dedicated `agent_id` column to `contacts` (separate spec if desired).
- Surfacing the agent on the Contact view UI (separate small task).
- Reassigning deals.
- Bulk-reassign-without-deleting (a future feature, not this one).
