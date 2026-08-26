# Two adjacent recurring-event bugs found during AT-335 — investigation + proposal

**Status:** INVESTIGATION + PROPOSAL ONLY. No code changed. For Johan to approve / raise as their own tickets.
**Investigated:** 2026-08-01. **Repo:** `corex-qa1` (QA1).

## They are coupled — read both before picking a fix order

Bug (2) is the trigger; bug (1) is what makes its blast radius bad. Today, **every single-occurrence "Complete" click on a recurring event completes the entire series** (bug 2), and because the series-expansion query only excludes `dismissed` parents, not `completed` ones (bug 1), **every future occurrence of that now-completed parent keeps generating and rendering — struck through, as if already done — for dates that haven't happened yet.** A recurring "call the tenant monthly" reminder, completed once, silently shows every future month as done.

Recommend fixing **(2) first** — it removes the everyday trigger. **(1)** still needs its own decision because a deliberate "complete the whole series" action (which a proper scope-prompt fix to (2) would newly *offer* as an explicit choice) raises the same question on purpose, not by accident.

---

## Bug 1 — recurring-series completion doesn't stop future occurrences generating

### Root cause

`CalendarEventService::getEventsForRange()` (`app/Services/CommandCenter/CalendarEventService.php:154-171`) — the query that selects which recurring **parents** get expanded into virtual occurrences for the visible date range:

```php
$parentQuery = CalendarEvent::query()
    ->where('is_recurring', true)
    ->where('event_date', '<=', $endC)
    ->visibleTo($user, $scope);
...
// Mirror the base status handling: a dismissed parent = "delete all".
if (!empty($filters['status'])) {
    if ($filters['status'] !== '*') {
        $parentQuery->where('status', $filters['status']);
    }
} else {
    $parentQuery->where('status', '!=', 'dismissed');   // ← only excludes dismissed
}
```

Only `dismissed` parents are excluded from expansion. A `completed` parent is still expanded by `RecurrenceExpander::expand()` (`app/Services/CommandCenter/Calendar/RecurrenceExpander.php:57-113`) for every occurrence date in the visible window, **including dates in the future.**

Each virtual occurrence is built by `makeVirtualOccurrence()` (`RecurrenceExpander.php:170-191`):
```php
$occ = $parent->replicate([
    'is_recurring', 'recurrence_rule', 'parent_event_id',
    'reminder_offsets', 'reminders_sent',
]);
```
`status` is **not** in the exclusion list, so `replicate()` copies the parent's `status='completed'` onto every occurrence, past and future alike. The render layer (`resources/views/command-center/calendar/partials/_day-column.blade.php:128-144`) reads that copied status purely for styling: `$isDone = in_array($evt->status, ['completed', 'dismissed'], true)` → strikethrough/dim CSS class — it doesn't stop the tile from rendering. So a completed recurring series doesn't disappear; it turns into a permanent trail of greyed-out "done" tiles stretching into the future.

**This exclusion is deliberate for the non-recurring case** — `CalendarEventService.php:92-130`'s CAL-8 comment is explicit: *"Completed events are kept on the grid... users want to see what they finished."* That's correct and should not change. The bug is that the **same exclusion rule was mechanically mirrored onto the recurring-parent query** (the comment literally says "Mirror the base status handling") without asking whether "a single completed event stays visible" and "a completed recurring **series**" mean the same thing. They don't — the parent's `status` isn't describing one appointment, it's gating whether an entire future-generating series keeps generating.

### Proposed fix

Exclude `completed` (alongside the existing `dismissed`) from the **recurring-parent query only** — `CalendarEventService.php:170`:
```php
$parentQuery->whereNotIn('status', ['dismissed', 'completed']);
```
Leave the **base (non-recurring) query untouched** (`CalendarEventService.php:129`) — a single completed appointment should keep showing struck-through, per the existing CAL-8 decision; only the recurring-*parent* gate changes.

**Open product question for Johan (flag in the ticket, don't default silently):** should occurrences **before** today stay visible/struck-through as history even after the parent is fully completed, with only *future* occurrences suppressed? The fix above suppresses the whole series uniformly once the parent is `completed`, which is the simple version and matches how `dismissed` already behaves — but "history" and "stop repeating" could reasonably want different cutoffs. Recommend shipping the simple uniform version first (matches existing `dismissed` semantics exactly, smallest change) unless Johan wants the date-split version.

### Blast radius

- One file, one line: `app/Services/CommandCenter/CalendarEventService.php:170`.
- Affects only recurring events whose **parent** status is `completed` — i.e., only reachable today via bug (2)'s over-broad Complete action, or (once bug 2 is fixed) via a deliberately-chosen "complete all" scope.
- No DB/schema/API shape change. No effect on non-recurring events (separate code path, untouched).
- Should ship together with, or after, bug (2)'s fix — fixing (1) alone still leaves a single click able to vanish an entire future series' visibility; fixing (2) alone still leaves any *existing* completed-parent series in the DB rendering wrong until (1) also lands. Recommend both in the same ticket/PR.

---

## Bug 2 — "Complete" has no this/future/all scope prompt like Edit/Delete

### Root cause

Edit and Delete both already have full recurring-scope handling. Update (`app/Http/Controllers/CommandCenter/CalendarController.php:1560-1620`):
```php
$scope = $data['recur_scope'] ?? null;
$occ   = $data['occurrence_date'] ?? null;
if ($calendarEvent->is_recurring && $occ && in_array($scope, ['this', 'future'], true)) {
    $svc = app(\App\Services\CommandCenter\Calendar\RecurrenceEditService::class);
    $result = $scope === 'this' ? $svc->editOccurrence(...) : $svc->editFuture(...);
    ...
}
```
Destroy (`CalendarController.php:1699-1739`) has the equivalent `deleteOccurrence`/`deleteFuture`/`deleteAll` branch. Frontend prompts the user for scope before either request goes out: `onFormSubmit()` (`index.blade.php:4135-4143`) for edit, `deleteEvent()` → `openRecurScopeModal('delete')` (`index.blade.php:4195-4201`) for delete.

**`complete()` has none of this** (`CalendarController.php:1775-1808`):
```php
public function complete(Request $request, CalendarEvent $calendarEvent)
{
    // (deal-step bridge branch, unrelated to recurrence)
    ...
    // Default: mark calendar event complete directly (non-deal events)
    $calendarEvent->markCompleted();
    ...
}
```
No `is_recurring` check, no `occurrence_date`/`recur_scope` input at all — `markCompleted()` runs unconditionally on whatever `$calendarEvent` resolves to. For an occurrence, that's always the **parent**: `show()` (`CalendarController.php:672-694,764-765`) resolves the route-bound parent, only overlays the occurrence's date/time *in memory*, and returns `'id' => $calendarEvent->id` — the parent's real id — in its JSON (alongside `occurrence_date`/`is_occurrence`/`recurrence_parent_id`, which the frontend already receives but doesn't act on).

All **three** frontend entry points that trigger completion post straight to the parent id with no occurrence/scope data, even though `panelData` already carries it:
- Panel Complete button, `index.blade.php:2339-2340` — form posts to `'/corex/command-center/calendar/' + panelData.id + '/complete'`.
- Context-menu shortcut `completeFromContext()`, `index.blade.php:4082-4090` — same, via `fetch`.
- "Complete with Reason" flow, `index.blade.php:4002-4004` — same target, driven by `reasonPickerEventId = panelData.id`.

**Same gap exists in `dismiss()`** (`CalendarController.php:1810-1814`, and the same three frontend entry points share the reason-picker's `dismiss` branch) — flagging alongside since it's the identical bug shape and a real fix should almost certainly cover both actions in one pass, not leave `dismiss()` behind as a second copy of this bug.

**Why `RecurrenceEditService` can't just be reused as-is for this:** its `editOccurrence()` hardcodes `'status' => 'pending'` on the exception child it creates (`app/Services/CommandCenter/Calendar/RecurrenceEditService.php:52`) and does not read a `status` field from its `$fields` input at all. `editAll()`'s field allow-list (`RecurrenceEditService.php:149`) also excludes `status`. Neither method can express "completed" today — a real fix needs new methods on this service, not a parameter tweak to the existing ones.

### Proposed fix

**Backend — `app/Services/CommandCenter/Calendar/RecurrenceEditService.php`:** add scope-aware completion methods mirroring the existing delete-scope shape (`deleteOccurrence`/`deleteFuture`/`deleteAll`, lines 161-210):
- `completeOccurrence(CalendarEvent $parent, string $occurrenceDate, User $user): CalendarEvent` — same tombstone-child pattern as `deleteOccurrence()` (lines 161-183), but `status: 'completed'` instead of `'dismissed'`. This occurrence renders done; the series and every other occurrence is untouched.
- `completeAll(CalendarEvent $parent): void` — `$parent->update(['status' => 'completed'])`, i.e. today's current (accidental) behavior, now reachable only as an explicit, deliberate choice.
- `completeFuture(...)` — **flag as an open question for Johan rather than building by default.** "Complete this and all future occurrences" is a much less common real-world intent than "this one" or "the whole series" — Delete/Edit offer it because rescheduling/removing a whole future block is a normal ask, but pre-emptively marking *unoccurred future events* as "done" is an unusual concept. Recommend shipping with just `this`/`all` scope options for Complete (and Dismiss) unless Johan specifically wants `future` too.

**Backend — `CalendarController.php`:** give `complete()` (and `dismiss()`) the same `is_recurring` + `recur_scope`/`occurrence_date` branch `update()` already has (`CalendarController.php:1607-1620` is the exact pattern to mirror), dispatching to the new `RecurrenceEditService` methods.

**Frontend — `index.blade.php`:** the existing `openRecurScopeModal()` used by delete already prompts this/future/all and is reusable — wire the panel Complete button (line 2339), `completeFromContext()` (line 4082-4090), and the reason-picker's complete branch (line 4002-4004) to call it (with `future` omitted per the above, pending Johan's call) before posting, passing `occurrence_date`/`recur_scope` the same way the edit/delete flows already do. Same treatment for the dismiss branch alongside, since it shares the identical gap.

### Blast radius

- New methods in `RecurrenceEditService.php` (additive — doesn't change `editOccurrence`/`editFuture`/`editAll`/`deleteOccurrence`/`deleteFuture`/`deleteAll`).
- `CalendarController::complete()` and `::dismiss()` gain a branch each, following the exact pattern already proven in `update()`/`destroy()` — low risk, established shape.
- Frontend: 3 call sites in `index.blade.php` (panel button, context-menu shortcut, reason-picker) gain a scope-prompt step before submitting — same UX pattern users already see for Edit/Delete on a recurring event, so no new interaction concept for the end user.
- **Non-recurring events are entirely unaffected** — the new branch only activates when `is_recurring` is true, exactly like `update()`/`destroy()` today.
- Existing recurring series that are *already* wrongly `completed` in the DB (a side effect of today's bug) are not retroactively fixed by this change alone — once bug (1) also lands, their future occurrences stop rendering; nothing further needed since no occurrence rows were ever persisted (they're virtual/synthetic), so there's no cleanup migration required.

---

## Recommended sequencing

1. Ship as one ticket (or two tightly-linked tickets) — they're the same defect story and review together more easily than apart.
2. Land bug (1)'s one-line fix and bug (2)'s scope-prompt fix together; (1) alone is incomplete (still describes wrong data once (2) legitimately offers "complete all"), (2) alone leaves currently-broken series rendering wrong until (1) also lands.
3. Two decisions to get from Johan before writing code: (a) uniform full-series suppression vs. date-split "past stays visible, future stops" for bug (1); (b) whether Complete/Dismiss need a `future` scope option or just `this`/`all`.
4. `dismiss()` shares bug (2)'s exact shape — recommend it rides in the same fix rather than becoming a third ticket later.
