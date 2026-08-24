# CoreX Professional OS — Design System (Dual-Theme)

## Agency Brand Colour Management

All colours marked `[AGENCY]` are **dynamic** — set per-agency in **Settings > Agency Management**
and injected as CSS custom properties via `corex.blade.php`.

| Role | CSS Variable | Default | Usage |
|------|-------------|---------|-------|
| Sidebar | `--brand-sidebar` | `#0ea5e9` | Sidebar nav hover & active highlight colour |
| Icons | `--brand-icon` | `#0ea5e9` | Icons, active states, links, accents |
| Default | `--brand-default` | `#0b2a4a` | Default profile avatars, page headers, general branding |
| Buttons | `--brand-button` | `#0ea5e9` | Primary buttons, CTAs, action elements |

**Important:** The sidebar background itself is NOT agency-controlled — it uses the theme surface
(`--surface`). Only the hover/active highlight colour within the sidebar is branded via `--brand-sidebar`.

Injected in `resources/views/layouts/corex.blade.php` via inline `<style>` reading from the
authenticated user's agency record (`agencies` table: `sidebar_color`, `icon_color`, `default_color`, `button_color`).

---

## 1. Core Identity (Shared Across Themes)

### Geometry
- **Border radius:** Strictly `6px` (`rounded-md`) for all containers, cards, buttons, and inputs.
- No `rounded-2xl`, `rounded-xl`, `rounded-lg` — everything is `rounded-md`.

### Primary Accent
- **Brand Blue:** `#0ea5e9` — active states, primary buttons, key highlights.
- Linked to `--brand-button` and `--brand-icon` via agency colour management.

### Iconography
- Monochromatic strategy. Use `--brand-icon` for all icons to maintain a professional, focused interface.

---

## 2. Dark Mode Specifications (Default)

| Element | Value |
|---------|-------|
| Main Content BG | `#050505` |
| Sidebar/Nav BG | `var(--surface)` = `#13161d` (theme-controlled, not agency) |
| Sidebar Hover | `color-mix(in srgb, var(--brand-sidebar) 12%, transparent)` `[AGENCY]` |
| Glassmorphism | `white/5` background, `white/10` border, `backdrop-blur-xl` |
| Text Primary | `white` |
| Text Secondary | `white/50` |
| Hover States | `white/10` background, `white/20` border |

---

## 3. Light Mode Specifications

| Element | Value |
|---------|-------|
| Main Content BG | `#F8FAFC` (Slate 50) |
| Sidebar/Nav BG | `var(--surface)` = `#ffffff` (theme-controlled, not agency) |
| Sidebar Hover | `color-mix(in srgb, var(--brand-sidebar) 12%, transparent)` `[AGENCY]` |
| Glassmorphism | `white/80` background, `#E2E8F0` (Slate 200) border, `backdrop-blur-xl` |
| Text Primary | `#0F172A` (Slate 900) |
| Text Secondary | `#64748B` (Slate 500) |
| Hover States | `#E2E8F0` (Slate 200) background |

---

## 4. Component Patterns

### Cards
Use the `.glass` class pattern.
- Dark mode: deep and technical feel.
- Light mode: clean and airy feel.
- Border radius: `rounded-md` (6px).

### Buttons

**Primary:**
- Background: `--brand-button` (default `#0ea5e9`) `[AGENCY]`
- Glow: `shadow-lg` with `shadow-brand-500/20`
- Text: white, `font-semibold`, `rounded-md`

**Secondary:**
- Background: theme's "Glass" background with a subtle border.

### Inputs
- Background: theme-aware (`white/5` dark, `white/80` light)
- Focus ring: `--brand-button` (Brand Blue)
- Border radius: `rounded-md` (6px)

### Page Headers
> **Updated 2026-07-22 (AT-336 restyle).** The solid `--brand-default` banner is
> retired on restyled pages (Properties first). Page headers are now **flat, sticky,
> translucent chrome** — not a raised coloured block. The agency brand colour must
> NOT fill the header surface; it appears only on the primary CTA (`--brand-button`)
> and accent icons/links (`--brand-icon`).
- Layout: `sticky top-0 z-10`, `px-6 py-3.5`, breaking out of the content padding
  (`-mx`/`-mt`) so it pins to the scrollport top.
- Background: app bg at 85% opacity + `backdrop-blur`. A single **1px bottom border**
  in the fixed neutral border colour. No side/top borders, no surface fill, no rounded
  corners, no shadow.
- Title: `text-primary`, 16px bold. Subtitle: 12px muted.
- Buttons: ghost (`corex-btn-outline` — 1px neutral border, no fill, muted text that
  brightens on hover) for secondary actions; the single primary CTA uses
  `corex-btn-primary` (`--brand-button` + `shadow-md` at 25%). 32px square ghost icon
  buttons for Help/Settings. Pass `variant => 'surface'` to the tour launcher include
  so the "?" adapts to the neutral bar.

_Legacy (pre-AT-336): brand-default banner with white bold text. Still in use on
un-restyled pages; migrate them to the flat header as they are restyled._

### Stat / KPI cards (AT-336)
- Surface, background and border come ONLY from the fixed neutral slate system —
  never the agency brand. `rounded-lg`, 1px neutral border, card shadow, `px-5 py-4`.
- Value 26px bold tabular + 11px uppercase muted label; 36px `rounded-md` bordered icon
  tub on the right (neutral, or emerald for a "live" metric).
- **Active/selected filter** is indicated SOFTLY: border in `--brand-icon` ~40% + a
  faint `--brand-icon` 10% tint. No bright 2px ring, no glow.

### Dark-theme neutral tokens — confirmed (AT-336 fidelity pass)
The lift in dark mode comes from a two-tone step: the surface must be visibly LIGHTER
than the page bg, plus a just-visible border. These are the exact neutral values —
**no brand/teal tint on any neutral**:

| Token | Value | Role |
|---|---|---|
| `--bg` | `#020617` (slate-950) | page background **and sidebar** — one shared base tone |
| `--surface` | `#0F172A` (slate-900) | card / panel / stat-tile surface — lifts off bg |
| `--surface-2` | `#020617` (slate-950) | inner tiles, idle icon tubs (on the #0F172A surface) |
| `--border` | `#1E293B` (slate-800) | all card/panel/tile borders |
| `--text-primary` | `#FFFFFF` | headings, values |
| `--text-secondary` | `#E2E8F0` (slate-200) | body |
| `--text-muted` | `#94A3B8` (slate-400) | muted / ref-chip value |
| `--text-faint` | `#64748B` (slate-500) | faint / ref-chip label |

Elevation: each card also carries `box-shadow: 0 4px 12px rgba(0,0,0,0.30)`; hover
lifts the border to `--brand-icon` at 50% (no translate/bounce).

**Sidebar tone:** in dark mode the sidebar background **equals the page base
(`#020617`)** — not `--surface`, not pure black — with a `#1E293B` right border; its
drill-down panels use the same `#020617`. Page + sidebar are one deep tone; cards
float above both.

- **Glass status badges** (on the property image): `rgba(0,0,0,0.45)` + `backdrop-blur(6px)`
  + 1px ring `rgba(255,255,255,0.10)`, text `#F1F5F9`, 11px, 6px radius. Every real
  status stays (For Sale / Active / Open / Sole). "Active" adds a 6px emerald dot
  `#34D399` + emerald text `#34D399`. Same recipe for the photo-count badge.
- **Ref chip** (P24 / PP): 4px radius, 1px `--border`, **no fill**; label `--text-faint`,
  value `--text-muted` — neutral, never brand-tinted.

### Scrollbars
- Width: `6px` ultra-thin
- Thumb: matches theme's secondary text colour
- Radius: `6px`

---

## 5. Motion & Transitions

### Transitions
All theme switches and hover states: `transition-all duration-300` (300ms smooth).

### Entrance Animations
Use `motion/react` for subtle scale-in (`0.95` to `1.0`) and opacity fades on view changes.

---

## 6. CSS Variable Reference

### Agency Brand (dynamic, per-agency)
```css
--brand-sidebar: #0ea5e9;   /* Sidebar hover/active highlight */
--brand-icon:    #0ea5e9;   /* Icons, accents, links */
--brand-default: #0b2a4a;   /* Default profiles, page headers */
--brand-button:  #0ea5e9;   /* Buttons, CTAs */
```

### Component Aliases
```css
--corex-sidebar-hover: var(--brand-sidebar);
--corex-accent:        var(--brand-icon);
--corex-mid:           var(--brand-default);
```

### Theme System (fixed, not agency-controlled)
```css
/* Light (default) */
--bg:             #f4f6fb;
--surface:        #ffffff;
--surface-2:      #f0f2f8;
--border:         rgba(0,0,0,0.07);
--text-primary:   #111827;
--text-secondary: #4b5563;
--text-muted:     #9ca3af;

/* Dark */
--bg:             #0d0f14;
--surface:        #13161d;
--surface-2:      #1a1e28;
--border:         rgba(255,255,255,0.06);
--text-primary:   #eef0f5;
--text-secondary: #8890a4;
--text-muted:     #545b6e;
```

---

## 7. File Reference

| File | Purpose |
|------|---------|
| `resources/css/corex.css` | Root CSS variables, theme system, component styles |
| `resources/views/layouts/corex.blade.php` | Injects agency brand colours as CSS vars |
| `resources/views/layouts/corex-sidebar.blade.php` | Sidebar navigation |
| `app/Models/Agency.php` | Stores `sidebar_color`, `icon_color`, `default_color`, `button_color` |
| `app/Http/Controllers/Admin/AgencyController.php` | CRUD for agency brand colours |
| `resources/views/admin/agencies/create-edit.blade.php` | Brand colour picker UI (4 semantic roles, dual-theme preview) |

---

## 8. Page-by-Page Restyle Prompt

Use this prompt when going through each page to apply the design system:

```
Restyle this page to match the CoreX Design System (.ai/DESIGN-SYSTEM.md):

1. GEOMETRY: All border-radius must be rounded-md (6px). Replace any rounded-2xl, rounded-xl, rounded-lg with rounded-md.
2. COLOURS — use agency brand CSS variables:
   - Page header banner background: var(--brand-default, #0b2a4a)
   - Primary buttons/CTAs: var(--brand-button, #0ea5e9) with shadow-lg
   - Icons/accents/links: var(--brand-icon, #0ea5e9)
   - Default avatars/profile circles: var(--brand-default, #0b2a4a)
   - NO hardcoded brand colours — always use the CSS variables with fallbacks.
3. GLASSMORPHISM: Cards should use theme-aware backgrounds (var(--surface) bg, var(--border) border).
4. TRANSITIONS: All hover states and interactive elements: transition-all duration-300.
5. DARK MODE: Ensure all colours flow through CSS variables (var(--text-primary), var(--surface), etc). No hardcoded light-only colours.
6. BUTTONS: Primary = solid --brand-button with shadow-lg shadow-brand-500/20. Secondary = glass bg with subtle border.
7. INPUTS: theme-aware background, focus ring in --brand-button colour.
8. SCROLLBARS: 6px thin, thumb matches secondary text colour.
9. ENTRANCE: Add motion for view changes where appropriate (scale 0.95->1.0, opacity fade).

Read the full page first, then make targeted edits. Keep the same structure/layout, just update the styling to match the system.
```

---

## 9. Post-Restyle Audit Checklist

After restyling any page, run this audit to confirm nothing is broken:

### Automated checks
1. `php -l` on all changed PHP/Blade files — no syntax errors.
2. `php artisan view:clear` — clear compiled views.
3. `scripts/dev-check.ps1` — full lint + cache clear + tests.

### Manual page audit (do for every restyled page)
Load the page in the browser and verify:

| # | Check | What to look for |
|---|-------|-----------------|
| 1 | **Page loads** | No 500/404/blank screen. Content renders. |
| 2 | **Layout intact** | Sidebar, header, content area all present and correctly positioned. |
| 3 | **Data displays** | Real data from DB shows (not 0/blank/missing). Tables populated, counts correct. |
| 4 | **Geometry** | All corners are `rounded-md` (6px). No rounded-2xl/xl/lg/full remnants. |
| 5 | **Colours** | Page header uses `--brand-default`. Buttons use `--brand-button`. Icons use `--brand-icon`. No hardcoded brand colours. |
| 6 | **Theme** | All text/bg/border uses CSS variables. No hardcoded `slate-*`/`gray-*` Tailwind colours (except status badges). |
| 7 | **Interactions** | Buttons clickable. Links navigate correctly. Forms submit. Dropdowns open/close. |
| 8 | **Transitions** | Hover states animate smoothly (300ms). No jarring snaps. |
| 9 | **Spacing** | Consistent gaps between sections. No cramped or overly loose areas. |
| 10 | **Responsive** | Page doesn't break at common widths (resize browser). No horizontal overflow. |
| 11 | **Dark mode** | Toggle theme — all elements remain readable. No white-on-white or black-on-black. |
