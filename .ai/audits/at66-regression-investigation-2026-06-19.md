# AT-66 Regression Investigation — Calendar Viewing Feedback

**Date:** 2026-06-19 (reverted 2026-06-20)
**Author:** Johan Reichel (with Claude)
**Status:** AT-66 reverted from Staging. Live (`main`) was never affected.
**Scope:** Staging only. `main`/production untouched throughout.

---

## 1. Summary

AT-66 began as a **small bug**: on a *link-less / contactless* viewing event, the
"Capture Feedback" modal rendered a **blank body**. That narrow defect was
**mis-scoped into a full rebuild** of the viewing-feedback subsystem.

In the course of that rebuild AT-66:

1. **Flipped the data model** — changed `viewing` (and `listing_presentation`,
   `property_evaluation`) `feedback_mode` from `per_contact` → `per_property`
   at the seeder's canonical creation point
   (`CalendarEventClassSeeder`), plus a one-off DB update on Staging.
2. **Rebuilt the modal body** — replaced the working per-contact
   "Property 1 of N" stepper ("Save & Next Property") with a per-property body
   and a contact fan-out summary.
3. **Added agency-configurable fan-out role mapping** — new nullable
   `agency_contact_settings.feedback_fanout_roles` column + model surface.
4. **Added a recovery path** — a `link-property` route/action to attach a
   property to a link-less event from the empty state.

### What broke

The rebuild **regressed two flows that work correctly on live**:

- **(B) The per-contact stepper.** Live captures feedback per *(contact ×
  property)* via a "Property 1 of N" stepper with a "Save & Next Property"
  button. AT-66's per-property body removed that stepper, changing the capture
  UX and the rows written to `calendar_event_feedback`.
- **(C) Buyer fan-out.** Captured feedback fans out to the buyer's "Viewings &
  Feedback" tab and to seller-visible notes on the property / sale screen.
  The per-property rewrite regressed this fan-out.

### Where it landed

AT-66 was merged into **Staging only** (merge commit `92e2adcd`). It **never
reached `main`/live**. Live's per-contact stepper has always been correct and
remains the reference behaviour.

---

## 2. The actual outstanding bug (NOT fixed here)

The original defect that triggered AT-66 is real and still open:

> On a **contactless / link-less viewing** (e.g. event 5758), the Capture
> Feedback modal renders a **blank body**.

Root cause (from the AT-66 seeder comment and code review): the per-contact
feedback branch assumes attendees/contacts (and, for property context, a linked
property) exist. A viewing with no linked contact/property falls through to an
empty render. This is a **small, contained bug** that predates AT-66 and is
**unrelated to the per_contact ↔ per_property question**. It should be fixed as
its own narrow ticket — render a sensible empty/recovery state for link-less
viewings — **without** rebuilding the feedback model or touching the stepper.

---

## 3. Resolution — revert, not reset

The original plan was `git reset --hard` to the pre-AT-66 commit (`2d8416e4`) +
force-push, on the assumption that the AT-66 merge was the **tip** of Staging.

**That assumption was stale.** Between the AT-66 merge and the time of cleanup,
an entirely separate, substantial feature — the **Shared Drive document module**
(`SharedDriveFolder`/`SharedDriveFile` models, controller, service, two
migrations, spec `.ai/specs/shared-drive.md`, sidebar nav, permissions, tests) —
landed on Staging on top of the AT-66 merge:

```
1c0d93f2 fix                              # Shared Drive follow-up
88bec166 fix                              # Shared Drive follow-up
8cb447c8 Merge branch 'main' into Staging
69ea9e35 fix                              # Shared Drive feature module
92e2adcd Merge AT-66 into Staging         # <- NOT the tip
2d8416e4 Batch contact.fica_missing purge # reset target
```

A hard reset to `2d8416e4` would have **permanently destroyed the Shared Drive
feature**. AT-66 (calendar/command-center) and Shared Drive (documents) share
**no application code** — only the auto-generated `mysql-schema.sql` snapshot and
`routes/web.php` overlap. So AT-66 was backed out **non-destructively** via:

```
git revert -m 1 92e2adcd      # revert commit e14d5f01
```

### Conflict resolution
- **`routes/web.php`** — auto-merged. Shared Drive routes kept; only AT-66's
  `command-center.calendar.link-property` route dropped.
- **`database/schema/mysql-schema.sql`** — regenerated from `hfc_staging` via
  `php artisan schema:dump` (not hand-merged). Reflects the live staging DB:
  Shared Drive tables present; the additive nullable
  `agency_contact_settings.feedback_fanout_roles` column retained (harmless).

### DB
- The additive nullable `feedback_fanout_roles` column is **left in place** on
  `hfc_staging` (harmless; no code references it after the revert).
- `viewing` `feedback_mode` restored to **`per_contact`** to match live.

### Commits backed out by the revert
`f21085bc · 22b38310 · ea60c8bf · 54354634 · 2fbe05cc` (AT-66 steps 1–5),
delivered to Staging via merge `92e2adcd`.

---

## 4. Lessons

1. **A blank modal is a render bug, not a data-model question.** The fix for an
   empty render is an empty/recovery state — not a per_contact → per_property
   migration and a body rebuild.
2. **Don't assume a merge is still the branch tip.** Always re-fetch and inspect
   what sits above a merge before reset/force-push. Here it saved the Shared
   Drive feature.
3. **Prefer `revert -m 1` over `reset --hard` on shared branches.** It preserves
   unrelated work and never rewrites history other developers have pulled.

---

## 5. Follow-up

- [ ] New small ticket: contactless/link-less viewing → Capture Feedback modal
      renders blank. Fix the empty render only.
- [x] AT-66 reverted from Staging (`e14d5f01`).
- [x] `main`/live confirmed untouched.
