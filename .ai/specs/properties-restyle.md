# BUILD PROMPT — CoreX OS Premium UI (Properties + Sidebar) — FINAL / CONSOLIDATED
> AT-336 restyle. Single source of truth for the Properties/sidebar dark+light restyle.
> Last updated: 2026-07-22 (Andre). Supersedes the separate v1/v2/fidelity prompts.

## ROLE
Senior product designer + frontend engineer. Restyle the CoreX OS **Properties pages**
(index, show/edit) and **sidebar** to a Linear/Stripe/Vercel-calibre dark+light system
with a live theme toggle. This is a RESTYLE of existing shells — not a rebuild.

## GOVERNANCE (read first, do not skip)
1. Read CLAUDE.md, .ai/STANDARDS.md, .ai/BUILD_STANDARD.md and .ai/DESIGN-SYSTEM.md
   before changing anything.
2. Scope = ONLY the Properties pages + the sidebar. Do NOT change features, links,
   inputs, buttons, nav structure/routes, permissions, or Alpine behaviour. You are
   restyling shells. Anything else you notice → REPORT, don't touch.
3. QA1 only. Nothing to Staging/live. No commits/promotion unless asked.
4. INVESTIGATE FIRST: open the actual file/partial that renders each element, confirm
   file + lines, note what produces the current styling, then apply the spec to those
   exact files.
5. Verification: php -l on changed .php (compile Blade + lint), confirm CSS braces
   balance. Do NOT run scripts/dev-check.ps1 or the full suite (non-negotiable #13 —
   this is CSS/Blade/MD only) unless explicitly told. Do NOT run `view:clear` while
   Vite dev is hot (Blade auto-recompiles). Update .ai/DESIGN-SYSTEM.md to match.

---

## IMPLEMENTATION STRATEGY (keeps blast radius contained)
- All new rules live in ONE clearly-marked, appended-LAST block in resources/css/corex.css
  (wins on source order; deletable to revert).
- Reskin each Properties page via a scoped wrapper class **`.corex-props-v2`** on the page
  root, which overrides the neutral tokens (`--bg/--surface/--surface-2/--border/--text-*`)
  to the slate palette below. This reskins every card/panel/tile the page already renders
  with almost no HTML change. The rest of the app keeps the global theme.
- The page **canvas** (the `<main id="appScroll">` element) is global, so scope it with
  `html.dark main#appScroll:has(.corex-props-v2) { background:#020617 !important }` — only
  pages carrying `.corex-props-v2` change.
- The **sidebar** is a shared component (explicitly in scope) → its restyle is global
  (applies on every page's sidebar); that's intended.

---

## AGENCY BRAND TOKENS (⚠ never hardcode — read from agency settings; theme-independent)
Four brand roles as CSS vars, each defaulting to the agency accent. Tints via
`color-mix(in srgb, var(--brand-x) N%, transparent)` — never invent fixed tints.

  --brand-sidebar   → sidebar hover & active highlight
  --brand-icon      → icons, active states, links, accents, logo mark
  --brand-default   → profile/agent avatars, general branding marks
  --brand-button    → primary buttons / CTAs

| Element | Token |
|---|---|
| Sidebar active-row bg, active ring, left accent bar, hover bg, active badge | --brand-sidebar |
| Active nav icon, links, card hover-glow border, accent icons, **logo mark**, active-control fills (view toggle, My/All pills, stat active border), scrollbar thumb | --brand-icon |
| Agent/user avatars | --brand-default |
| Primary buttons (New Property, Ad, Save, Create-first-listing) + their glow | --brand-button |

⚠ Brand colour must NEVER fill a neutral card/panel/tile surface, border, or page/sidebar
background. If the agency `--brand-default` is a deep navy, do NOT use it for anything that
must read on the dark sidebar (e.g. the logo mark) — use `--brand-icon`.

---

## FIXED NEUTRAL SYSTEM — CONFIRMED VALUES (scoped to `.corex-props-v2`)

### DARK  (`html.dark .corex-props-v2`, plus canvas + sidebar)
| Token | Value | Role |
|---|---|---|
| --bg | #020617 (slate-950) | page canvas **and sidebar** — one shared base tone |
| --surface | #0F172A (slate-900) | ALL cards, stat tiles, filter bar, list, panels — lifts off bg |
| --surface-2 | #020617 (slate-950) | inner tiles / idle icon tubs / image wells |
| --border | #1E293B (slate-800) | 1px on every card/panel/tile |
| --border-hover | #334155 (slate-700) | |
| text-primary | #FFFFFF | headings/values |
| text-secondary (body) | #E2E8F0 (slate-200) | |
| text-muted | #94A3B8 (slate-400) | ref-chip value |
| text-faint | #64748B (slate-500) | ref-chip label |
| emerald | #34D399 (emerald-400) | live/positive/sold |
| card shadow | 0 4px 12px rgba(0,0,0,0.30) | |

- The page canvas AND the sidebar share **#020617** (sidebar = base, NOT --surface, NOT
  pure black); sidebar right border #1E293B; drill-down nav panels also #020617. Cards
  float one clear step above at #0F172A. No navy/teal cast on any neutral.

### LIGHT  (`.corex-props-v2`)
| Token | Value | Role |
|---|---|---|
| --bg | #f8fafc (slate-50) | page |
| --surface | #eef2f6 | cards — a soft grey, NOT pure white |
| --surface-2 | #ffffff | inner/wells (lighter than card so they pop) |
| --border | #cbd5e1 (slate-300) | bumped from slate-200 for clear visibility |
| --border-hover | #94a3b8 (slate-400) | |
| text-primary | #0f172a | body #334155 · muted #64748b · faint #94a3b8 |
| emerald | #059669 (emerald-600) | |
| card shadow | 0 1px 2px rgba(15,23,42,0.06) | |

Semantic (both themes): destructive hover = rose-400 dark / rose-500 light. Image glass
overlay = black 45% + backdrop-blur(6px) + ring white/10, text slate-100.

---

## GLOBAL TOKENS
Font: 'Inter', ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif.
Radii: cards/stat tiles/header ghost buttons = 8px (rounded-lg) · sidebar logo tub, branch
switcher, search, nav rows = 12px (rounded-xl) · View/Ad/CTA/help/settings = 6px (rounded-md)
· ref chips = 4px · avatars/progress/status dots/left-bar = full.
Transitions: 150ms on colour/border. NO hover translate/bounce. All numerics = tabular-nums.

---

## PAGE HEADER  (flat bar — NOT sticky)
- A flat bar at the TOP of the page in normal flow — it scrolls away with content (NOT
  sticky, no pinning). Break it out of `<main>`'s padding with `-mx-4 lg:-mx-6 -mt-4 lg:-mt-6`
  + `px-6 py-3.5` so its 1px BOTTOM border (var(--border)) spans full width and it sits flush
  at the top. No card fill, no rounded corners, no shadow, no brand block — neutral chrome.
- Left: H1 (page title) 16px bold text-primary + subtitle 12px muted.
- Right cluster (gap 8px): every existing action preserved. Secondary buttons = ghost
  (`corex-btn-outline`: 1px neutral border, no fill, muted text, hover brightens). The
  primary/save CTA = `corex-btn-primary` (--brand-button + glow). Help tour launcher gets
  `variant => 'surface'`. Square icon buttons (Settings etc.) = 32px ghost.

## STATS BAR  (4 equal cards, gap 16px; 2-col < lg)  [index]
- Each tile: neutral surface + 1px neutral border + card shadow, rounded-lg, px-5 py-4.
  Value 26px BOLD tabular + 11px uppercase muted label under it; 36px rounded-md bordered
  icon tub on the RIGHT. Total (Home, neutral) · On Market (TrendingUp, EMERALD tub) ·
  Draft (PenLine, neutral) · Sold (Building2, neutral). Hover = border lifts one step.
- Active/selected filter tile = SOFT: border `--brand-icon` ~40% + faint `--brand-icon` 10%
  tint over the surface. NO bright 2px ring, no glow.

## PROPERTY CARD (grid)  [index]
- rounded-lg neutral card + shadow, overflow-hidden. Hover = border-glow `--brand-icon` 50%
  + brand shadow tint (no translate).
- IMAGE full-bleed h-160px (touches top & sides). Real <img> object-cover; empty state =
  house icon (text-muted, low opacity) on a NEUTRAL `linear-gradient(--surface-2 → --surface)`
  well (never brand-filled). Scrim white6%→transparent→black45%.
    · Top-left: glass badges (rgba(0,0,0,0.45)+blur6+ring white/10, text #F1F5F9, 11px, 6px
      radius) — every real status kept (For Sale/For Rent, status, mandate Open/Sole). The
      live status adds a 6px emerald dot #34D399 + emerald text #34D399; sold = rose text.
    · Top-right: the real syndication/published control (keep the feature).
    · Bottom-right: glass photo-count badge (same recipe).
- BODY (p-4): PRICE hero 22px BOLD tabular text-primary. Address (primary link) truncate;
  title secondary. Specs row (type + Bed/Bath/Gar/m², faint icon + muted tabular, omit
  missing). Ref chips: 4px radius, 1px --border, **NO fill**, label = text-faint, value =
  text-muted (neutral — no brand tint). Footer (top border): 20px agent avatar
  (--brand-default tint) + muted name; actions = View (ghost), **Ad (PRIMARY --brand-button)**,
  28px square delete → rose on hover.

## PROPERTY SHOW / EDIT PAGE  (`/corex/properties/{id}`)
Same system, applied to the detail/edit shell:
- Wrap the page root in `.corex-props-v2` (this alone adopts the slate palette + dark canvas
  on every panel using `var(--surface/--border/--text-*)`).
- Convert the top page header from any `background: var(--brand-default)` brand block to the
  flat neutral header above (title + subtitle + preserved action buttons as ghost + a single
  primary Save/CTA). Pass tour launcher `variant => 'surface'`.
- Every content panel/section card = neutral `--surface` + 1px `--border` + the card shadow,
  rounded-lg. Tabs/sub-nav: active uses `--brand-icon`; idle muted. Inputs/wells use
  `--surface-2`. Section headings text-primary; labels text-muted/faint. Image gallery/hero
  well = neutral gradient (never brand-filled); glass overlays reuse the badge recipe. Any
  status/marketing pills use the semantic emerald/rose or neutral — not a brand-filled block.
- Keep ALL functionality: tabs, forms, syndication panel, intelligence panels, share/PDF
  actions, maps, every route and Alpine component. Restyle shells only.

## EMPTY STATE (keep, restyle)  [index]
House icon in a neutral circle + small --brand-icon "+" badge; bold title + muted subtitle;
PRIMARY CTA (--brand-button).

## LIST VIEW  [index]
Neutral thumbnails (neutral gradient well + muted empty icon), price cell = text-primary
tabular. (Status pills still use --brand-default — flagged for a dedicated list pass.)

---

## SIDEBAR (w-64, full-height, right border, flex column)
- **Logo mark**: a 32px rounded-xl tub built from **--brand-icon** (NOT --brand-default,
  which is too dark on #020617) — soft `--brand-icon 18%` tub + `--brand-icon 40%` ring
  containing a 14px solid --brand-icon block, beside "CoreX" + "Os" accent. Done as a CSS
  `::before` on the wordmark (no HTML change).
- Dark background = **#020617** (same as page), right border #1E293B, drill-down panels also
  #020617.
- **Controls retone** — search field, Switch-Branch / Acting-as switchers, and the theme
  picker must NOT be stark --surface-2 grey boxes. Use a faint theme-blending inset via a
  shared token on `.corex-sidebar`:
    dark  → --side-control-bg rgba(255,255,255,0.03), --side-control-border #1E293B
    light → --side-control-bg rgba(15,23,42,0.03),   --side-control-border rgba(15,23,42,0.10)
  (Keep --surface-2/--border as fallbacks. Focus/hover states unchanged.)
- Nav rows: rounded-xl. Active = `--brand-sidebar` 10% bg + inset ring `--brand-sidebar` 25%
  + text-primary + a 3px×20px rounded-r bar of solid --brand-sidebar on the left edge + icon
  in --brand-icon. Idle muted; hover brightens. Same treatment for active sub-items.
  (KEEP the existing permissioned drill-down IA and every route/badge — restyle only.)
- **Fancy scrollbar** (scoped to sidebar scroll areas — the raw browser bar breaks the
  theme): 9px, transparent track, a slim FLOATING pill thumb (2px transparent border +
  background-clip:padding-box) with a `--brand-icon → --brand-sidebar` gradient at ~35–50%,
  brightening to full brand on hover. Firefox via scrollbar-width:thin + scrollbar-color.
- Footer: 36px avatar (--brand-default tint) + name/role + ghost theme-toggle (Sun in dark,
  Moon in light).

---

## THEME
Single theme state at root; sidebar footer toggle flips it. Neutral + semantic colours
resolve from the theme maps; the four --brand-* vars are theme-independent.

## DELIBERATELY NOT IN THIS PASS (report, don't build)
- Sidebar IA is NOT flattened into OPERATIONS/INTELLIGENCE/OUTREACH — the live drill-down is
  permission-wired; regrouping would break features. Only its look is restyled.
- No Earnings widget / right-column layout is added to Properties (net-new feature, needs a spec).
- List-view status pills still brand-filled — leave for a dedicated list-view pass.

## QUALITY FLOOR
Responsive to mobile, visible keyboard focus (--brand-icon), reduced-motion respected, no
browser storage. Icons = Lucide thin-stroke (1.75). `:has()` needs an evergreen browser
(fine for QA).

## FILES (this restyle touches)
- resources/css/corex.css — one appended `AT-336` block (scoped palette, canvas/sidebar tone,
  card/stat/glass/chip classes, sidebar logo/active/scrollbar/control-retone).
- resources/views/corex/properties/index.blade.php — header, stats, cards, list, empty.
- resources/views/corex/properties/show.blade.php — show/edit shell (wrapper + header + panels).
- resources/views/corex/properties/partials/p24-number-fix.blade.php — ghost trigger.
- resources/views/layouts/corex-sidebar.blade.php — control retone tokens on switch buttons.
- .ai/DESIGN-SYSTEM.md — confirmed dark/light tokens + header/stat/sidebar notes.
