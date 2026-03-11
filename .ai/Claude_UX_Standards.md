# CoreX OS — UX Standards & Rules

> This document is LAW. Every feature, fix, page, and component must
> comply. VS Code Claude must read this before ANY UI work.

## Rule 1: Navigation Stability

The sidebar menu MUST be globally stable.
- Clicking a menu item loads the page. The sidebar stays exactly as it was.
- The active item highlights. Nothing else opens, closes, or jumps.
- Submenus only open when their parent is clicked — never auto-opened
  on page load unless the current page is a child of that submenu.
- No menu item should ever auto-expand or steal focus from the user's
  current context.

Implementation: The sidebar must track the active route and ONLY expand
the submenu that contains the active page. All other submenus stay in
their current state (open or closed as the user left them).

## Rule 2: Save Stays Where You Are

When a user clicks Save, the page MUST NOT scroll to the top or reload.
- Save actions use AJAX (fetch/axios), not form submissions that reload.
- After save: show a success toast/notification, keep scroll position,
  keep all form state.
- If validation fails: show errors inline next to the fields, do not
  scroll away from the error.
- The only exception is "Save and Close" which navigates back to the
  parent list.

## Rule 3: Menu Structure — Clear, Flat, Findable

Menus must be intuitive for non-technical estate agents.
- Maximum 2 levels deep (parent → child). Never 3 levels.
- Group by workflow, not by technical module.
- Every menu item label must be self-explanatory — no jargon, no
  abbreviations unless universally understood.
- Related items live together. If a user has to hunt, the menu is wrong.
- Sidebar should show icons + labels. Collapsed state shows icons only.

## Rule 4: No Horizontal Scrolling — Ever

The page body NEVER scrolls horizontally.
- Tables that exceed viewport width: use a scrollable container
  (overflow-x-auto on the table wrapper) while the page stays fixed.
- Cards and grids must wrap responsively.
- Form layouts must stack on smaller screens.
- Test rule: if the browser horizontal scrollbar appears on any
  screen ≥1024px wide, it's a bug.

## Rule 5: Sticky Action Bars

Action buttons (Save, Back, Delete, Export, etc.) MUST be visible
at all times without scrolling.
- Every page that has actions gets a sticky header bar
  (sticky top-0 z-10 with background).
- The bar contains: Back button (left), Page title (center/left),
  Action buttons (right).
- This bar stays fixed as the user scrolls through content.
- Section-specific buttons (e.g., "Add Row" inside a table) can live
  inline, but primary actions are ALWAYS in the sticky bar.
- PDF Splitter, document editors, long forms — the split/save/submit
  button is ALWAYS visible. Period.
- Sticky headers must pin flush below the top nav bar with ZERO gap.
  Use the full-bleed wrapper pattern: parent div with `-m-4 lg:-m-6`,
  sticky bar as direct child with `sticky top-0`, content below with
  `p-4 lg:p-6`. Use `flush` prop on `<x-page-header>` when inside
  a full-bleed wrapper.

Standard layout:
```
┌─────────────────────────────────────────────────┐
│ ← Back    Page Title              [Save] [More] │ ← STICKY
├─────────────────────────────────────────────────┤
│                                                 │
│  Page content scrolls here                      │
│                                                 │
└─────────────────────────────────────────────────┘
```

## Rule 6: Lists Are Professional

Every list/table in the system MUST have:
- **Search**: text search across key fields, instant filter as you type
- **Filters**: dropdowns or toggles for status, type, branch, date range
  — whatever makes sense for that data
- **Sort**: clickable column headers, visual indicator of sort direction
- **Default sort**: newest first (created_at DESC) unless another sort
  makes more logical sense
- **Pagination or virtual scroll**: never dump 500+ rows on screen
- **Empty state**: helpful message when no results, not a blank void
- **Count**: always show "Showing X of Y results"

If a list has no search, no sort, and no filter — it's not done.

## Rule 7: Screen Space Is Sacred

Every pixel must earn its place.
- Forms use grid layouts (2-3 columns on desktop) not single-column
  stacks unless the fields genuinely need full width.
- Cards in lists show ONE line of key info by default. Details expand
  on click (accordion/drawer pattern).
- Spacing is consistent but compact: not cramped, not wasteful.
- Large edit forms: group related fields in collapsible sections,
  first section open by default.
- Blank/white space larger than the content it surrounds is a bug.

Reference: user management page should show 10-15 users visible
without scrolling, not 1-2.

## Rule 8: Never Break Flow

A user should NEVER have to abandon their current task to configure
something elsewhere.
- If a dropdown needs a new option, provide an inline "Add New" that
  opens a modal — don't send them to a settings page.
- If a required field depends on setup that hasn't been done, show a
  clear inline message with a link that opens in a new tab or modal.
- Wizards and multi-step processes save progress at each step.
- Unsaved changes get a "You have unsaved changes" warning on navigation.

## Rule 9: Responsive Without Compromise

CoreX must work on:
- Desktop (1920px, 1440px, 1366px, 1280px, 1024px)
- Tablet landscape (1024px)
- MacBook (1440×900, 1280×800) — this is explicitly called out as
  needing to work

Minimum supported width: 1024px. Below that, show a "please use a
larger screen" message rather than a broken layout.

## Rule 10: No Hard Deletes — Ever

All "delete" actions MUST be soft deletes (archive/trash).
- Show the user a "Delete" button — the label is fine.
- Behind the scenes: set a `deleted_at` timestamp (Laravel SoftDeletes)
  or an `archived` / `is_active` flag. NEVER run a hard DELETE query.
- Deleted/archived records disappear from normal views.
- Admin can recover archived records (build a "Trash" or "Archived"
  filter on list pages).
- This applies to EVERYTHING: documents, templates, clauses, deals,
  presentations, properties, listings, users — no exceptions.
- If a model doesn't have SoftDeletes yet, add the trait and migration
  before implementing any delete functionality.

## Rule 11: Permissions Are Mandatory

Every new feature MUST include permission entries.
- Add a permission key to `CoreXPermissionSeeder.php` in the correct section.
- Assign to roles following the pattern: super_admin gets everything,
  admin gets most, branch_manager gets operational items, agent gets
  their daily tools, viewer gets read-only.
- Gate the sidebar item with `@permission('key')`.
- Gate the route with `->middleware('permission:key')`.
- The Role Manager (`/corex/role-manager`) is the single source of truth
  for who can access what. No hardcoded role checks in routes, controllers,
  or views. Use `auth()->user()->hasPermission('key')` instead.
- Run the seeder after adding: `php artisan db:seed --class=CoreXPermissionSeeder`

## Rule 12: Branch Scoping

Data visibility follows this hierarchy:
- **Agent**: sees only their own records (where user_id = me)
- **Branch Manager**: sees all records in their branch (where branch_id = my branch)
- **Admin / Super Admin**: sees all records

Exceptions (shared data, visible to all roles):
- P24 Alerts / Portal Listings (market intelligence)
- Knowledge Base documents (shared reference)
- Presentation data sources (P24 comparables, CMA, suburb stats)
- Lookup tables (suburbs, property types, designations)

Implementation: Use `scopeVisibleTo(User $user)` on models, following
the pattern in `Deal.php`. Apply in every controller index action
and authorize in show/edit/delete actions.

Templates and document packs respect their own visibility setting
(global vs branch-specific) — don't override with branch scoping.

## Every New Feature Checklist

Before marking ANY feature as done, verify:
- [ ] Navigation link exists (sidebar, menu, or button)
- [ ] Permission key added to seeder + sidebar + route middleware
- [ ] Branch scoping applied (scopeVisibleTo on model, applied in controller)
- [ ] Sticky action bar with Back + primary actions
- [ ] No horizontal page scroll
- [ ] Lists have search, filter, sort, pagination
- [ ] Forms save via AJAX, maintain scroll position
- [ ] Screen space is efficient (no oversized elements)
- [ ] Works at 1280px width minimum
- [ ] Empty states are handled
- [ ] Loading states are shown for async operations
- [ ] Delete = soft delete / archive (never hard delete)
- [ ] All data transformations in controller, not in Blade @json()

## Component Reference

### `<x-page-header>` — Use on every page
Props: `title` (required), `back-route`, `back-label` (default "Back"),
`sticky` (default true), `flush` (default false — use true inside full-bleed wrappers)
Slot: `actions` (buttons on the right)

### `<x-list-header>` — Use on every list/table page
Props: `title`, `count`, `total`, `search-placeholder`, `search-model`,
`form-action` (for server-side), `paginator`, `back-route`, `back-label`, `sticky`
Slots: `filters` (dropdowns), `actions` (buttons)

### `<x-sort-header>` — Use on sortable table columns
Renders clickable column header with asc/desc arrow indicator.
Toggles via query params `?sort=field&direction=asc|desc`.

### Sticky Header Pattern (full-bleed, zero gap)
```blade
<div class="-m-4 lg:-m-6">
    <x-page-header title="Page Title" :flush="true">
        <x-slot:actions>
            <button>Save</button>
        </x-slot:actions>
    </x-page-header>
    <div class="p-4 lg:p-6">
        {{-- page content here --}}
    </div>
</div>
```

### Toast Notifications
Success: green, auto-dismiss after 3s
Error: red, stays until dismissed
Position: top-right, below sticky header

### Data in Alpine x-data
NEVER put PHP closures or complex expressions inside `@json()` in Blade.
Always transform data in the controller and pass simple variables/arrays
to the view. In Blade, only use `@json($simpleVariable)`.