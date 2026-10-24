## 6a. Editing a lease after creation — built, 2026-09-15 (conductor ruling, "B")

Full CRUD is the floor: deposit, end date, and lease type are editable after creation via an inline
edit toggle on the lease show screen, posting to the existing `update()` route. Deliberately NOT
editable there: `rental_amount` (only ever changes through a recorded escalation, §3.4, so the rate
history stays a true, unbroken record) and `start_date` (correcting it is a delete-and-recreate, only
possible while nothing has attached — §2's `isDeletable()` — not a silent edit of a term the tenant
agreed to).

### 6a.i Edit-screen layout, fixed 2026-09-22 (Johan: "why are the fields not lined up?")

The edit form's fields now use the SAME shared `.prop-input`/`.prop-select`/`.prop-label` classes
(`resources/css/corex.css`) already used on the property screen, onboarding, and rental inventories —
one shared box model guarantees identical label position and field height down the column, rather than
approximating it with one-off Tailwind + inline-style combos per field (the previous cause of the
misalignment: Monthly rental's read-only display and Deposit's editable input used near-identical but
not byte-identical class/style combinations). End date now sits beside the Month-to-month checkbox
instead of alone — it was being stranded by CSS grid's default (non-`dense`) auto-placement whenever a
later `col-span-2` item (Lease type or the no-approval threshold) couldn't fit beside it and forced a
new row instead of filling the gap.

**The grid container itself stays a CONSTANT `grid-cols-2`** (unchanged from before this pass) —
responsiveness lives on the INDIVIDUAL fields this commit owns instead (`col-span-2 sm:col-span-1`:
full width below 640px, half width paired above it), never on the container's own explicit column
count. This is not a style preference — it is a real bug found and fixed only by a genuine headless-
browser render at 390px (a static HTML/CSS read missed it entirely; see the Verify note below): the
no-approval threshold field's own `col-span-2` class is deliberately untouched per the cc5 coordination
below, and if the CONTAINER had instead dropped to `grid-cols-1` at mobile width (the first shape this
was built in, then reverted), that field's span-2 request would exceed the grid's one explicit column,
forcing CSS Grid to fabricate an unwanted IMPLICIT second column sized to that field's own content —
which then corrupted every OTHER row's column widths too, producing exactly the overlapping,
near-zero-width "Monthly rental" box Johan's original complaint was about, just relocated to mobile
width instead of fixed. Keeping the container's explicit column count constant at 2 makes the
untouched field's span-2 always resolve against real, existing tracks, at every viewport.

**Buttons — Save changes / Cancel / Activate / Cancel lease / Archive — now live in ONE unified flex
row**, replacing two previously-separate blocks (the edit form's own Save/Cancel div, and a second,
always-rendered "status actions" div below it that held Activate/Cancel lease/Archive). Save changes
now submits the form via the HTML5 `form="lease-edit-form"` attribute (the same cross-form-binding
pattern already used on the property screen — see `.ai/specs/rental-property-tab.md`) rather than
living inside the `<form>` element, so it can sit in the merged row without the fields themselves
needing to move. **Permission mapping is unchanged and independently preserved**: Edit/Save
changes/Cancel/Activate/Archive still require `leases.create`; Cancel lease still requires only
`leases.cancel` — the destructive group (Cancel lease, Archive) is deliberately NOT nested inside the
`leases.create` `@permission` block, so a user with `leases.cancel` but not `leases.create` still sees
Cancel lease, exactly as before this layout pass. Cancel lease and Archive are visually grouped,
right-aligned (`ml-auto`), and separated from Save/Cancel/Activate by a left border divider — Johan's
"keep it visually distinct... not an equal sibling of Save" — using the same `var(--ds-red, #dc2626)`
outline convention Archive already used elsewhere in CoreX (rental applications, rental inspections,
rental fault reports).

**Screen-space rule applied**: the no-approval spend threshold field's two lines of helper text
("Leave blank to use the agency's own default...") are removed — Johan's standing rule that every line
is either data or a control, never explanatory prose duplicating what the field's own state already
shows. **This field's label/input classes and grid placement are otherwise deliberately untouched** —
cc5 (work-order quotes build, 2026-09-22) is removing the whole field from this screen in a parallel
branch, since Johan ruled the no-approval threshold belongs on the PROPERTY, not the lease. Restyling
it here would just be redone/conflicted the moment cc5 lands; only the helper text — pure prose, no
functional relationship to cc5's removal — was touched.

**Mobile (390px)**: every one of this commit's own fields (`col-span-2` below the `sm:` breakpoint)
stacks full-width in the same top-to-bottom order; the untouched no-approval threshold and (when shown)
Lease type were already unconditional full-width `col-span-2` and are unaffected either way. The button
row uses `flex flex-wrap`, so at narrow widths the trailing destructive group (`ml-auto` pushes it to
the row's end on wide screens) wraps onto its own line below Save/Cancel/Activate rather than
overflowing horizontally — still visually distinct by colour, just stacked instead of side-by-side.
Verified live with a REAL headless browser (Puppeteer against system Chromium — the same tool
`scripts/rental-smoke.mjs` already uses) driving a genuine click on the real Edit button, not a static
HTML read — see the root-cause note above for why the static-only check this was first verified with
missed the real bug entirely.

Verify: a real headless-browser session (real Alpine JS execution, a real click on Edit, `getBoundingClientRect()`
read back from the live DOM — not a static HTML/CSS read, which is what let the container-level
`grid-cols-1` version ship past a first verification pass undetected) against an isolated local database
(local, not the deployed QA1 URL) at desktop (1280px) and 390px viewport widths, both with zero console
errors: Monthly rental (read-only) and Deposit sit on the same row with equal, non-overlapping boxes at
desktop, and stack full-width with zero overlap at 390px; End date and Month-to-month share a row at
desktop; Save changes/Cancel/Cancel lease render on one row at desktop with Cancel lease visually
separated; the removed helper text is gone from the rendered HTML; the untouched field (name, type,
value, placeholder, classes) is byte-identical to before this commit. `scripts/verify-alpine-render.mjs`
run against a static fetch of the same page: zero errors. Existing test suite
(`LeaseTypeVisibilityTest`/`LeaseEditTest`/`LeaseCoreTest`/`LeaseSettingsTest`/`LeaseTypeSettingTest`)
re-run as a regression check — all still passing, no changes needed to any test since none of them
assert exact DOM position, only presence/absence of text and values.
