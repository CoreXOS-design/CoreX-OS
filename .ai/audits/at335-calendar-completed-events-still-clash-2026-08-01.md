# AT-335 — Calendar flags completed events as clashing/conflicting

**Status:** INVESTIGATION ONLY. No code changed. Awaiting Johan's decision on which fix(es) to authorize.
**Reported by:** Johan. **Investigated:** 2026-08-01. **Repos checked:** `corex-qa1` (QA1) and `corex-dev-5` (main) — the relevant files are byte-identical on both branches; this is not a QA1-vs-main divergence.

## Summary

There is **not one** clash-detection routine in the calendar module — there are **three**, and only one of them is bug-free. The user-visible symptom ("completed events still show as clashing") is best explained by the **week/day grid's lane-packing layout**, which never looks at `status` at all. A second, independent conflict computation in the main grid controller has the same bug but is currently dead code (nothing reads its output). The one routine that IS correct — and that a first pass might mistake for "the" conflict detector — is `ConflictDetectionService`, which already excludes completed/dismissed events.

## How "completed" is represented on the event model

`CalendarEvent` (`app/Models/CommandCenter/CalendarEvent.php`) has no boolean/timestamp for completion — it's a plain string column:

```php
// database/migrations/2026_03_31_300001_create_calendar_events_table.php:23
$table->string('status', 20)->default('pending')->index()->comment('pending, completed, overdue, dismissed');
```

Helpers (`CalendarEvent.php:278-296`):
```php
public function isOverdue(): bool { return $this->status === 'pending' && $this->event_date->isPast(); }
public function markCompleted(): void { $this->update(['status' => 'completed']); }
public function markDismissed(): void { $this->update(['status' => 'dismissed']); }
```

No `scopeNotCompleted()`/`scopeActive()` exists on the model — every consumer below inlines its own status filter (or omits one) rather than sharing a scope.

## The three routines

### 1. `ConflictDetectionService::checkUserConflicts()` — CORRECT, not the bug

`app/Services/CommandCenter/Calendar/ConflictDetectionService.php:16-59`. Powers `GET /calendar/check-conflicts`, consumed by:
- the organizer self-conflict warning, `index.blade.php:3548-3571`
- the invited-attendee conflict badge, `index.blade.php:4396-4413`
- the pending-invitations "Conflicts with…" banner, `invitations.blade.php:44-59` (fresh call per page load, `routes/web.php:1518-1530`)

Already filters correctly:
```php
// ConflictDetectionService.php:43
->whereNotIn('status', ['completed', 'dismissed']);
```
Present since the file's creation (`e9c7968b`, 2026-05-06) and untouched by every later commit including the `occupies_time` refactor (`884a38b8`, 2026-07-02). No commit message ever calls out *why* it excludes completed/dismissed — it's correct but undocumented, and **`tests/Feature/CommandCenter/OccupiesTimeConflictTest.php`'s 5 tests all fixture `status: 'pending'`** — there is zero test coverage proving this exclusion actually holds. If a future refactor of this file drops the line, nothing would catch it.

### 2. Week/day grid lane-packing — **LIKELY ROOT CAUSE**, does not check status

Two byte-identical closures, both named `$layoutDayColumn`:

- `resources/views/command-center/calendar/index.blade.php:63-101`
- `resources/views/command-center/calendar/partials/_day-column.blade.php:36-63`

This is a rendering algorithm, not a "conflict" feature by name — it greedily packs events that overlap in time into side-by-side "lanes" so simultaneous tiles don't sit on top of each other (Google/Outlook-style). It takes the day's event list, sorts by start time, and clusters anything whose time range overlaps — **with no `status` check anywhere in the closure**. A `completed` event is included in `$events` like any other and gets assigned a fractional-width lane if it overlaps another tile.

Where it's consumed, `_day-column.blade.php:128-144`:
```php
$isDone = in_array($evt->status, ['completed', 'dismissed'], true);
...
class="cal-layerable absolute ... {{ $isDone ? 'line-through opacity-70' : '' }}"
style="... left: calc({{ $lane }} / {{ $lanes }} * 100% + 1px); width: calc(100% / {{ $lanes }} - 2px);"
```
`status` is read **only** to add a strikethrough/dim CSS class — it is never used to pull the event out of the lane cluster. So a completed viewing that overlaps another appointment still renders squeezed to (e.g.) 50% width, sitting right next to the other tile — dimmed and struck through, but geometrically identical to a real live clash. This is a very plausible read of "the calendar still flags them as clashing," and it only affects the **week/day grid views** — the month view (`_month-block.blade.php:174-196`) lists events in a plain vertical list with no lane math, so this symptom should be view-specific. Worth confirming with Johan which view he was on when he saw it.

### 3. `CalendarController::applyFilters()` `$event->has_conflict` — same bug, but dead code

`app/Http/Controllers/CommandCenter/CalendarController.php:2231-2267`, called from `applyFilters()` (feeds `index()`, `renderWeek()`, `renderDay()`, `monthBlockData()`, `events()`, and more — 10 call sites). A manual O(n²) sweep over same-user appointments:

```php
$appointments = $result->filter(fn($e) => !in_array($e->category, $nonOccupyingClasses))
    ->sortBy('event_date')->values();
$conflictIds = [];
for ($i = 0; $i < $appointments->count(); $i++) {
    for ($j = $i + 1; $j < $appointments->count(); $j++) {
        $a = $appointments[$i]; $b = $appointments[$j];
        if ($b->event_date < ($a->end_date ?? $a->event_date)) {
            $conflictIds[$a->id] = true; $conflictIds[$b->id] = true;
        } else { break; }
    }
}
...
$event->has_conflict = isset($conflictIds[$event->id]);   // line 2267
```
Filters by `occupies_time` category only — **no status exclusion at all**. However: `grep -rn "has_conflict"` across every blade/JS file shows `$event->has_conflict` (set here) is **never read anywhere** — the two live UI reads of `has_conflict` (`index.blade.php:3569`, `:4408`) are both `data.has_conflict` from the separate `/check-conflicts` JSON response, not this property. This computation is currently pure waste (runs on every grid render, touches nothing) and a landmine: the moment anyone wires a badge to it, it will reproduce this same bug.

## Root cause (best assessment)

**#2 (lane-packing) is the mechanism most likely producing what Johan is seeing** — visually indistinguishable from a real clash on the week/day grid, for exactly the reported case (event is completed, still looks like it's clashing). **#3 is a related landmine, not the current symptom**, since its output is unconsumed. **#1 is already correct** and should not be touched except to add test coverage.

Two secondary findings surfaced during the trace, reported for completeness — neither is the direct cause of AT-335, but both are adjacent enough that a fix session here will likely touch the same code:
- `CalendarEventService::getEventsForRange()` (`CalendarEventService.php:154-177`) only excludes `dismissed` recurring parents from expansion, not `completed` — a completed recurring series keeps generating future virtual occurrences (correctly excluded from `ConflictDetectionService`, but still rendered as if scheduled).
- The "Complete" action (`completeFromContext()`, `index.blade.php:4082-4090` → `CalendarController::complete()` line ~1775) has no this/future/all recur-scope prompt, unlike Edit and Delete — completing one occurrence of a recurring series marks the **entire parent series** completed. Filed separately; flagging here because it compounds with the recurring-expansion gap above.

## Proposed fix (NOT implemented — Johan to decide)

1. **Primary:** exclude `status IN ('completed','dismissed')` events from the lane-clustering input in both `$layoutDayColumn` closures (`index.blade.php:63-101`, `_day-column.blade.php:36-63`) — either filter them out of `$events` before clustering (so they don't consume a lane against active events) and render them in their own always-full-width row/pass below the live lanes, or give them a dedicated lane group that never competes with active-event width. Recommend factoring the duplicated closure into one shared helper while touching it, since the two copies already have to be kept in lockstep by hand (`_day-column.blade.php`'s docblock literally says "identical geometry to the classic week overlay").
2. **Related cleanup:** either delete the dead `has_conflict` sweep in `CalendarController::applyFilters()` (`CalendarController.php:2231-2267`) since nothing reads it, or — if there's a plan to wire it to a grid badge — add the same `whereNotIn`/`!in_array(status, ['completed','dismissed'])` exclusion `ConflictDetectionService` already has, so it doesn't ship pre-broken.
3. **Test gap:** add a case to `OccupiesTimeConflictTest` (or a new test) that fixtures a `status='completed'` event overlapping an active one and asserts it's excluded — currently every fixture in that file is `status: 'pending'`, so the one correct implementation (`ConflictDetectionService`) has no regression protection.
4. **Out of scope for AT-335, flag separately:** the recurring-parent-completion gap in `CalendarEventService::getEventsForRange()` and the missing recur-scope prompt on Complete.

## Blast radius of fix #1 (the recommended primary fix)

- Two files: `resources/views/command-center/calendar/index.blade.php` (week/day overlay rendering) and `resources/views/command-center/calendar/partials/_day-column.blade.php` (lazy-loaded day-column partial, same geometry).
- Visual-only change — no controller, route, model, or migration touched. No JSON API shape changes.
- Affects the **week and day grid views only**; month view is unaffected (different rendering, no lane math).
- Every event that currently shares a time slot with a completed/dismissed event will re-render at full width instead of split — needs a visual re-check across a few QA1 deals/agents with completed events overlapping active ones, on both week and day views, before promoting.
- No change needed to `ConflictDetectionService` or its three consumers (self-conflict warning, attendee badge, invitations banner) — confirmed already correct.
