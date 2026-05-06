# Calendar Module Audit — 2026-05-04

## Summary
- Total features audited: 12
- PASS: 7
- PARTIAL: 3
- FAIL: 2

---

## Critical findings (FAIL items)

### 1. FAIL — Root Alpine `<div>` missing closing `>`

**File:** `resources/views/command-center/calendar/index.blade.php:47`

The root Alpine container div is missing its closing angle bracket:
```html
<div class="space-y-6" x-data="calendarPage()" x-init="..." @keydown.window="..." @mouseup.window="dragEnd()"
                                                                                                              ^--- NO >
    {{-- PAGE HEADER --}}
    <div ...>
```

**Impact:** Browsers may recover via implicit tag closure, but Alpine.js may fail to initialize the component properly depending on the browser's error recovery. This is the most likely single root cause for:
- Add Event button not working (Alpine state not initialized)
- Keyboard shortcuts not firing
- Drag-to-create not working
- Feedback modal not opening
- All interactive features broken

**Fix:** Add `>` at end of line 47.

---

### 2. FAIL — `store()` does not write to `calendar_event_links` pivot

**File:** `app/Http/Controllers/CommandCenter/CalendarController.php:421-441`

When creating a manual event, `store()`:
- Sets `property_id` FK directly on the event row
- Sets `contact_id` FK to only the FIRST contact from `contact_ids[]`
- Stores extra contacts in `metadata` JSON

It NEVER writes to `calendar_event_links`, so:
- `$event->linkedContacts()` returns empty for user-created events
- `$event->linkedProperties()` returns empty
- `has_contacts` in `show()` returns false
- "Capture Feedback" button never appears for user-created events
- Feedback loop broken for all non-demo events

**Fix:** After creating the event, insert rows into `calendar_event_links` for property + all contacts.

---

## Partial findings

### 3. PARTIAL — Sticky headers (M1 follow-up)

**What works:** Nothing. No sticky CSS was ever applied.
**What doesn't:** Toolbar scrolls away on long views.

**Root cause:** The sticky header task was mentioned in a follow-up message but was never implemented. No `position: sticky` classes exist in the calendar view.

**Layout context:** `position: sticky` WOULD work because the scroll container is `<main id="appScroll" class="flex-1 overflow-y-auto">` (line 91 of corex.blade.php). Sticky elements inside this main would pin correctly.

---

### 4. PARTIAL — Date picker (M1.2)

**What works:** The button rendering, Today button visibility logic, URL navigation.
**What might not work:** The `showPicker()` call depends on Alpine being initialized (see Bug #1). If Alpine fails to init, clicking the date label does nothing.

The `<input type="date">` is hidden with `opacity-0 pointer-events-none h-0 w-0 overflow-hidden` and relies on `showPicker()` API call to open it. This is correct when Alpine works. If Alpine is broken (Bug #1), the date input stays hidden and inaccessible.

---

### 5. PARTIAL — Drag-to-create (M2.3.5)

**What works (if Alpine inits):**
- 196 `@mousedown` handlers correctly placed on half-cells
- Drag state + methods present in calendarPage()
- Overlay elements exist per-column

**What might not work:**
- All depends on Alpine initializing (Bug #1)
- The drag overlay uses absolute positioning within a parent that has `class="relative"` — this is correct
- The `@mouseup.window="dragEnd()"` on the broken root div tag may not fire

---

## Working features (backend-confirmed)

| Feature | Status | Evidence |
|---------|--------|----------|
| M1.1 Demo data | PASS | 60 events, 30/30 split, 13 feedback rows |
| M2.1 Event classes | PASS | 3 active classes for viewing/valuation/listing_presentation |
| M2.2 Event links pivot | PASS | 105 rows, relations resolve correctly |
| M2.4 Feedback tables + seeder | PASS | 22 options, 13 feedback rows, audit log table exists |
| M2.5 Auto-tasks | PASS | 16 open tasks, reconcile command works, storeFeedback closes tasks |
| M2.6 Reschedule endpoint | PASS | PATCH works, audit row written, source-driven rejected |
| M1.3 Cell navigation (HTML) | PASS | 33 `view=day` links rendered in month grid |

---

## Recommended fix order

### Fix 1 — CRITICAL: Close the root `<div>` tag (line 47)
Add `>` to close the Alpine root. This likely fixes ALL interactive features in one shot:
- Add Event button
- Keyboard shortcuts
- Drag-to-create
- Drag-to-reschedule
- Date picker
- Feedback modal
- Detail panel

### Fix 2 — store() writes to calendar_event_links
After event creation, insert proper link rows for property + contacts. This ensures:
- Feedback button appears on user-created events
- Auto-tasks fire correctly for user-created events
- Property/contact inverse relations work

### Fix 3 — Sticky headers (implement)
Add `sticky top-0 z-20` to page header + toolbar wrapper. Background must be opaque (`var(--surface)` or `var(--bg)`). Works within the existing `<main>` scroll container.

### Fix 4 — Verify after Fix 1
After closing the div tag, manually test:
- All keyboard shortcuts
- Drag-to-create
- Drag-to-reschedule
- Feedback modal flow
Many of these should "just work" once Alpine initializes properly.

---

## Suspected root causes

### 1. Broken HTML tag from incremental attribute additions
The root `<div>` on line 47 accumulated attributes across multiple prompts:
- Original: `x-data="calendarPage()"`
- M1.5 added: `@keydown.window="handleShortcut($event)"`
- M2.3.5 added: `@mouseup.window="dragEnd()"`
- M2.5 added: `x-init="if (...)..."`

The final prompt that added `@mouseup.window="dragEnd()"` likely used an Edit that matched the line but didn't include the `>` at the end, or the `>` was on a new line that got consumed by the next edit.

### 2. store() built before calendar_event_links table existed
When M2.3 (create modal V2) was built, the `calendar_event_links` table had been created by M2.2 but the `store()` code was written with a fallback approach (FK + metadata JSON) because the links pattern was new. It was never updated to use the proper pivot.

### 3. Sticky never implemented
The sticky header task arrived as a follow-up message during the M1.5 keyboard shortcuts implementation. It was acknowledged but never executed — the conversation moved to M2 tasks instead.
