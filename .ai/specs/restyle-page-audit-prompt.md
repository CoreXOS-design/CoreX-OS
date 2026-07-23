# CoreX Restyle — Single-Page Audit & Fix Prompt (AT-336)
> Paste this prompt and change only the URL line. One page (and its sub-pages) per run.
> The app-wide foundation is already done; this is the per-page polish pass.

---

## PROMPT (copy from here down; edit the URL)

**AUDIT & FIX THIS PAGE:** `http://localhost:8000/<PATH>`

You are doing a **restyle-only** audit-and-fix of ONE CoreX page (and every sub-page/step/partial it renders) so it matches the AT-336 design system already applied to the Properties page. Visual only — never change behaviour.

### 0. Read first
- `.ai/specs/properties-restyle.md` — the design system + confirmed tokens (this is the source of truth).
- Use `resources/views/corex/properties/index.blade.php` and `show.blade.php` as the **reference implementation** — the page you audit should end up feeling like those.

### 1. Resolve the page to its files
- From the URL, find the route in `routes/web.php` → controller method → the Blade view it returns.
- Open that view AND every partial / sub-view it `@include`s or `@extends`, and — for multi-step flows (deal register, evaluations, wizards, onboarding steps) — **every step view**. List them before editing. Audit all of them.

### 2. What "correct" looks like (check each)
- **Header:** must be the flat neutral header, NOT a solid `--brand-default` navy/blue block. If it's still an inline brand banner, convert it: add the `corex-page-banner` marker class and remove the inline `background:var(--brand-default…)` (the CSS then makes it flat + full-bleed + neutralises inner white text/buttons). If it already uses `<x-page-header>` / `<x-list-header>` / `corex-page-banner`, leave the header alone.
- **Surfaces:** every card / panel / tile / table / modal body = `var(--surface)` background + `1px var(--border)`. No `bg-white`, `bg-gray-*`, `bg-slate-*`, or raw hex neutrals.
- **Inner wells / inputs / hovers / table headers:** `var(--surface-2)`.
- **Text:** heading `var(--text-primary)`, body `var(--text-secondary)`, muted `var(--text-muted)`, faint `var(--text-faint)`. No `text-gray-*` / `text-slate-*`.
- **Borders/dividers:** `var(--border)` (use `divide-[color:var(--border)]` for `divide-*` utilities; hover/ring/focus states need arbitrary-value classes like `bg-[color:var(--surface-2)]` since inline style can't express pseudo-states).
- **Primary button / main CTA:** `var(--brand-button)` + white text (or the `corex-btn-primary` class).
- **Secondary buttons:** ghost — `corex-btn-outline`, or `1px var(--border)` + `var(--text-secondary)`, no fill.
- **Accents — links, active tabs, active toggles, accent icons, count badges:** `var(--brand-icon)` (tints via `color-mix(in srgb, var(--brand-icon) N%, transparent)`). Never a hardcoded `sky-*`/`blue-*`.
- **Avatars / profile marks:** `var(--brand-default)` tint is correct — leave.
- **Tabs / sub-nav:** active = `var(--brand-icon)` underline + faint tint; idle = `var(--text-secondary)`.

### 3. Do NOT touch (leave exactly as-is)
- **Semantic colours:** green/emerald (success/live), red/rose (danger/delete), amber/yellow (warning), and any status badge — these carry meaning.
- **Functional everything:** `@php`, `@if/@foreach/@include`, routes, `x-data/x-model/@click`/Alpine, `wire:`, form `action=`/`name=`, ids, `data-*`, positioning styles (top/left/width/height/position/transform), and JS. This is VISUAL ONLY.
- **Do not touch:** e-sign signature-surface / template-editor views (`docuperfect/signatures/sign|setup|review|placeholder|property-documents|wet-ink*`, `templates/*`, `web-templates/*`); standalone client pages (`sales-documents/*`, `onboarding/portal`, `docuperfect/signatures/external/*`) which are their own Tailwind-CDN HTML with no tokens; public website (`public/*`); and `auth/*` login pages. If the page you were given IS one of these, STOP and report that it's intentionally out of scope.

### 4. Verify (required before you claim done)
For every Blade file you changed, run this and confirm "No syntax errors detected":
```
php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $p=app("blade.compiler")->compileString(file_get_contents($argv[1])); $t=tempnam(sys_get_temp_dir(),"b"); file_put_contents($t,$p); echo shell_exec(escapeshellarg(PHP_BINARY)." -l ".escapeshellarg($t)); @unlink($t);' <FILE>
```
Then `git diff` your changes and confirm no line touches routes/Alpine/handlers/positioning (colour/class/style lines only). Do NOT run `view:clear` (Vite is hot) or `dev-check.ps1` (CSS/Blade only).

### 5. Report back
- The files (incl. sub-pages/steps) you audited.
- Each fix: file:line → what was wrong → what token it now uses.
- Anything you deliberately left (semantic colour, brand mark, out-of-scope page) and why.
- Confirmation: all changed files compile; no behaviour changed.
- Do NOT commit unless asked.

---

## Notes for the human driver
- Work down the sidebar in order: **Today → Calendar/Tasks → My Portal → Real Estate (Properties ✓, Contacts, Buyer Pipeline, Presentations, Viewing Packs, Market Intelligence, Core Matches, Map, Outreach, Portal Leads) → Deals (each step) → Commercial Evaluations → Compliance → Documents → Communications → Payroll → Admin/Settings.**
- Many pages are already 90% done by the foundation + sweeps; the audit mostly catches: a still-branded header, a stray hardcoded colour the sweep left, a light/dark contrast miss, or a hardcoded `sky/blue` accent.
- Check BOTH themes (toggle in the sidebar footer) — a colour can pass in dark and fail in light.
- If a header's full-bleed border overlaps something above it, that page has content above the banner — tell the agent to make that banner `sticky:false`/non-bleed or move it.
