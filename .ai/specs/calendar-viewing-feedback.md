# Spec: Calendar viewing feedback (one store)

**Status:** Built on QA1 (2026-10-08) - awaiting Johan's test. Not on Staging / live.
**Owner rulings:** Johan, 2026-10-08 (R1-R11). Full trace that led here: `/tmp/viewing-feedback-trace-2026-10-08.md` (mismatches 1-13).
**Pillars:** Property (feedback is about a property), Contact (the buyer), Agent (who captured / who may edit). Read by the seller live link, the agent Intelligence tab, the appointment panel, the contact page and the client app.
**Related:** `seller-live-link.md` §5, `calendar-interactive.md`, `SPEC_calendar_event_classes.md`.

---

## 1. What this is

A viewing appointment can cover several properties. For each property the agent records what happened and what the buyer said. That feedback must be **one thing in one place**, read the same way by every screen. Before this change it lived in two shapes (columns written by the old per-contact form; a JSON bundle written by the per-property form since 20 Aug), each reader looked at one of them, and the three screens that matter (appointment, Intelligence, seller link) therefore disagreed.

## 2. The single store (R1)

Table `calendar_event_feedback`, **columns only**, one row per (appointment, property):

| Column | Meaning |
|---|---|
| `viewing_status` | `viewed` (default) / `did_not_happen` / `declined_on_arrival` (R6) |
| `outcome_option_id` | outcome (agency feedback option, category `outcome`) |
| `concern_option_ids` | concern ticks (option ids) |
| `seller_visible_notes` | the **SELLER comment** - shown to the seller |
| `internal_notes` | the **INTERNAL comment** - agents only, never shown to a seller |
| `next_action_notes` | next action - agents only |
| `captured_by_user_id`, `captured_at` | the ORIGINAL capture - never overwritten by an edit |
| `last_edited_by_user_id`, `last_edited_at` | stamped by every edit |
| `deleted_at`, `archived_by_user_id`, `archive_reason` | archive (soft delete), restorable |
| `feedback_kind` | always `viewing` for a viewing appointment (it used to be stamped `listing_presentation` - mismatch 10) |

`kind_specific_data` (the JSON bundle) is **no longer read or written** for viewings. It stays on old rows as the untouched original.

**Reads** go through `App\Services\Properties\PropertyViewings` (the counting rules, section 4); **writes** through `App\Services\Properties\ViewingFeedbackService` (section 6). Any new reader of viewing feedback must use `PropertyViewings` - never query the table directly.

## 3. The migration command (reversible, idempotent, dry-run first)

`php artisan viewing-feedback:migrate-single-store`
- default = **dry run**: prints each row that would change (outcome label -> option id, ticks, seller note, `feedback_kind` restamp) and the counts; writes nothing.
- `--apply` writes. Every changed row's previous values are stored in `viewing_feedback_migration_log` (before / after, per run token). The JSON is never cleared.
- idempotent: a row already logged (and not reversed) is skipped.
- `--reverse [--run=<token>] --apply` puts the logged "before" values back.
- Outcome labels that match no option are left in the JSON and listed.
- **Deploy step:** run it (dry run first) on each environment right after `migrate`; until it has run, rows written by the current form are not visible to the shared reader (today's behaviour, no worse).

## 4. Counting rule (R6, R7, R9) - `PropertyViewings`

1. A viewing is a calendar event in a viewing category, not deleted, not `dismissed`, **still linked** to the property as a subject property (R9).
2. **Held** (per property): the appointment is `completed` - even with no feedback ("no feedback given" is not "no viewing") - **or** non-blank feedback has been captured for that property. Booked-only / dismissed / deleted never count.
3. Per-property status, from the latest capture: **`did_not_happen`** - does not count, nothing at all is shown to the seller; **`declined_on_arrival`** - is feedback, does **not** add to the viewings-held count, and the seller sees a separate line ("1 buyer arrived but chose not to view").
4. A feedback row belongs to a property only if recorded against it (or, with no property, the appointment's only property). A sibling property's row is never shown or counted (mismatch 11 fixed on the contact page too).
5. **Blank rows** (viewed, nothing ticked, nothing written) are not feedback: they never count, never make a viewing held, never feed a roll-up. The form never creates one.
6. The count is identical for every audience; only the content differs.

## 5. What each audience sees (R2, R5)

- **Appointment panel** (agent-side): per property - status, outcome, concern ticks, seller comment, internal comment, next action, **captured by / when, last edited by / when**; the change log; archived rows with Restore (editors).
- **Intelligence tab**: same data per viewing, plus "buyers who arrived but chose not to view" in the summary.
- **Seller live link**: viewings held; "N buyers arrived but chose not to view"; **every concern tick** (no top-2 cut) as "N of M viewers mentioned X"; **every seller comment** (no top-5 cut) with its outcome. **Never** the internal comment, next action, buyer identity, or anything from a `did_not_happen` property.
- **Contact page**: the row for the property the line is about.

## 6. Who may edit (R3) and the audit trail (R4, R8)

- Permission **`viewing_feedback_edit.view`** (Role Manager, scope own / branch / all): `own` = appointments the user created (agent default), `branch` = appointments in their branch (branch-manager default), `all` = whole agency (admin default). The creator can always edit. Enforced server-side in `ViewingFeedbackService::canEdit()` on the save, archive and restore routes; no role names in views. Everyone else who can see the appointment gets a read-only view (`can_edit: false`, Save hidden).
- **Change log** `calendar_event_feedback_log`: every create / edit / archive / restore, per field: property, contact, field, old value, new value, who, when. An unchanged re-save logs nothing.
- **Archive / restore** (R8): soft delete only, by the same people, logged, restorable. No hard delete.
- **Re-save is safe** (R10): saving finds the existing row for (appointment, property) whatever form wrote it and updates it in place. (Proven on the previous code: re-saving an old-form row from the current form failed with HTTP 500, duplicate key on `cef_event_contact_property_unique`.)
- **Removing a property from an appointment** (R9): its feedback row is archived (reason "property removed from appointment"), not deleted, and it stops counting.

## 7. Routes

`GET command-center.calendar.feedback.show` (form data, `can_edit`), `POST command-center.calendar.feedback.store` (save), `POST command-center.calendar.feedback.archive|restore` (`/calendar/{event}/feedback/{id}/archive|restore`). The appointment JSON (`command-center.calendar.show`) carries `viewing_feedback`.

## 8. Files

`app/Services/Properties/PropertyViewings.php` (read), `ViewingFeedbackService.php` (write, access, log, panel data), `app/Console/Commands/MigrateViewingFeedbackSingleStore.php`, `CalendarController` (`showFeedback`/`storeFeedback` delegate for viewings, `archiveFeedback`/`restoreFeedback`, panel data in `show`), `CalendarEventCreator::syncEventLinks` + `CalendarEventService::syncManualEventLinks` (R9), `ContactController` (viewing lists), `GeneratePropertyRecommendations`, views `calendar/index.blade.php` (panel + form), `calendar/_event-feedback-modal.blade.php`, `properties/show.blade.php` (Intelligence), `seller-link/live.blade.php`; migration `2026_10_17_000100_viewing_feedback_single_store`; permission in `config/corex-permissions.php`.

## 9. Setup Wizard (non-negotiable 10a)

`viewing_feedback_edit.view` is a Role Manager permission, not an agency setting; it is deliberately **not** in the Setup Wizard (Johan's call per the ruling; roles are configured in Role Manager). Recorded here as a decision, not an oversight.

## 10. Known limits / reported, not changed

- Other readers of the table that do not feed the seller link or Intelligence were left on their own queries (they read the columns, which are the one store after the migration): `ReportingService`, `BuyerIntelligenceService`, `BuyersReportService`, `ContactHistoryService`, `CommandCenterService` (missed-feedback prompt), `RecomputeBuyerPropertyViews`, `BackfillLeadFirstResponse`, `BuyersBackfillFlagCommand`, `ReconcileCalendarEvents`. They count raw rows, so a blank row still counts there. Reported to the conductor.
- `CommandCenterApiController::calendarUpdate` (mobile) changes the property scalar without re-syncing link rows, so R9 does not fire from that path.
- The mobile app does not capture viewing feedback today (the web calendar is the only writer).
