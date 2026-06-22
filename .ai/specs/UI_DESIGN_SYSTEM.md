# CoreX OS — UI Design System

> **The single source of truth for ALL CoreX OS UI. Every page, every module, every component must conform to this file.**
> Last updated: 2026-04-20
> Supersedes: any conflicting rules in `.ai/DESIGN-SYSTEM.md`, `Claude_UX_Standards.md`, ad-hoc inline patterns in views.

---

## How to read this spec

This is a **definitive** document, not a suggestion. If a page contradicts it, the page is wrong — fix the page. If this spec is wrong, update the spec first (commit to `main`), then fix the pages.

- **MUST / MUST NOT** — non-negotiable
- **SHOULD** — default; deviate only with a good, documented reason
- **MAY** — allowed alternatives

Before creating any new UI:
1. Read §4 (Blade Component Inventory). If a component exists, use it.
2. Read §3 (Component Standards). Use the codified pattern.
3. Read §1 (Design Tokens). Never hardcode a colour, radius, or font size that a token covers.
4. Read §5 (Strict Rules). Do not ship anything on that list.

---

## 1. DESIGN TOKENS

All tokens live as CSS custom properties defined in `resources/css/corex.css`. Brand colours are injected per-agency at runtime via `layouts/corex.blade.php` `<style>` block. You MUST use tokens — never hardcode hex for anything a token covers.

### 1.1 Background layers

| Token | Light | Dark | Use |
|---|---|---|---|
| `--bg` | `#f4f6fb` | `#0d0f14` | App background (body, main content) |
| `--surface` | `#ffffff` | `#13161d` | Card, table, filter bar, modal body |
| `--surface-2` | `#f0f2f8` | `#1a1e28` | Hover row, nested card, table header, input bg |
| `--brand-sidebar` | agency | agency | Sidebar nav hover/active highlight (tinted via `color-mix`) |

Sidebar background itself uses `--surface` — it is **not** agency-branded. Only hover/active states are.

### 1.2 Border colours

| Token | Light | Dark | Use |
|---|---|---|---|
| `--border` | `rgba(0,0,0,0.07)` | `rgba(255,255,255,0.06)` | Default border on all surfaces |
| `--border-hover` | `rgba(0,0,0,0.14)` | `rgba(255,255,255,0.12)` | Hover state for interactive borders |
| Focus border | `var(--brand-button)` | `var(--brand-button)` | Input/button focus ring base colour |
| Danger border | `#dc2626` | `#dc2626` | Destructive actions only |

### 1.3 Text

| Token | Light | Dark | Use |
|---|---|---|---|
| `--text-primary` | `#111827` | `#eef0f5` | Headings, body, table cells |
| `--text-secondary` | `#4b5563` | `#8890a4` | Subtitles, helper text |
| `--text-muted` | `#9ca3af` | `#545b6e` | Timestamps, metadata, disabled labels |
| Inverse | `#ffffff` | `#ffffff` | Text on branded/solid backgrounds (page header, buttons) |

### 1.4 Agency brand colours (runtime-injected)

| Token | Default | Use |
|---|---|---|
| `--brand-sidebar` | `#0ea5e9` | Sidebar hover/active tint only |
| `--brand-icon` | `#0ea5e9` | Icons, inline links, small accents |
| `--brand-default` | `#0b2a4a` | Page header backgrounds, profile blocks, banded surfaces |
| `--brand-button` | `#0ea5e9` | Primary buttons, input focus, CTAs |

#### How the brand vars resolve at runtime

Three layers, in cascade order:

1. **Safe defaults** — `resources/css/corex.css` `:root` defines every `--brand-*` token to the navy / sky-blue values above. Loaded by `@vite` on every page that uses CoreX layouts. These are the FALLBACK so the surface NEVER renders white-on-white when the per-agency injection is absent.
2. **Per-agency override** — `layouts/corex.blade.php` AND `layouts/corex-app.blade.php` BOTH emit a `<style id="agency-brand">` block in `<head>` that re-declares each token with the agency's actual colour and `!important`. The `!important` wins over the `:root` defaults. Gated by `@auth + agency exists`.
3. **Inline-style fallback** — when an inline `style` attribute references a brand var, the safer pattern is to include a per-property fallback: `style="background: var(--brand-default, #0b2a4a); color: #fff"`. Belt-and-braces against any future CSS-load-order surprise.

The "white-on-light" contrast bug surfaces when:
- The agency injection is missing (auth-less pages, error pages, layouts that don't carry the inject), AND
- The `:root` defaults are missing OR overridden, AND
- An inline style uses `background: var(--brand-default); color: #fff` without its own fallback.

That combination resolves the background to `transparent` (the initial value), leaving white text on the page's white body — invisible. The fix that landed (commit referenced below) closes all three holes: layered defaults + agency inject on both layouts + sweep of high-traffic inline styles to add per-property fallbacks.

### 1.5 Semantic colours

| Role | Token | Hex | Use |
|---|---|---|---|
| Success | `--ds-green` | `#059669` | "Granted", "Paid", "On track", positive trends |
| Warning | `--ds-amber` | `#f59e0b` | "Pending", "Needs attention", near-deadline |
| Outreach (orange) | `--ds-orange` | `#ea580c` | Outreach-consent suppression/awaiting states — "Awaiting reply" (consent sent, unanswered) and "Marketing opted out" (explicit decline). Distinct from amber (`--ds-amber` = silence-lapse "No response") so a sent-but-unanswered pitch or an explicit opt-out never reads as the same shade as a passive lapse. Badge class: `.ds-badge-orange`. |
| Danger | `--ds-crimson` | `#c41e3a` | "Declined", "Loss", destructive confirmations only |
| Info | `--ds-navy` | `#0b2a4a` | "Registered", neutral informational badges |

**Never use red** for neutral scores, low values, or non-danger metrics. Use amber for "needs attention" and teal/brand for neutral.

### 1.6 Typography

- **Font family (UI):** Figtree (loaded from Bunny CDN, weights 400/500/600/700/800). Fallback: system sans.
- **Font family (mono):** JetBrains Mono, system mono fallback. Use for IDs, codes, monetary amounts in tables where alignment matters.
- **Never** hardcode `font-family: 'Plus Jakarta Sans'` in inline styles. The legacy usage in `x-page-header` is a bug to be migrated.

**Size scale (rem):**

| Token | Value | Tailwind | Use |
|---|---|---|---|
| `xs` | 0.6875rem (11px) | `text-[0.6875rem]` | Badges, labels, micro-copy |
| `sm` | 0.75rem (12px) | `text-xs` | Meta, helper text, pills |
| `base-sm` | 0.8125rem (13px) | `text-[13px]` | Body text, table cells, nav items, inputs |
| `base` | 0.875rem (14px) | `text-sm` | Default body |
| `md` | 1rem (16px) | `text-base` | Standard form inputs, buttons |
| `lg` | 1.125rem (18px) | `text-lg` | Panel titles, card section headers |
| `xl` | 1.25rem (20px) | `text-xl` | Page title on branded header |
| `2xl` | 1.625rem (26px) | `text-[1.625rem]` | KPI values |
| `3xl` | 1.75rem (28px) | `text-[1.75rem]` | Hero stat `ds-value-xl` |

**Weight scale:**

| Weight | Tailwind | Use |
|---|---|---|
| 400 | `font-normal` | Default body, table cells |
| 500 | `font-medium` | Nav items, labels, links |
| 600 | `font-semibold` | Buttons, active nav, panel titles, page subtitles |
| 700 | `font-bold` | Page titles (`text-xl` on branded header) |

### 1.7 Spacing scale

Use the standard Tailwind scale (`0.25rem` increments). In CoreX patterns these values are load-bearing:

| Token | Tailwind | Use |
|---|---|---|
| `0.25rem` | `1` | Inline chip gap |
| `0.5rem` | `2` | Badge padding (`px-2`) |
| `0.75rem` | `3` | Card inner gap, section padding |
| `1rem` | `4` | Card padding, table cell x-padding |
| `1.25rem` | `5` | Panel padding |
| `1.5rem` | `6` | Section gap, page horizontal padding |
| `2rem` | `8` | Empty-state padding |
| `3rem` | `12` | Empty-state padding (large) |

### 1.8 Border radius

**CoreX standardises on `rounded-md` (6px) as the default.** Larger radii are reserved for specific components.

| Token | Tailwind | Value | Use |
|---|---|---|---|
| Standard | `rounded-md` | 6px | Buttons, inputs, cards, panels, tables, filter bar, badges (rectangular), page header, table containers |
| Small | `rounded` | 4px | Progress bars, micro-elements |
| Pill | `rounded-full` | 9999px | Status badges (`.ds-badge`), avatars, dots |
| Circle | `rounded-full` | 9999px | Avatars, notification dots |
| Large | `rounded-2xl` | 16px | Modals only (legacy pattern — `x-modal` uses `rounded-lg`) |

**MUST NOT** use `border-radius: 3px` (found in compliance module — migrate to `rounded-md`) or `rounded-3xl` anywhere.

### 1.9 Shadows / elevation

| Token | Value | Use |
|---|---|---|
| Card | `0 1px 3px rgba(0,0,0,0.06)` | Default card resting state (`.ds-status-card`) |
| Card hover | `0 2px 8px rgba(0,0,0,0.1)` | Card hover |
| Dropdown | `0 8px 24px rgba(0,0,0,0.4)` | Notification dropdown, user menu |
| Modal | `0 10px 30px rgba(0,0,0,0.18)` | Modal dialog |
| Primary button | `0 4px 12px color-mix(in srgb, var(--brand-button) 25%, transparent)` | Primary button resting |
| Primary button hover | `0 6px 16px color-mix(in srgb, var(--brand-button) 35%, transparent)` | Primary button hover |

Dashboard cards MAY use `shadow-sm hover:shadow`. Content cards in index/list pages **SHOULD NOT** use Tailwind shadow utilities — rely on `.ds-status-card` shadow or no shadow at all.

### 1.10 Transitions

- **Duration:** 300ms for standard UI (`transition: all 300ms`), 150ms for colour-only changes (nav hover, link colour).
- **Easing:** `ease` (default) or `ease-in-out`. `ease-linear` only for opacity fades.
- **MUST** include a transition on every hover-capable interactive element.

---

## 2. PAGE STRUCTURE STANDARD

Every authenticated page MUST follow this structure.

### 2.1 Layout file

```blade
@extends('layouts.corex-app')
@section('corex-content')
    {{-- page content --}}
@endsection
```

- Default layout: `layouts.corex-app` (includes sidebar + mobile toggle + Ellie + toast container).
- **MUST NOT** use `layouts.app`, `layouts.navigation`, or extend a bare HTML file for authenticated pages.
- Guest pages use `layouts.guest`. Onboarding uses `layouts.onboarding-portal`. TV uses `layouts.corex.blade.php` with TV-specific slots.

### 2.2 Sidebar

- **Width:** `240px` (`--corex-sidebar-width`). Fixed on desktop (`lg:`), drawer on mobile.
- **Background:** `var(--surface)` — theme-controlled, NOT agency-branded.
- **Hover/active highlight:** `color-mix(in srgb, var(--brand-sidebar) 12%, transparent)` (hover), `18%` (active). Text colour in hover/active state is `var(--brand-sidebar)`.
- **Nav item padding:** `0.5rem 0.75rem`, margin `0.125rem 0.625rem`, font `0.875rem / 400`.
- **Active item:** font-weight 600, tinted background, same colour text.
- **Sub-items (expanded groups):** indented via `.corex-nav-children` container, rendered with `.corex-nav-subitem` (smaller font, left-padded).
- **Section labels:** `.corex-nav-section-label` / `.corex-nav-sublabel` — uppercase, `0.6875rem`, slate-500, `letter-spacing: 0.06em`.
- **Collapse point:** `lg` (1024px). Below that, sidebar becomes an overlay drawer with `bg-black/50` backdrop.
- **User profile block:** pinned to bottom via `margin-top: auto`, border-top `rgba(255,255,255,0.08)`, avatar `2.25rem` circle.

### 2.3 Main content area

- **Left offset:** none — sidebar is flex sibling, main is `flex-1`.
- **Top padding:** `p-4` on mobile, `p-6` on `lg:`.
- **Max-width:** page content SHOULD wrap in `max-w-7xl mx-auto` unless the feature needs full-bleed (tables, dashboards with many panels).
- **Horizontal padding:** inherit from main `p-4`/`lg:p-6`; inner wrapper MAY add `px-4 sm:px-6 lg:px-8` for centred content.
- **Vertical rhythm:** `space-y-6` between major sections on a page.

### 2.4 Page header block

Two valid patterns. Pick one per page; never mix.

**Pattern A — Branded header (preferred for index/list pages):**

```blade
<div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Page Title</h1>
            <p class="text-sm text-white/60">Optional subtitle or context.</p>
        </div>
        <div class="flex items-center gap-2">
            {{-- right-aligned action buttons --}}
            <a href="..." class="corex-btn-primary">Primary Action</a>
        </div>
    </div>
</div>
```

**Pattern B — `<x-page-header>` component (preferred for detail/edit pages):**

```blade
<x-page-header title="Edit Contact" :back-route="route('contacts.index')">
    <x-slot:actions>
        <button class="corex-btn-primary">Save</button>
    </x-slot:actions>
</x-page-header>
```

Rules for both:
- Title: `text-xl font-bold` (Pattern A on white text; Pattern B on `--text-primary`).
- Subtitle: `text-sm`, colour `text-white/60` (Pattern A) or `var(--text-muted)` (Pattern B).
- **MUST** have a single primary action on the right for list/index pages where creation is possible. Primary action uses `corex-btn-primary`.
- Secondary/tertiary actions use `corex-btn-outline` or inline text links.
- **MUST NOT** render an oversized empty header that contains only a single number (anti-pattern — see §5).
- Page header sits **above** the filter bar and content — never interleaved.

### 2.5 Content body

- Gap from header to first content section: `mt-4` or `space-y-6` on outer wrapper.
- Between content sections (filter bar → content, content → footer): `space-y-4` to `space-y-6`.
- Cards within a section: `gap-4` (grid) or `space-y-3` (stacked).

### 2.6 Footer area

- Pages generally have no footer. Action-heavy pages (create/edit forms) use `<x-sticky-action-bar>` pinned to the bottom.
- Sticky footer bar: `sticky bottom-0 z-30`, `bg: var(--surface)`, `border-top: 1px solid var(--border)`, shadow-sm, `py-3 px-4 lg:px-6`.

---

## 3. COMPONENT STANDARDS

### 3.1 Page header (title + subtitle + action button)

**Purpose:** Identify the page and expose top-level actions.
**Markup:** See §2.4 Pattern A / Pattern B.
**Required props (Pattern B):** `title`.
**Variants:** branded (Pattern A, index pages), plain (Pattern B, detail/edit pages), flush (no negative margins — `flush=true`).
**Never:** put KPI tiles inside the header block; use a separate stat grid below. Never make the title a clickable link. Never omit a subtitle without a good reason (context matters).

### 3.2 Stat / KPI tile

**Purpose:** Display a single metric with optional trend and icon.
**Use component:** `<x-corex-kpi-card>`.

```blade
<div class="corex-kpi-grid">
    <x-corex-kpi-card title="Total Deals" value="R 12,500,000" :trend="8" :trend-up="true" />
    <x-corex-kpi-card title="Active Listings" value="142" />
</div>
```

- **Grid:** `.corex-kpi-grid` auto-responsive (4 → 2 → 1 cols at 1200px / 640px).
- **Value:** `1.625rem` (26px), weight 600.
- **Trend:** `up` green `#059669`, `down` crimson `#dc2626`. **Never invert** (down is not always bad — do not abuse colour).
- **Icon:** optional, rounded square `2.5rem` square, tinted background.
- **Never:** render a floating-point KPI without formatting (`number_format()` or `formatZar()`). Never render "NaN" or "undefined" — use `—` as the placeholder for missing data.

### 3.3 Card (generic)

**Purpose:** Containing surface for grouped content.

```blade
<div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
    {{-- content --}}
</div>
```

- `rounded-md`, `p-4` (or `p-5` for larger panels), `bg: var(--surface)`, `border: 1px solid var(--border)`.
- Optional: `.ds-status-card` class for cards that communicate a status via coloured left border.
- **Never:** mix `rounded-lg` or `rounded-2xl` with other cards on the same page. Never use inline `box-shadow` — use the shadow tokens.

### 3.4 Badge / tag

**Purpose:** Communicate status, category, or count.
**Use classes:** `.ds-badge` + one variant.

```blade
<span class="ds-badge ds-badge-success">Active</span>
<span class="ds-badge ds-badge-warning">Pending</span>
<span class="ds-badge ds-badge-danger">Declined</span>
<span class="ds-badge ds-badge-info">Registered</span>
<span class="ds-badge ds-badge-default">—</span>
```

- Always pill-shaped (`border-radius: 9999px`), `text-xs uppercase font-semibold`.
- **MUST** have `white-space: nowrap` — add it to `.ds-badge` in `corex.css` if not already present. A badge that wraps is broken.
- **MUST NOT** exceed 2 words or 20 characters. If it does, it's a label, not a badge.
- Colour mapping: success=green, warning=amber, danger=crimson, info=navy/brand, default=grey. **Red (danger)** reserved for destructive/fatal states only — never for low values or neutral metrics.
- Tinted variants (for category tags, non-status): use `color-mix(in srgb, var(--brand-icon) 12%, transparent)` background with `var(--brand-icon)` text, not the full `.ds-badge` classes.

### 3.5 Button

**Purpose:** Trigger an action.
**Classes:** Use the defined utilities — do **not** use `<x-primary-button>` / `<x-secondary-button>` / `<x-danger-button>` (they exist only for legacy auth screens and use hardcoded Tailwind that doesn't respect brand tokens).

| Variant | Class | Use |
|---|---|---|
| Primary | `corex-btn-primary` | Main CTA, one per section |
| Outline | `corex-btn-outline` | Secondary actions, "Cancel", "Back" |
| Icon | `corex-btn-icon` | Toolbar icons (bell, theme toggle) |
| Danger | `corex-btn-primary` with `style="background:#dc2626"` or a dedicated class (add `corex-btn-danger` to `corex.css` if missing) | Destructive confirm only |

- Sizes via padding only: default `px-3.5 py-1.5` (text-sm), large `px-5 py-2.5` (text-base).
- **Disabled state:** `disabled:opacity-40 disabled:cursor-not-allowed`.
- **Loading state:** swap text for a spinner via Alpine `x-show`; button stays disabled while loading.
- **Never:** use raw Tailwind `bg-blue-600` — that doesn't respect agency branding. Always use tokens via the `corex-btn-*` classes.

### 3.6 Form input / select / textarea

**Purpose:** Capture user input.

**Standard input:**

```blade
<label for="field" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Label</label>
<input id="field" name="field" type="text"
       class="w-full rounded-md px-3 py-2 text-sm"
       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
<p class="mt-1 text-xs" style="color: var(--text-muted);">Helper text.</p>
```

- **Label position:** above the input, `text-xs font-medium`.
- **Focus state:** `border-color: var(--brand-button)`, `box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent)`.
- **Error state:** `border-color: #dc2626`, below input render `<x-input-error :messages="$errors->get('field')" />`.
- **Required field marker:** `<span class="text-red-500">*</span>` after label text.
- **Select dropdown:** same classes + `.list-header-filter` class when inside a filter bar (applies standardised height 2.25rem).
- **Textarea:** same pattern, `rows="4"` default.
- **Never:** use bare `<input>` without a label (except search inputs with a visible icon + placeholder).

### 3.7 Table

**Purpose:** Display structured tabular data.

```blade
<div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm ds-table">
            <thead>
                <tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider"
                        style="color: var(--text-muted);">Column</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider"
                        style="color: var(--text-muted);">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                        onmouseover="this.style.background='var(--surface-2)'"
                        onmouseout="this.style.background=''">
                        <td class="px-4 py-3">{{ $row->name }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="..." class="text-xs font-semibold" style="color: var(--brand-icon);">Edit</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="px-4 py-12 text-center text-sm" style="color: var(--text-muted);">
                            No records yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```

- **Header:** `.ds-table thead th` styling handles background, uppercase, letter-spacing.
- **Rows:** even-row zebra (`tbody tr:nth-child(even) { background: #f8fafc }`) via `.ds-table` — acceptable.
- **Hover:** tinted `var(--surface-2)`.
- **Action column:** right-aligned, use `<x-sort-header>` if sortable.
- **Empty state (inline):** a single row with `colspan` equal to column count, `py-12 text-center`, muted colour, plain-text message.
- **Sortable columns:** use `<x-sort-header field="name" label="Name" :current-sort="$sort" :current-direction="$direction" />`.
- **Never:** use `<table>` without the container div — unwrapped tables have no border radius and overflow badly on mobile.

### 3.8 Filter bar

**Purpose:** Search + refine list views.
**Preferred:** `<x-list-header>` component. Inline variant only when that component doesn't fit.

```blade
<x-list-header
    title="Contacts"
    :form-action="route('contacts.index')"
    :count="$contacts->count()"
    :total="$totalContacts"
    search-placeholder="Search name, email, phone...">
    <x-slot:filters>
        <select name="type" onchange="this.form.submit()" class="list-header-filter">
            <option value="">All types</option>
            <option value="buyer">Buyer</option>
        </select>
    </x-slot:filters>
    <x-slot:actions>
        <a href="{{ route('contacts.create') }}" class="corex-btn-primary">New Contact</a>
    </x-slot:actions>
</x-list-header>
```

- **Layout:** flex row, wrap on mobile. Search grows (`flex-1`), filters fixed width.
- **Result count:** visible near the title — "Showing 42 of 128".
- **All filters** submit the form on change (`onchange="this.form.submit()"`).
- **Search input:** `<input type="text">` with leading search icon SVG.
- **Clear filter:** if any filter is active, show a "Clear" link to reset.
- **Never:** build a second search input on the same page. Never hide the result count.

### 3.9 Alert / notice block

**Purpose:** Page-level message (not toast).

```blade
<div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
     style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
            border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
            color: var(--text-primary);">
    <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber);">...</svg>
    <div class="flex-1">
        <strong>Heads up.</strong> This is an informational message.
    </div>
    <button type="button" class="text-xs font-semibold" style="color: var(--ds-amber);">Action</button>
</div>
```

Colour mapping (background tint 10%, border tint 30%):

| Variant | Tint colour | Use |
|---|---|---|
| Info | `var(--brand-icon)` | General info |
| Success | `var(--ds-green)` | Confirmation after action |
| Warning | `var(--ds-amber)` | Needs attention, near-deadline |
| Danger | `var(--ds-crimson)` | Error, blocked state |

### 3.10 Empty state

**Purpose:** Communicate "no data yet" and guide the user to first action.

```blade
<div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
         style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
        <svg class="w-6 h-6">...</svg>
    </div>
    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No contacts yet</h3>
    <p class="text-sm mb-4" style="color: var(--text-muted);">Add your first contact to start tracking relationships.</p>
    <a href="{{ route('contacts.create') }}" class="corex-btn-primary">Add Contact</a>
</div>
```

- **MUST** have: icon, heading, body text, CTA (unless the user lacks permission to create).
- **Vertical padding:** `py-12` consistently. `py-8` acceptable only inside a narrow panel.
- **Never:** render a blank white space where content should be. Never say "Nothing to show" without a next-step CTA.

### 3.11 Modal

**Purpose:** Focused interaction without navigating away.
**Use component:** `<x-modal name="contact-edit" max-width="lg">`.

- **Overlay:** `bg-black/50`, z-40.
- **Dialog:** `rounded-md` (override `x-modal`'s `rounded-lg` default via class merge), `bg: var(--surface)`, `p-6`, max-width from prop.
- **Header:** `text-lg font-semibold` title + close button (×) top-right.
- **Body:** `space-y-3` or a form grid.
- **Footer:** right-aligned action row with Cancel (outline) + Confirm (primary).
- **Close behaviour:** click outside (overlay), press Esc, click ×, or submit form (all close).
- **Focus trap:** enforced by `x-modal` component — do not roll your own.
- **Never:** nest modals. Never use a modal where a new page would be more appropriate (rule of thumb: if the form has >6 fields, it's a page).

### 3.12 Sidebar nav item

**Purpose:** Entry to a module or page.
**Classes:** `.corex-nav-item` (top-level), `.corex-nav-subitem` (child of expandable group).

- **Icon:** `1.25rem` square, on the left.
- **Label:** truncates at `ellipsis`, flex-grows.
- **Chevron:** right-aligned, rotates 90° when group is open.
- **Active state:** tinted background, brand colour text, `font-semibold`.
- **Attention indicator:** small coloured dot on the right (`w-2 h-2 rounded-full bg-amber-500`) when the item needs user attention (e.g. incomplete training).
- **Sub-items:** use `.corex-nav-children` container + `.corex-nav-subitem`. Labels within groups use `.corex-nav-sublabel`.
- **Never:** create a top-level nav item without a route. Never omit the icon. Never build a three-level nesting — flatten or split the module.

### 3.13 Progress bar / ring

**Purpose:** Show completion or capacity.
**Classes:** `.ds-progress-track` (container) + `.ds-progress-bar` + colour variant.

```blade
<div class="ds-progress-track">
    <div class="ds-progress-bar ds-bar-navy" style="width: 42%"></div>
</div>
```

Colour variants: `.ds-bar-navy`, `.ds-bar-amber`, `.ds-bar-crimson`, `.ds-bar-green`.

- **MUST NOT** use red for a neutral score. A 40% completion bar is navy or amber, not red. Red is reserved for "failed" / "over target in a bad way" / explicit danger.
- Height: `8px` default (`.ds-progress-track`). Do not use heights outside `{4px, 8px, 12px}`.

### 3.14 Avatar / user chip

- **Avatar:** `rounded-full`, `2.25rem` square in sidebar, `2rem` in tables/lists.
- Initials fallback: two uppercase initials from the user's name, colour `#fff` on `var(--brand-icon)` background. Use `User::profilePhotoUrl()` helper — don't recompute initials in views.
- **User chip (inline mention):** avatar + name, `gap-2`, name `text-sm font-medium`.

### 3.15 Dropdown menu

**Use component:** `<x-dropdown align="right" width="48">`.

- **Trigger slot:** icon button or text.
- **Content slot:** series of `<x-dropdown-link>` or `<button>` entries.
- **Positioning:** `align="right"` for user menu / row actions, `align="left"` for filter/selectors that open left-to-right.
- **Max height:** `max-h-72 overflow-y-auto` when list is long (e.g. agent picker).
- **Dark mode:** the default `<x-dropdown>` hardcodes `bg-white text-gray-800`. Override via `content-classes` prop for dark-aware variants: `content-classes="py-1"` (tokens) and style the inner wrapper with `background: var(--surface)`.

### 3.16 Pagination

Rendered from Laravel paginator: `{{ $items->links() }}`. Ensure the paginator view uses Tailwind (`php artisan vendor:publish --tag=laravel-pagination` with tailwind variant). Default Blade `simple-tailwind` paginator is acceptable.

- Place pagination **below** the table, within the same card border (inside the outer `<div>`), separated by `border-top: 1px solid var(--border)`.
- **Never:** render pagination above the table. Never omit it when results exceed one page.

### 3.17 Breadcrumb

Breadcrumbs are OPTIONAL. When used, place them immediately above the page header.

```blade
<nav class="text-xs mb-3" style="color: var(--text-muted);">
    <a href="{{ route('contacts.index') }}" style="color: var(--brand-icon);">Contacts</a>
    <span class="mx-1">/</span>
    <span>{{ $contact->name }}</span>
</nav>
```

- Never more than 3 levels. Current page is the last, unlinked.

### 3.18 Toast / flash notification

**Use component:** `<x-toast-notifications />` is already included in `layouts.corex-app` via the main layout. Trigger toasts from anywhere via:

```js
window.showToast('Saved successfully', 'success');
window.showToast('Something went wrong', 'error');
```

Or set Laravel session flash — picked up automatically on page load:

```php
return redirect()->back()->with('success', 'Contact updated.');
```

- **Types:** `success` (green), `error` (red), `info` (blue), `warning` (amber).
- **Duration:** 4 seconds auto-dismiss; error toasts 6 seconds.
- **Position:** fixed top-right.
- **Never:** use browser `alert()` for user feedback. Never silently succeed — always toast on save.

---

## 4. BLADE COMPONENT INVENTORY

All components live in `resources/views/components/`. Invoke via `<x-{name}>`.

| Component | File | Props | Renders | Notes / Known Issues |
|---|---|---|---|---|
| `x-application-logo` | `application-logo.blade.php` | `$attributes` | SVG logo | Legacy (auth screens only). Prefer text "CoreX Os" in authenticated layouts. |
| `x-auth-session-status` | `auth-session-status.blade.php` | `status` | Success message div | Hardcoded `text-green-600`. Auth-only. |
| `x-corex-document` | `corex-document.blade.php` | `title`, `subtitle`, `reference`, `date`, `parties[]`, `showHeader`, `showFooter`, `pageNumber`, `totalPages` | Print-optimised document wrapper with agency header/footer | DocuPerfect / generated PDFs only. Uses `--agency-accent` CSS var. |
| `x-corex-kpi-card` | `corex-kpi-card.blade.php` | `title`, `value`, `trend=0`, `trendUp=true`, `iconBg` | KPI tile with value, trend arrow, optional icon slot | `iconBg` default mixes hardcoded hex into Tailwind — migrate to tokens. |
| `x-danger-button` | `danger-button.blade.php` | `type=submit`, `class` | Red button | Legacy auth pattern; DO NOT use in CoreX pages — use `corex-btn-primary` with danger styling instead. |
| `x-dropdown-link` | `dropdown-link.blade.php` | `$attributes` | `<a>` with hardcoded dark text | Hardcoded `color:#111827!important;` — known bug. Use sparingly; inside `<x-dropdown>`. |
| `x-dropdown` | `dropdown.blade.php` | `align=right`, `width=48`, `contentClasses` | Alpine-powered dropdown with trigger + content slots | Uses deprecated `ring-opacity-5`. Default `bg-white text-gray-800` doesn't respect dark mode — override via `content-classes`. |
| `x-input-error` | `input-error.blade.php` | `messages` | Error `<ul>` | Hardcoded `text-red-600`. Use for validation errors. |
| `x-input-label` | `input-label.blade.php` | `value` or slot | `<label>` | Hardcoded `text-gray-700` — doesn't respect dark mode. For CoreX forms, inline-style the label with `var(--text-secondary)` until this is migrated. |
| `x-list-header` | `list-header.blade.php` | `title`, `formAction`, `paginator`, `count`, `total`, `searchPlaceholder`, `searchName=search`, `searchModel=search`, `sticky=true`, `backRoute`, `backLabel=Back` | Sticky filter/search toolbar with count, title, search, filter slot, action slot | Primary list page header — USE THIS. Dual mode (server GET vs Alpine) is complex — read the source before extending. |
| `x-modal` | `modal.blade.php` | `name`, `show=false`, `maxWidth=2xl` | Alpine modal with focus trap | Overlay hardcoded `bg-gray-500 opacity-75`. Migrate to `bg-black/50`. |
| `x-nav-link` | `nav-link.blade.php` | `active=false` | Top-nav `<a>` (not sidebar) | Legacy `layouts.navigation` only. Not used in corex-sidebar. |
| `x-page-header` | `page-header.blade.php` | `title`, `backRoute`, `backLabel=Back`, `sticky=true`, `flush=false` | Sticky header with back button + title + actions slot | Uses negative margin pattern (`-mx-4 -mt-4 lg:-mx-6 lg:-mt-6`). Hardcoded Plus Jakarta Sans font in inline style — BUG, migrate to Figtree. |
| `x-primary-button` | `primary-button.blade.php` | `type=submit`, `class` | `bg-gray-800` button | Legacy auth; DO NOT use in CoreX pages. Use `corex-btn-primary`. |
| `x-responsive-nav-link` | `responsive-nav-link.blade.php` | `active=false` | Mobile nav link | Legacy. Not used in corex-sidebar mobile drawer. |
| `x-secondary-button` | `secondary-button.blade.php` | `type=button`, `class` | White outlined button | Legacy auth; DO NOT use. Use `corex-btn-outline`. |
| `x-sort-header` | `sort-header.blade.php` | `field`, `label`, `align=left`, `currentSort`, `currentDirection` | `<th>` with sort toggle link + arrow | Use in every sortable table. Hardcoded grey — should use tokens. |
| `x-sticky-action-bar` | `sticky-action-bar.blade.php` | slots: left/center/right | Sticky top bar with 3-section layout | Used on long forms (create/edit) for "Save / Cancel" pinned to top. Hardcoded `bg-white`. |
| `x-text-input` | `text-input.blade.php` | `disabled=false`, `$attributes` | `<input>` | Hardcoded `border-gray-300 focus:border-[#00b4d8]`. Use raw `<input>` with token styles in CoreX pages. |
| `x-toast-notifications` | `toast-notifications.blade.php` | none | Fixed toast container with `window.showToast()` API | Include once in root layout (already done). Triggers via JS or session flash. |
| `x-tv-link` | `tv-link.blade.php` | `tvCode` | TV code generator/display card | TV module only. |

### 4.1 Component usage rules

- **New pages MUST first check this inventory.** If a component exists that does 80% of what you need, extend it — don't duplicate markup.
- **When a component is flagged as "legacy / hardcoded colours"**, do not use it in new CoreX pages. Use the documented inline pattern with tokens instead.
- **When this spec changes a component's contract**, update both the component source and every view using it in the same commit. Never leave a page using the old API.

---

## 5. STRICT RULES — THINGS THAT MUST NEVER APPEAR ON ANY PAGE

These are audit failures. A page ships with **zero** of these. Not "we'll fix it later" — fix it before merge.

1. **Raw floating-point numbers rendered to users.** Always format:
   - Money → `R {{ number_format($value, 0) }}` or helper `formatZar($value)`.
   - Percentages → `{{ number_format($value, 1) }}%`.
   - Counts → `{{ number_format($count) }}`.
   - Never `{{ $deal->gross }}` unbaked.
2. **Badge or tag text wrapping to a second line.** `.ds-badge` MUST have `white-space: nowrap`. Badge content MUST be ≤2 words / ≤20 characters.
3. **Red colour used for neutral scores or non-danger metrics.** Low completion = amber or brand colour. Red = danger/destructive only.
4. **Warning or alert text rendered as a raw unstyled string.** All page-level messages go through the Alert block pattern (§3.9) or a toast.
5. **Oversized empty header blocks** containing only a single number or icon with no title. The branded header (§2.4 Pattern A) MUST have a text title.
6. **Broken or missing empty states.** A list page with zero records MUST render the empty state pattern (§3.10). Never a blank white region.
7. **Hardcoded inline styles** for anything a token or class covers. Exceptions (still allowed for now):
   - `style="background: var(--surface); border: 1px solid var(--border);"` — acceptable until we ship utility classes like `.surface-card`.
   - Dynamic values (e.g. `style="width: {{ $percent }}%"` on a progress bar) — acceptable.
   - **NOT acceptable:** `style="color: #0b2a4a"` (use `var(--brand-default)`), `style="border-radius: 3px"` (use `rounded-md`), `style="font-family: 'Plus Jakarta Sans'"` (use Figtree via CSS).
8. **Inconsistent spacing between equivalent elements on different pages.** Use the spacing scale (§1.7). Table padding is always `px-4 py-3`. Card padding is always `p-4` (default) or `p-5` (large panel).
9. **Test, placeholder, or debug data visible in any view.** No "Lorem ipsum", no `foo@test.com`, no "John Doe" unless it's a real seeded user.
10. **Raw hex codes in Blade.** Any hex that a token covers is a bug. Use `var(--brand-default, #0b2a4a)` with a fallback, not `#0b2a4a` alone.
11. **`onmouseover="..."` inline JavaScript for styling.** Use CSS `:hover` or Tailwind `hover:` classes. The row-hover inline-JS pattern (seen in rentals/deals) is acceptable only as a transitional fix — migrate to CSS.
12. **jQuery.** We use Alpine.js. If you think you need jQuery, rewrite in Alpine.
13. **Two primary buttons in the same header.** One primary CTA per section. Everything else is outline or text.

---

## 5.A COMMON PITFALLS — pattern bugs caught in audits

These are NOT one-off bugs. They recur. Read this section before writing any inline style or new component.

### 5.A.1 White text on `var(--brand-default)` without a per-property fallback

**Symptom:** Page header banner renders as white-on-white — heading, breadcrumb link, subtext all invisible.

**Cause:** `style="background: var(--brand-default); color: #fff"`. When `--brand-default` is unset (auth-less page, layout without the agency-brand inject), the background falls back to `transparent` and `#fff` text vanishes on the white page body.

**Rule:** Every inline reference to a `--brand-*` var that pairs with a contrasting text colour MUST include a per-property fallback hex. The triple-defence is in place (`:root` defaults in corex.css + agency inject in both layouts + per-property fallback) — but the inline fallback is the cheapest guarantee:

```html
<!-- ❌ Brittle — fails if both upstream layers ever drop -->
<div style="background: var(--brand-default); color: #fff">…</div>

<!-- ✅ Safe — works even with no CSS at all -->
<div style="background: var(--brand-default, #0b2a4a); color: #fff">…</div>
```

Same applies to `var(--brand-button, #0ea5e9)`, `var(--brand-icon, #0ea5e9)`, `var(--brand-sidebar, #0ea5e9)`.

### 5.A.2 `text-white` without a `dark:` companion class

`<span class="text-white">` on a card that uses `bg-white` in light mode (or `bg-slate-50`) is invisible. Either:
- Use a semantic colour: `text-text-primary` (resolves correctly in both modes), OR
- Pair it: `text-white dark:text-white` only when the container is dark-bg in BOTH modes.

### 5.A.3 Light grey text (`text-slate-100`/`text-gray-100`/`text-slate-200`) on non-dark backgrounds

Surfaces fine in dark mode, invisible in light mode. Pair with `dark:` variants or switch to `text-text-muted` (which already remaps per theme via `corex.css`).

### 5.A.4 Inline style on an element inside `[x-show=""]` / `[x-cloak]`

Inline styles persist when Alpine toggles visibility, but if the parent surface inherits `color: transparent` from an Alpine state transition class, the child text vanishes. Always provide colour on the element itself, not just the parent.

---

## 6. RESPONSIVE RULES

### 6.1 Breakpoints

Tailwind defaults:

| Name | Min width |
|---|---|
| `sm` | 640px |
| `md` | 768px |
| `lg` | 1024px — sidebar collapse point |
| `xl` | 1280px |
| `2xl` | 1536px |

### 6.2 Sidebar

- **Desktop (`lg:` and up):** fixed sidebar, `240px` wide, always visible.
- **Mobile (< `lg:`):** hidden by default. Top bar appears with hamburger button. Tapping hamburger slides the sidebar in from the left; `bg-black/50` overlay covers the rest. Tap overlay or any nav item to close.
- The mobile top bar shows: hamburger + "CoreX Os" wordmark. Nothing else.

### 6.3 Card grids

| Grid | `sm` | `md` | `lg` | `xl` |
|---|---|---|---|---|
| KPI (`.corex-kpi-grid`) | 1 col | 1 col | 2 cols (< 1200px) | 4 cols |
| Property/listing cards | 1 col | 2 cols | 3 cols | 4 cols |
| Contact cards | 1 col | 2 cols | 3 cols | 3 cols |
| Dashboard panels | 1 col | 1 col | 2 cols | 3 cols |

Use `grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4`.

### 6.4 Tables on small screens

- Tables MUST wrap in `<div class="overflow-x-auto">` so horizontal scroll kicks in on mobile.
- Tables with > 6 columns SHOULD provide a card-layout fallback below `md:` — render the same data as stacked `<div>` cards, one record per card.
- Never hide columns on mobile without a way to see that data (use a "View details" link to the detail page).

### 6.5 Page header stacking

- Pattern A (branded): `flex-col md:flex-row`. Title/subtitle stacks on top of actions on mobile.
- Pattern B (`x-page-header`): back button + title always on a single line; actions slot wraps below on mobile.
- KPI tiles stack 1-col on mobile. Never fit more than 2 KPIs per row below `md:`.

### 6.6 Forms

- Multi-column form grids collapse to 1-col below `md:`.
- Field labels stay above inputs at all breakpoints (never side-by-side labels — breaks too easily).
- Sticky action bars (`<x-sticky-action-bar>`) remain sticky on mobile. Buttons wrap if needed.

---

## 7. Dark mode

- Controlled by `html.dark` class on `<html>`. Toggle via `.corex-theme-toggle` in the header.
- Theme persists via `localStorage['corex-theme']` and synced to the backend via `PUT /profile/theme`.
- Every new component MUST work in both themes. Check by toggling during dev.
- Do **not** add dark-mode-specific markup (`dark:bg-...`). The token system handles it — `var(--surface)` resolves to the right colour automatically.

---

## 8. Acceptance criteria for new UI

Before any new page/feature is marked done:

- [ ] Uses `layouts.corex-app` (or documented alternative)
- [ ] Has a sidebar nav entry created same day
- [ ] Page header follows §2.4 Pattern A or B
- [ ] All buttons use `corex-btn-*` classes (no `x-primary-button` / `x-danger-button`)
- [ ] All colours use tokens (no hardcoded hex in Blade)
- [ ] Border-radius is `rounded-md` (or documented exception)
- [ ] Badges use `.ds-badge` classes, have `nowrap`, ≤20 chars
- [ ] Empty state renders when data is empty
- [ ] Pagination below every list/table
- [ ] Works in light AND dark theme
- [ ] Works at mobile (<`lg:`), tablet (`md:`), and desktop (`lg:`+)
- [ ] No raw floats, no "undefined", no debug data
- [ ] Loading states on async actions
- [ ] Confirmation dialog on destructive actions
- [ ] Soft delete, not hard delete
- [ ] `php artisan view:clear` + `scripts/dev-check.ps1` pass with zero new failures

---

## 9. Known gaps & follow-ups

Logged during the audit that produced this spec (2026-04-20). Each should become its own prompt/PR:

1. **`.ds-badge` missing `white-space: nowrap`** — add to `corex.css`. A badge that wraps is broken.
2. **`x-page-header` hardcodes Plus Jakarta Sans** in inline style — migrate to Figtree.
3. **`<x-primary-button>` / `<x-secondary-button>` / `<x-danger-button>`** are Tailwind-hardcoded and don't respect agency branding. Either deprecate and document (done above) or rewrite to use `corex-btn-*` classes.
4. **`<x-text-input>` / `<x-input-label>`** hardcoded greys. Add token-aware variants or inline the markup in CoreX forms (documented above).
5. **Compliance module** uses raw hex (`#00d4aa`, `#ef4444`, `#eab308`) and `border-radius:3px`. Audit and migrate to tokens + `rounded-md`.
6. **`<x-modal>`** overlay uses `bg-gray-500 opacity-75` — migrate to `bg-black/50`. Default dialog radius `rounded-lg` → `rounded-md` unless explicit override.
7. **`<x-dropdown>`** hardcodes `bg-white text-gray-800`. Retrofit to use tokens or accept `content-classes` override more broadly.
8. **Hover states via `onmouseover="..."`** in tables (rentals, deals). Replace with CSS `:hover` rule on `.ds-table tbody tr`.
9. **Three colour namespaces** exist: `--corex-*`, `--ds-*`, `--pres-*`, `--hfc-*`. Plan a migration to a single `--corex-*` namespace. Until then, this spec treats them as documented, non-additive.
10. **Dashboard cards (`bg-white border rounded-lg`)** diverge from the `rounded-md` standard. Either codify dashboard as an exception (richer cards with shadow) or migrate. This spec leaves dashboard as a documented exception pending product decision.
11. **`<x-list-header>`** is under-used. Several index pages (compliance, rentals) roll their own filter bar. Migrate them.
12. **`<x-corex-kpi-card>` `iconBg` default** mixes hex into Tailwind classes. Rework to token-based.
13. **Legacy `nexus.css`** still present in `resources/css/`. Confirm it's unreferenced, then delete.

---

## 10. Source of truth

- This spec overrides any conflicting rule in `.ai/DESIGN-SYSTEM.md`, `Claude_UX_Standards.md`, or `Claude UX standards.md`. Those documents should be updated to point here or deleted after any salvageable content is merged in.
- Commit spec changes to `main` only. Both dev branches pull from `main` for `.ai/` updates.
- When in doubt: **tokens over hex, components over duplication, patterns over creativity.**
