# HR menu — sidebar navigation reorg

**Status:** Built, 2026-10-05 (Johan). Navigation/menu config only — no routes,
controllers, views, migrations, or permissions were added or changed.

---

## 1. What changed and why

Johan asked for a new top-level **"HR"** menu, combining two things that
previously lived in unrelated places in the sidebar:

- **Payroll** — previously its own top-level slide-panel group under the
  "Branch Manager" section (`resources/views/layouts/corex-sidebar.blade.php`,
  Alpine group key `payroll`). Moved one level deeper: **HR → Payroll**, with
  every existing item (Employees, Earning Types, Deduction Types, Runs)
  nested a further level beneath that. No URL, route name, or permission
  changed — this is a pure navigation move.
- **PPRA FFC Employment Letter** (the admin register,
  `admin.ppra-employment-letters.*`) — previously a flat, root-level item in
  the "Admin" section. Moved to **HR → Documents → "PPRA FFC Letter"**. Same
  route, same permission (`ppra_employment_letters.view`), same controller/
  views (`App\Http\Controllers\...\PpraEmploymentLetterController` and its
  admin register views) — entirely untouched.

**Not moved, explicitly out of scope:** "Leave Management" stays exactly
where it was (its own top-level group under "Branch Manager", a sibling of
where Payroll used to be) — the task named only Payroll, not Leave. "PPRA
Inspection Pack" stays in the Admin section — the task named only the
Employment Letter register.

**Documents is deliberately a two-item-wide placeholder today.** Only
"PPRA FFC Letter" is linked. "Employment Contract" and "Disclosure Letter"
are coming (Johan is sending those documents) — no dead links are created
for them now; the Documents group is structured so they slot in as sibling
`<a>` tags inside the same panel once their own routes/controllers exist.

---

## 2. Visibility — HR is independent of the old section gates

The pre-existing section wrappers (`@permission('sidebar.section.branch_manager')`
around Payroll/Leave, `@permission('sidebar.section.admin')` around the PPRA
register) each gate an entire SECTION, not an individual item. HR spans
users who previously qualified for either section but not necessarily both —
a branch manager with payroll permissions but no admin-section flag, or a
pure admin with `ppra_employment_letters.view` but no branch-manager-section
flag. Nesting HR inside either pre-existing wrapper would have hidden it from
one of those two audiences.

**Fix:** the HR block is NOT wrapped in any `sidebar.section.*` permission —
it renders standalone, gated only by the same dynamic
`$user->hasAnyPermission([...])` pattern Payroll/Leave already use for
themselves:

```php
hasAnyPermission(['manage_payroll', 'run_payroll', 'view_payroll_reports', 'ppra_employment_letters.view'])
```

Each item inside still keeps its own, narrower permission check exactly as
before (`manage_payroll`/`run_payroll` on the Payroll items,
`ppra_employment_letters.view` on the Documents item) — HR's own gate is
only "can this user see at least one thing inside," never a new permission
key of its own.

Agents keep their existing **My Portal** self-service PPRA letter flow
(`ppra-employment-letters.index`/`.show`/`.download`/etc., a completely
different route family under `/my-portal/ppra-employment-letters` —
cc2's concurrent PPRA screens work) — untouched by this change, and not
linked from this HR menu at all (HR → Documents is the ADMIN register of
every agent's letter, not an agent's own copy of theirs).

---

## 3. Three-level nesting — the mechanism, and what it took to prove it works

The sidebar's nav panels are a generic Alpine push/pop STACK
(`resources/views/layouts/corex-sidebar.blade.php`, the root `x-data` block):

```js
groupParents: @js($navGroupParents),
stack: @js($activeChain),
chain(g) { const out = []; for (let c = g; c; c = this.groupParents[c] || null) out.unshift(c); return out },
push(g) { if (this.stack[this.stack.length - 1] !== g) this.stack.push(g) },
pop() { this.stack.pop() },
inStack(g) { return this.stack.includes(g) },
```

`$navGroupParents` (PHP, server-rendered into the above) maps a child group
key to its parent's key; `$groupOpen($g)` (`in_array($g, $activeChain, true)`)
is true for a group OR any of its active descendants, walking that same
parent chain server-side so the correct panel is already open on first paint
(no flash-of-closed-panel before Alpine hydrates). **This mechanism already
supported arbitrary depth before this change** — `chain()`/`$activeChain`
walk the FULL parent chain, not just one level — but the only two things
that had ever used it (`'evaluation' => 'hidden'`, and a since-retired
`'rentals' => 'hidden'`, see `git show 8753f0abd`) were dead or removed, so
nothing had ever actually exercised 3 real, live levels before. The retired
`Rentals` block's own markup (recovered from `git show 8753f0abd`) is the
proven template this build copied:

```html
<div>
    <button type="button" @click="push('<key>')"
            class="corex-nav-subitem corex-nav-group-toggle corex-nav-subgroup-toggle {{ $groupOpen('<key>') ? 'active' : '' }}">
        <span>Label</span>
        <svg class="corex-chevron">...</svg>
    </button>
    <div class="corex-nav-panel {{ $groupOpen('<key>') ? 'is-open' : '' }}" :class="{ 'is-open': inStack('<key>') }">
        <button type="button" @click="pop()" class="corex-nav-back">...Back...</button>
        <div class="corex-nav-panel-title">Label</div>
        <!-- items -->
    </div>
</div>
```

placed as a child of its PARENT panel's own content — the CSS
(`.corex-nav-panel .corex-nav-subgroup-toggle`, `resources/css/corex.css`)
already existed for exactly this ("Nested drill-down inside a panel... reads
as a sub-item; behaves as a group toggle") and needed no change.

**`$navGroupParents` additions** (one map, both new keys):

```php
'payroll' => 'hr',
'hr-documents' => 'hr',
```

**New `$activeGroup` branch** — the PPRA register's route never needed one
before (it was a root item; landing on it needed no panel to auto-open).
Now that it lives inside a panel, skipping this would reproduce the exact
"jumps away" bug class this resolver chain exists to prevent:

```php
} elseif (request()->routeIs('admin.ppra-employment-letters.*')) {
    $activeGroup = 'hr-documents';
}
```

Payroll's own existing branch (`routeIs('payroll.*') → $activeGroup = 'payroll'`)
needed no change — Payroll is still the leaf; only its PARENT registration
changed.

---

## 4. A real bug this build found and fixed in `SidebarNavAuditor`

`app/Support/Navigation/SidebarNavAuditor::groupAtLine()` cross-references
every authenticated route against the sidebar link that opens it. Before
this fix, it picked the FIRST panel in scan order whose line-range contained
a given link — correct when panels never nest, but wrong the moment one
does: a NESTED panel's range (e.g. `payroll`) is fully CONTAINED inside its
parent's range (`hr`), both discovered in ascending-line order with the
parent found first, so every Payroll link would have been misattributed to
`group: 'hr'` instead of `'payroll'` — which would then disagree with
`resolveActiveGroup('payroll.employees.index') === 'payroll'` and fail the
whole suite with spurious `WRONG_GROUP` flags on every single Payroll route.

Fixed to resolve the panel with the SMALLEST (innermost) span containing the
line — the standard "nearest enclosing scope" rule — rather than first-match.
This is the auditor's own, generic fix (not a per-route workaround), proven
by `tests/Feature/Navigation/SidebarNavMappingTest.php::
test_hr_menu_moved_routes_resolve_to_their_own_nested_group_not_the_parent()`,
which asserts every moved Payroll/PPRA route's sidebar link is attributed to
its OWN group, not an outer parent.

---

## 5. A pre-existing gap found, NOT fixed (outside this task's scope)

Running the sidebar mapping test against the current `origin/QA1` tip (cc2's
concurrent PPRA screens work already merged in) surfaced three routes with
no sidebar link at all: `ppra-employment-letters.index`/`.show`/`.download`
— the AGENT-FACING My Portal self-service flow
(`App\Http\Controllers\Compliance\PpraEmploymentLetterController`, routes
under `/my-portal/ppra-employment-letters`). This is unrelated to and
unaffected by this change (nothing here touches that route family at all)
— it predates this build and belongs to cc2's own in-flight work. Recorded
as an allow-listed, reasoned exception in
`tests/Feature/Navigation/fixtures/sidebar-nav-allowlist.json` rather than
silently fixed (non-negotiable #2 — report, don't fix, outside scope) or
silently ignored (the allow-list mechanism exists precisely so a gap like
this is visible and attributed, not swept away).

---

## 6. Files touched

- `resources/views/layouts/corex-sidebar.blade.php` — moved Payroll +
  PPRA Employment Letters link; added the HR block; added the
  `admin.ppra-employment-letters.*` → `hr-documents` `$activeGroup` branch;
  added `'payroll' => 'hr'` / `'hr-documents' => 'hr'` to `$navGroupParents`.
- `app/Support/Navigation/SidebarNavAuditor.php` — `groupAtLine()` fixed to
  resolve the innermost enclosing panel, not the first match.
- `tests/Feature/Navigation/SidebarNavMappingTest.php` — new test pinning
  the moved routes' correct (non-parent) group attribution.
- `tests/Feature/Navigation/fixtures/sidebar-nav-allowlist.json` — three new
  entries for the pre-existing, unrelated agent-facing PPRA gap (§5).
- `.ai/specs/hr-menu.md` — this document.

No migrations, no new permission keys, no controller/view changes.
