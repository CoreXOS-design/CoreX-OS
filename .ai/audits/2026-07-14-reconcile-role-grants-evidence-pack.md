# Reconcile-role-grants — evidence pack for the "clean it" decision

**Prepared:** 2026-07-14 (m6). **Report only — nothing was changed, anywhere.**
**Decision this supports:** whether to run `corex:reconcile-role-grants --roles=agent --apply` on live.
**Command status:** BUILT + verified on qa1 (`ebe55f07`), HELD on Johan's word since 2026-07-12.
**Reads with:** `.ai/audits/2026-07-12-permission-drift-cleanup-verification.md` (the qa1 proof run).

---

## The one-sentence version

**33 live agents currently hold ~120 permissions they were never meant to have — including the
right to settle deals (mark commission paid) and approve FICA. Cleaning it takes those away and
takes nothing legitimate; the one visible change is that agents go back to their own read-only
"My Deals" register instead of the admin deal register.**

---

## 1. What is actually over-granted

Drift is **uniform** across all three agency contexts — it is a bad bulk seed on 2026-07-02 17:43,
not per-agency customisation:

| Context | Grants held (DB) | Config says | Over-grants |
|---|---|---|---|
| `agent` template (agency NULL) | 243 | 123 | **120** |
| `agent` @ agency 1 | 240 | 123 | **117** |
| `agent` @ agency 7 | 243 | 123 | **120** |
| **Total rows to remove** | | | **357** (120 distinct keys) |

**Who holds them today:** every user with role `agent` — **33 on live** (15 in agency 1, 18 in
agency 7). The seed was uniform, so each of those 33 holds *every* key below. No other role is
touched by this cleanup (`--roles=agent`).

### The keys that actually matter — what each one lets an agent do today

| Over-granted key | What it really gates | Agent has it today | After `--apply` |
|---|---|---|---|
| **`settle_deals`** | `/admin/deals/{deal}/settle` — **settle a deal: mark commission paid, print settlement** | ✅ yes | ❌ removed |
| **`create_deals`** | `GET /admin/deals` — **the ADMIN deal register** (index, create, edit, store) | ✅ yes | ❌ removed |
| **`compliance.fica.approve`** | **Approve a FICA submission** (compliance sign-off) | ✅ yes | ❌ removed |
| **`delete_properties`** | Delete (archive) listings | ✅ yes | ❌ removed |
| **`publish_properties`** | Publish a listing live | ✅ yes | ❌ removed |
| **`manage_p24`** | `/admin/p24/*` — Property24 portal admin + settings | ✅ yes | ❌ removed |
| **`manage_targets`** | `/admin/targets` — set agency/listing targets | ✅ yes | ❌ removed |
| **`deals_v2.override_dates`** | Override DR2 pipeline due dates | ✅ yes | ❌ removed |
| **`agency.authorize_external_access`** | Authorise external access to agency data | ✅ yes | ❌ removed |
| **`testimonials.publish`** | Publish testimonials to the public site | ✅ yes | ❌ removed |
| *…~110 more* | The rest of the manager set (compliance dashboards, leave approval, supervision, worksheets, branch switching, clause editing …) | ✅ yes | ❌ removed |

> **The full 120-key list is one read-only command away** and is NOT reproducible from the repo — it
> exists only in the database, because config has been *tightened since* the July-02 seed (today's
> `branch_manager` config is 217 keys, so `branch_manager − agent` = 95 ≠ 120; some over-grants are
> keys **no role's config grants any more**). To print the authoritative list:
>
> ```
> cd /corex-qa1 && php artisan corex:reconcile-role-grants --roles=agent      # dry-run — changes NOTHING
> ```
> qa1 is a faithful live mirror (restored after the 07-12 proof), so its dry-run *is* live's plan.

---

## 2. What `--apply` would change on live, in plain agency language

**Agents lose. Agents gain nothing. Nobody else is affected at all.**

- An agent can no longer **settle a deal** — no marking commission paid, no settlement print. *(This
  is the security headline: 33 agents can do this on live right now.)*
- An agent can no longer open the **admin deal register** (`/admin/deals`), or create/edit deals there.
- An agent can no longer **approve FICA**, **publish or delete a listing**, **administer Property24**,
  **set targets**, **override DR2 due dates**, or **authorise external access**.
- An agent **keeps their entire real job**: all 123 config-intended keys survive untouched —
  `view_deals`, `deals.view`, `deals.create`, `contacts.view`, `properties.view`,
  `access_deal_register_v2`, `deals_v2.view`, `deals_v2.edit`, `portal_leads.view`, and the rest.
- **No other role changes.** The command refuses non-closed-include roles by design: `super_admin`
  (wildcard) and `admin` (all-minus) are **REFUSED** — their intent isn't exhaustively enumerated, so
  "outside the list" can't be assumed to be drift. Only `agent` is in scope.
- **Nothing is deleted.** Every removal is a soft-delete (non-negotiable #1); the rows keep their IDs.

Proven on qa1 against a real user — **Retha Kelly (#24, agent, agency 1): 240 → 123 → 240** grants
across apply → rollback, with every dangerous key denied after apply and her full legitimate 123-key
set intact throughout.

---

## 3. The rollback story

**One command, total, deterministic.**

- Every `--apply` writes a **snapshot manifest** (the exact row IDs it touched) to
  `storage/app/permission-reconcile/reconcile-<timestamp>.json`.
- Removal is **`deleted_at` only** — rows are never hard-deleted, IDs are preserved.
- Undo is a pure `restore()` of exactly those IDs:
  ```
  php artisan corex:reconcile-role-grants --rollback=storage/app/permission-reconcile/reconcile-<ts>.json
  ```
- Cache is cleared on both apply and rollback, so the change (and the undo) is live immediately — no
  fpm reload needed for the permissions themselves.
- **Verified end-to-end on qa1: 357 removed → `357/357 restored`.** Not a claim; a proof run.

Keep the snapshot path. That is the whole safety net, and it is sufficient.

---

## 4. What Johan should expect the morning after

**One visible change for agents, and it is a correction, not a loss:**

- **The "Deals" admin menu item disappears from the agent's header.** It is gated on `create_deals`
  (`resources/views/layouts/corex-header.blade.php:97`), so it **hides cleanly** — no dead link, no
  403 page. (STANDARDS: no dead buttons.)
- **Agents go back to `/agent/deals` — "My Deals"**, which the codebase itself describes as
  *"Agent: My Deals (read-only, remarks via log)"* (`routes/web.php:555`). It is gated on `view_deals`,
  which agents **keep**. This is the register agents were designed to have; the over-grant had been
  handing them the *admin* register instead.
- **The DR2 registers are unaffected** — `/deals-dr2` is gated on `view_deals` and `/deals-v2` on
  `access_deal_register_v2`, both of which agents keep.

**Expect one kind of complaint, and it is the point of the exercise:** an agent who has settled a deal
or approved a FICA in the last two weeks will find they no longer can. That capability was never
theirs — it arrived by accident on 2026-07-02.

**Nothing else changes.** No data moves, no deals change state, no listings unpublish, no emails fire.
This touches exactly one table (`role_permissions`), one role (`agent`), and sets a `deleted_at`.

---

## Recommended sequence (when authorised)

1. `php artisan corex:reconcile-role-grants --roles=agent` — **dry-run on the target**; eyeball the count (expect ~357 on live).
2. `--apply` — snapshot auto-written. Note the path.
3. `view:clear` + `route:clear` + `config:clear`; reload the live pool (**php8.3** — live is not 8.2).
4. Spot-check one agent: the Deals admin item is gone from the header, `/agent/deals` still works.
5. Keep the snapshot for one week. Rollback is one command if anything looks off.

## The follow-up that stops it recurring

`corex:sync-permissions --merge-defaults` is **additive-only** — it can never remove a grant, so a
tightened config never propagates and the DB stays frozen at the broadest set ever seeded. Until a
pruning path runs routinely, **this drift will come back the next time a role's config is tightened.**
Wiring the reconcile into the deploy is the fix — but it is a destructive command, so that wiring is
Johan's call, not a lane's, and it is deliberately not done (see AT-265, where the same command's
soft-deletes were found to be the trigger for the permission fail-open that was fixed today).
