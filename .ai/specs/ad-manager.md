# Ad Manager — Module Spec

> Status: ACTIVE — build in flight on `AT-7-Finish-ad-manager-for-CoreX`
> Last updated: 2026-06-13 (Andre)
> Pillars: **Property** (read), **Agent** (read), **Agency** (read/scope)

---

## 1. What this feature does and why

The Ad Manager lets an agent turn any property listing into a polished, download-ready
social/marketing graphic in seconds — the "red button" for property marketing. From a
property the agent opens **Create Ad**, picks a design (a pre-built CoreX template or an
agency custom template), the design auto-fills with that property's real data (price,
photos, agent, features), and the agent downloads a PNG sized for Facebook / Instagram /
Story / WhatsApp or pushes it straight into the Marketing hub.

Two template tiers:

| Tier | Source | Editable | Visibility |
|------|--------|----------|------------|
| **Pre-built** | Hand-crafted Blade in `_ad-templates.blade.php` | No (ships with CoreX) | All agencies |
| **Custom** | Built in the drag-drop Ad Builder, stored in `property_ad_templates` | Yes | The agency that built it only |

Why it matters: marketing graphics are otherwise made in Canva/Photoshop outside the
system, disconnected from listing data and agency branding. The Ad Manager absorbs that
work into CoreX — on-brand, on-data, one click — and keeps custom designs as agency IP.

---

## 2. Pillar connections

- **Property** — READ. Every ad is generated for a specific `Property`; the generator
  injects `formattedPrice()`, `allImages()`, beds/baths/garages, size, suburb, type, status.
- **Agent** — READ. Listing agent name, email, phone, designation, avatar appear on the ad.
- **Agency** — READ + tenancy. Logo/branding pulled from the property's branch → agency.
  Custom templates are scoped to the agency via `AgencyScope` (multi-tenancy.md).

The Ad Manager does not write back to a pillar (it produces an export). When an ad is
pushed to Marketing, the existing Marketing share log records the action — no new
write path is introduced here.

---

## 3. Data model

### Table: `property_ad_templates` (exists)

| Column | Type | Notes |
|--------|------|-------|
| `id` | PK | |
| `agency_id` | FK agencies, NOT NULL | tenancy — set by `BelongsToAgency` |
| `user_id` | FK users | the **creator** — drives edit/delete rights |
| `name` | varchar(100) | |
| `layout_json` | json | `{ elements[], canvasW, canvasH, canvasBg, canvasBgGradient?, canvasPreset }` |
| `is_global` | boolean | **deprecated for cross-agency use.** Never used to read across agencies (caused a tenancy leak). Kept only as a no-op until a follow-up migration drops it. |
| `deleted_at` | timestamp | soft delete (non-negotiable #1) |

No `property_id` column. Custom templates are **reusable** across every property in the
agency by design; a template is bound to a property only at generation time.

`layout_json.elements[]` element shape (superset — fields default if absent):

```
{ id, field, label, x, y, w, h, zIndex,
  fontSize, fontWeight, color, textAlign, textTransform, letterSpacing, lineHeight, padding,
  bgColor, bgOpacity,                 // text "pill" background
  borderWidth, borderColor, rotation, // frame/transform
  objectFit, borderRadius,            // image
  bg, opacity,                        // color_block
  gradFrom, gradTo, gradAngle,        // gradient
  text }                              // custom_text / badge literal copy
```

---

## 4. UI placement & navigation

- Entry: **Property → Create Ad** button (existing `corex.properties.ad`,
  URL `/properties/{property}/ad`). This is the navigation entry (non-negotiable #2).
- Template picker (Step 1) lists pre-built templates + the agency's custom templates,
  plus a **Build a custom template** / **New Template** action (permission-gated).
- Ad Builder: `corex.ad-templates.builder` (URL `/ad-templates/builder`) and
  `corex.ad-templates.builder.edit` (`/ad-templates/builder/{template}`). Opened from a
  property carries `?property={id}` so the canvas previews real property data and offers
  **Use on this property →**.

---

## 5. User flow

**Generate an ad**
1. Agent opens a property → **Create Ad**.
2. Picks a pre-built or custom template card.
3. Generator fills the design with the property's real data; agent switches platform
   (FB/IG/Story/WhatsApp).
4. **Download PNG** or, when arriving from the Marketing hub, **Use for Marketing**.

**Build a custom template** (needs `access_properties`)
1. From the picker, **New Template** → Ad Builder opens (carrying `?property={id}`).
2. Drag fields from the catalogue onto the canvas; live preview shows the current
   property's real data so the agent designs against reality, not placeholders.
3. **Save Template** → stored against the creator + agency.
4. **Use on this property →** returns to that property's ad picker with the template ready.

**Edit / delete a custom template**
- The **creator** can always edit/delete their own template.
- Any other agency member needs the `properties.ad_templates.manage` permission to
  edit/delete templates created by others within the same agency.
- No one can see, edit, or use a template from another agency (`AgencyScope`).

---

## 6. Permissions

- `access_properties` — gates the builder routes and the New/Edit/Delete actions in the picker.
- `properties.ad_templates.manage` — **new** action permission (section `properties`).
  Grants edit/delete on *other* members' agency templates. Creators bypass it for their own.
  Appears automatically in the Role Manager (catalogue-driven from `config/corex-permissions.php`).
- Default role grants: super_admin, admin/owner, branch_manager. Agents: own templates only.

---

## 7. Branding

- The "logo" element and pre-built templates render the **property's branch logo →
  agency logo → CoreX wordmark** fallback — never a hard-coded "nexusos"/HF Coastal mark.
- CoreX wordmark fallback: `corex` (white) + `os` (cyan `#33c4e0`), per the brand system.
- Watermark/footer text uses the agency name, not a hard-coded "HF COASTAL".

---

## 8. Pre-built template catalogue

Existing: **Power**, **Luxe**, **Split**.

New (this build — "do both": proposed 5 + alternate mix = 10):
1. **Just Listed** — announcement ribbon + single hero.
2. **Open House** — viewing call-out block over hero (no fabricated date; "by appointment"/agent to book).
3. **Editorial** — minimalist luxury, light canvas, large hero, generous type.
4. **Feature Grid** — 4-photo mosaic showcasing rooms.
5. **Price Spotlight** — oversized price + "NEW PRICE" tag.
6. **Coming Soon** — teaser, blurred/dim hero, "COMING SOON".
7. **Sold / Under Offer** — celebration overlay stamp.
8. **For Rent** — rental-focused, per-month price emphasis.
9. **Agent Spotlight** — agent headshot + tagline over hero (testimonial-style intro).
10. **Showcase** — 5-photo filmstrip carousel-style strip.

All render at the 4 platform presets and adapt to missing data (no broken layouts).

---

## 9. Expanded Ad Builder range

New catalogue fields: `custom_text`, `agency_logo` (real logo image), `status_badge`,
`reference`, `address`, `agent_phone`, `agency_name`, `website`, `line` (divider),
`badge` (pill), `shape` (circle/rect), `gradient` (overlay).

**Agent 2 (co-listing) fields** (AT-124): the Agent group exposes a full second
set — `agent_2_name`, `agent_2_email`, `agent_2_phone`, `agent_2_designation`,
`agent_2_avatar` — so a designer can build true **dual-agent templates** (place
Agent 1 and Agent 2 elements separately). They preview a co-agent placeholder on a
single-agent property and resolve to the real co-listing agent when one exists; on a
single-agent listing they render **empty** (never a placeholder) in the generator.

New per-element controls: text background colour + opacity (pill), border width + colour,
rotation, line-height. New canvas controls: two-stop background gradient + angle, extra
presets (LinkedIn 1200×627, Pinterest 1000×1500).

**Builder overhaul (AT-124):**
- **Shape list.** A `shape` element now carries a `shapeType` chosen from a visual
  picker — `rectangle`, `rounded` (editable corner radius), `circle`, `pill`,
  `triangle`, `diamond`, `pentagon`, `hexagon`, `star`, `chevron`. Geometry is one
  shared `shapeCss()` in the builder, mirrored by `SHAPE_CLIPS` in the generator
  (clip-path for the polygonal shapes). Legacy shapes (no `shapeType`, `borderRadius`
  as a %) still render unchanged.
- **Colour Block removed** from the palette (its renderer is kept so existing
  templates still display/edit).
- **Custom Image / Custom Video.** Two new fields let a user upload their own media
  into a block — `POST corex.ad-templates.upload-media` (image/video, ≤40 MB,
  server-side mimetype check, stored on the public disk under `ad-media/{agency}`);
  the URL is saved into the element's `src`. Video plays in the live preview; a
  downloaded **PNG captures a single still frame** (html2canvas limitation, noted in
  the panel).
- **Features chooser.** A `features` element now offers a checklist of the property's
  actual amenities (`Property::adData()['features_list']`); the chosen subset is
  stored in `el.selectedFeatures` (null = all). Falls back to the beds/baths summary
  when the property has no listed features.
- **On-element action toolbar.** Selecting any element shows a floating toolbar
  pinned above it on the canvas with **Duplicate / Rotate 45° / Delete** (counter-
  scaled so it stays a constant on-screen size at any canvas zoom).

---

## 10. Acceptance criteria

- [ ] Saving a custom template succeeds (no `/nexus/*` 404s); reopening loads it.
- [ ] Builder opened from a property shows that property's real data in the canvas and
      offers **Use on this property →** back to its ad picker.
- [ ] Every agency member sees all custom templates built in their agency; none from any
      other agency (verified with a 2-agency check — no `is_global` cross-agency leak).
- [ ] A non-creator without `properties.ad_templates.manage` cannot edit/delete another
      member's template (403); with it, they can. Creator always can.
- [ ] No "Nexus"/"nexusos" strings remain in the ad builder or generator; logo resolves to
      branch→agency→CoreX.
- [ ] 13 pre-built templates render correctly at all 4 platform sizes and degrade cleanly
      with 0–3 images.
- [ ] Expanded fields/controls persist in `layout_json` and re-render in the generator.
- [ ] `scripts/dev-check.ps1` passes with 0 new failures.

---

## 10b. Bulk Ad Manager (Tools)

A standalone page at **Tools → Ad Manager** (`/tools/ad-manager`) for producing ads for
**many properties at once**.

**Flow**
1. **Select properties.** A user with the all-agents permission sees every agency agent as a
   collapsible group; they expand an agent, tick that agent's properties (or "select all" for
   the agent), and can "skip" an agent. Selections accumulate across agents. A user without it
   sees only their own properties.
2. **Choose a template** — any pre-built template or an agency custom template.
3. **Generate.** The result is a list (one row per property) each with: the rendered ad + a
   **Download PNG** button, and the **AI description** (copy-to-clipboard). Optional "Include
   emojis ✨" toggle.

**Permissions (role manager)** — catalogue-driven, under the **Tools → Ad Manager** feature:
- `access_ad_manager` (access) — use the page + see the nav entry.
- `ad_manager.view` (action, **data-scope key**) — drives the **None / Own / Branch / All**
  selector in Role Manager, deciding whose listings the user may build ads for:
  - **None / Own** → only the user's own listings (no agent picker).
  - **Branch** → the user's own listings + other agents' listings in the same branch
    (agent picker shows branch agents).
  - **All** → every agent's listings in the agency (full agent picker).
  Enforced server-side per property in `index()`/`previews()`/`generate()` via
  `AdManagerController::canAdvertise()` — never trusted from the client. The scope is read
  with `PermissionService::getDataScope($user, 'ad_manager')`.
- Defaults (`scope_defaults`): super_admin/admin → All; branch_manager → Branch;
  agent → Own. This is the "Agents do their own, managers do their branch, admins do all"
  rule. (Replaced the legacy boolean `ad_manager.all_agents`, removed 2026-06-25.)

**Rendering** — the server renders the chosen pre-built template to HTML per property via the
shared `_ad-templates` partial (fed by `Property::adTemplateVars()`); the client shows it and
captures a PNG with html2canvas (images are same-origin via `publicImageUrl`, no `crossorigin`).
Custom templates return `layout_json` + `adData` and render client-side.

**Descriptions** — same `MarketingCopyService` (lowest tier, strict grounding, live-preview
link, no invented facts, optional emojis). Each call is budget-gated + cost-logged. If AI is
unavailable (no key / budget), the ad image still renders; the row shows the reason instead of
copy. Batch capped at 50 properties.

---

## 10e. Same-origin image resolution (html2canvas + cross-host storage)

The PNG is rasterised by **html2canvas, which can only read SAME-ORIGIN images** —
a cross-origin `<img>` displays but exports **blank**. `Property::adSafeImageUrl()` is
the single resolver every ad surface uses (generator `image_1..5` + logo, the
gallery picker, the bulk manager, the builder preview). It resolves in three tiers:

1. **File is on this host** (`public/storage/…` exists) → **host-relative `/storage/…`**
   (direct from the web server, same-origin, fastest). This is the normal prod path.
2. **File is on another of our hosts** (e.g. **Staging referencing live-hosted photos**,
   stored as absolute `https://corexos.co.za/storage/…` URLs) → route through the
   **same-origin proxy** `GET corex.properties.ad-media?u=<url>` (root-relative, so
   same-origin on any host). The proxy streams the local file when present, else
   fetches the bytes server-side and streams them — so the image **both displays and
   captures**. SSRF-safe: host allow-list (our storage domains only) + behind auth +
   `access_properties`; strong `Cache-Control`, no server-side blob cache.
3. **Genuinely external** (not `/storage/`) → left absolute (nothing we can re-home).

Why: without this, an environment that references images whose files it does not host
either 404s them (host-relative) or exports blank PNGs (cross-origin absolute). The
proxy makes every host correct. Handler: `PropertyController@adMedia`; its route is
declared **before** the `/{property}` catch-all so `ad-media` isn't matched as a
property slug.

---

## 10c. Printable Brochure (always-first · always-A4 · true PDF)

A special pre-built template that is **always first** in every picker and **always
A4** regardless of the platform/size selector. Unlike the social-square templates
(rendered client-side to PNG via html2canvas), the brochure is a **true single-page
A4 PDF** rendered server-side with **dompdf** (`barryvdh/laravel-dompdf`, already a
dependency) — it is meant to be printed and handed out, so it must be vector text,
A4 and print-crisp.

**Layout** (top→bottom): **centred agency logo** header; a **full-bleed photo grid**
— two hero photos (40% / 60%) with a solid-navy, square (un-rounded) **price badge
on the bottom-right of the right photo**, then a **5-photo thumbnail strip**; centred
title + location (pin); a **specs bar** (beds / baths / garages / parking) with line
icons — **any 0/empty spec is hidden** (vacant land shows no specs row); a **single
sub-heading line** of **Rates & Taxes · Levy · Floor Size** (only those present);
a **justified** description **capped so the brochure stays a single A4 page** (the QR
links to the full listing); and a footer with the **agent** (rounded-square photo,
name, phone, email) on the left and a **QR code** to the public listing preview on
the right. **Property features are intentionally NOT listed.** Download filename is
`Brochure - {address}.pdf`.

**Font**: the PDF embeds **Inter** (the CoreX UI font) — TTFs committed at
`resources/fonts/inter/Inter-{400,500,600,700}.ttf`, registered via `@font-face` in
`brochure-pdf.blade.php`. dompdf must WRITE a font-metrics cache; its default
`storage/fonts/` is created by the deploy user and is NOT writable by php-fpm
(→ "Permission denied" on staging). So `PropertyBrochureService::pdf()` points
dompdf's `fontDir`/`fontCache` at **`storage/app/dompdf-fonts`**, which the service
`@mkdir`s at runtime — created by the web process, so it's owned/writable by it on
every host (and already gitignored under `storage/app`). The **location pin** is a
**GD-drawn PNG** (`pinDataUri()`), not an inline SVG — an inline SVG's point gets
clipped at the text baseline in dompdf/browsers; a raster sizes predictably.
Image robustness: GD-undecodable formats (e.g. `.webp` on a no-webp GD build) embed
their raw bytes rather than dropping (dompdf renders webp/png/jpeg natively).

**Architecture**
- `App\Services\Properties\PropertyBrochureService` — single source of truth.
  - `data(Property, bool $embed)` builds the `$b` array consumed by the partial.
    `embed=true` (PDF) → every image is a downscaled base64 **data-URI** (GD), so
    dompdf needs no remote fetching and the file is self-contained; `embed=false`
    (browser thumbnail) → plain URLs (fast; no GD/QR work).
  - `pdf(Property)` renders `corex.properties.brochure-pdf` to an A4 dompdf doc.
  - Robustness: never remote-fetches the app's OWN host (a `/storage/...` URL whose
    file is missing locally returns null instantly instead of hanging on an HTTP
    round-trip to ourselves); external CDN images use a short-timeout best-effort
    fetch; QR is cached 1 day and only fetched for the real PDF.
- `resources/views/corex/properties/_brochure.blade.php` — **dompdf-safe** partial
  (tables not flex/grid; `background-size:cover` LONGHAND not the shorthand
  slash-syntax dompdf can't parse; data-URI SVG `<img>` icons via the bundled
  php-svg-lib; border-radius background clipping for circular photo). The SAME
  partial renders the PDF AND the picker-card thumbnails in the browser.
- `resources/views/corex/properties/brochure-pdf.blade.php` — `@page{margin:0}` A4
  wrapper; the brochure's 794px width = A4 @ 96dpi, its 30px padding = print margin.
- Route `GET /corex/properties/{property}/brochure` → `PropertyController@brochure`
  (`corex.properties.brochure`). `?dl=1` forces a download attachment; default
  streams inline. Property-access scope enforced (`AuthorizesPropertyAccess`);
  `AgencyScope` makes a foreign-agency listing 404, never a leak.

**Surfaces**
- Single-property **Create Ad** (`ad.blade.php`): a featured brochure card sits
  above the social grid (always first) with an A4 portrait preview + Download/Open
  PDF actions linking to the route.
- Bulk **Tools → Ad Manager**: `brochure` is the first entry in the catalogue
  (`AdManagerController::prebuiltTemplates()`), previewed at A4; selecting it makes
  `generate()` return one row per property with an A4 preview + a Download Brochure
  PDF link (no html2canvas, no AI copy — the brochure is a self-contained handout).

**Acceptance**
- [ ] Brochure card is first and A4 on both surfaces.
- [ ] Route streams `application/pdf` starting `%PDF`; `?dl=1` is an attachment.
- [ ] Degrades cleanly with 0 images / no agent / missing rates|levy.
- [ ] Foreign-agency listing 404s. Covered by `tests/Feature/Properties/BrochurePdfTest.php`.

---

## 10d. Agent identity (who appears on the ad) — AT-124

> Status: LIVE (single-property generator + brochure) · 2026-06-29 (Andre)
> Driven by agent feedback: *"Kan ons dalk by die ads 'n opsie hê om die agent se
> naam te kan verander veral as daar meer as 1 agent op 'n eiendom werk?"*

Every ad defaulted to the **listing agent** (`Property::adData()` hard-wired
`$this->agent`), with no way to change whose name/contact appears — even though a
listing can be **co-worked by two agents** (the co-listing agent already lives on
`pp_second_agent_id` / `Property::secondAgent()`, used for P24/PP dual-agent
syndication). This section adds an **agent selector** to the Ad Manager.

**Capabilities** — there is **no general agent picker**. An ad shows the people who
actually work the listing. The choice only appears **when the listing is co-listed**
(has a `pp_second_agent_id`):
- **Listing agent** (default) · **Co-agent** · **Both** — a 3-way segmented control
  in the generator toolbar (and on the brochure card). The chosen agent's **name,
  email, phone, designation, photo and initial** all follow.
- **Both** renders the two agents as **two SEPARATE blocks** (each its own avatar,
  name and contact) — never a merged "A & B" line. Every agent-bearing pre-built
  template has a real second agent block (`split`/`power`/`luxe` etc. show two agent
  cards; the inline-footer templates show two agent lines), and the A4 brochure shows
  a compact **two-column footer** (smaller photos/type so it stays one page).
- A single-agent listing shows the listing agent with **no control at all**.
- *(Not in this slice: free-typed custom names; persisting the choice on the
  property. The choice is per-ad, made at generation time.)*

**How it works (no new write path — pure read/render)**
- `Property::agentAdCard(?User)` → the `{id,name,email,phone,designation,initial,
  avatar}` card the client consumes (keys mirror `adData()`'s `agent_*`).
- `PropertyController::ad()` passes just two cards: `$listingAgentCard` and
  `$coAgentCard` (null unless the listing has a distinct `pp_second_agent_id`).
- `Property::adData()` / `adTemplateVars()` emit a full **`agent_2_*`** set sourced
  from `secondAgent` (empty unless co-listed) — used by the dual-agent layouts and
  the builder's Agent 2 fields.
- **Pre-built templates are server-rendered Blade**. Two reusable closures in
  `_ad-templates.blade.php` (`$agentChip` avatar block, `$agentLine` inline) render
  slot 1 (tagged `js-ad-name`/`-email`/`-desig`/`-initial`) and a slot-2 block
  (`js-ad-*-2` inside a hidden `js-ad-agent2` wrapper, each carrying its shown-display
  in `data-disp`). The generator swaps both slots' `textContent` and shows/hides the
  slot-2 wrapper per `agentMode`. **Custom templates render client-side** from
  `propertyData` (`agent_*` + `agent_2_*`) → same swap updates it and re-renders.
  `html2canvas` captures the live DOM, so downloads reflect the choice.
- **Brochure** (`PropertyBrochureService::data()/pdf()`) takes optional
  `$primary` / `$secondary` `User`s; the route `corex.properties.brochure` reads
  `?ad_agent=<id>` (in-scope agent, AgencyScope-validated, falls back to listing)
  and `?co=1` (co-brand with the listing's co-listing agent). The brochure card's
  control shares the generator's `agentMode` (`listing` | `co` | `both`) state.

**Scope / safety** — only the listing's own two agents are ever offered (built
server-side as `$listingAgentCard` / `$coAgentCard`); `?ad_agent` on the brochure
route is re-validated by `User::find` under `AgencyScope` (a foreign/unknown id
silently falls back to the listing agent). No client value is trusted to widen scope.

**Follow-ups (documented, not built here)**
- Bulk **Tools → Ad Manager** per-property agent override + a "Both" toggle (it
  already groups by listing agent and renders single-agent; the dual-agent blocks
  exist in the partial but the bulk surface has no toggle yet).
- Free-typed name override + remembering the choice on the property.

**Acceptance**
- [x] Co-listed property: generator + brochure show a Listing / Co-agent / Both
      control; switching updates the live preview and the downloaded PNG/PDF.
- [x] **Both** renders two SEPARATE agent blocks across every agent-bearing
      template (not a merged "A & B" line).
- [x] The builder Agent group has full Agent 1 + Agent 2 field sets; Agent 2
      previews a co-agent placeholder and renders empty on a single-agent listing.
- [x] Single-agent property: no control; the listing agent shows as before.
- [x] Brochure honours `?ad_agent` / `?co=1`; Both renders two agent blocks and
      stays one A4 page; bad/foreign `ad_agent` falls back to the listing agent.

## 12. The Ad render kernel — one renderer, three surfaces (AT-252)

> Status: LIVE · 2026-07-13 (Andre)

### 12.1 Why this exists

A custom template's `layout_json` is rendered on **three** surfaces: the Ad Builder
(reactive, Alpine), the single-property generator (`ad.blade.php`), and the bulk Ad
Manager (`tools/ad-manager.blade.php`). Each carried its **own copy** of the geometry,
the style computation and the value resolution. They drifted — and by the time it was
caught, the bulk manager's copy was four features behind, all of them visible on ads
that went to clients:

| Drift | What the agent actually got |
|-------|-----------------------------|
| No `shapeType` / `SHAPE_CLIPS` | A star/triangle/hexagon rendered as a rounded blob |
| No `custom_image` / `custom_video` | Uploaded media rendered as an empty box |
| No `selectedFeatures` | The features chooser was a no-op; the raw placeholder printed |
| No agent-2 empty-slot rule | A single-agent listing printed the literal words **"Agent 2 · Name"** onto the artwork |

Three renderers meant every new element property had to be hand-written three times,
and the third one was always forgotten. **The renderer is now one file.**

### 12.2 `public/js/corex-ad-render.js` — `window.CoreXAd`

The single source of truth for how an element becomes pixels. Not Vite-bundled: the ad
pages are standalone Blade documents that never load the app bundle (same reason
`corex-session-guard.js` and `docuperfect-editor.js` live in `public/js`).

| Export | Role |
|--------|------|
| `frameStyle(el, opts)` | The absolutely-positioned frame — position, size, z-index, rotation, border, `elOpacity`, `display:none` when hidden, box-shadow |
| `contentHtml(el, prop, opts)` | The element's inner HTML. **The whole point of the kernel** — one function decides what every field type looks like |
| `renderLayout(layout, prop, root, opts)` | Draws a whole `layout_json` into a DOM node (used by the two non-Alpine surfaces) |
| `textStyle` · `shapeCss` · `gradientCss` · `lineCss` · `watermarkCss` | Per-kind style computation |
| `textValue(el, prop, opts)` · `imageSrc(el, prop, opts)` | Value resolution |
| `canvasBackground(l)` · `canvasBgSolid(l)` | Canvas paint (the latter for html2canvas, which needs a flat colour) |
| `makeElement(type, x, y, z)` | A new element seeded from `FIELD_DEFAULTS` |
| `FIELDS` · `FIELD_GROUPS` · `FIELD_DEFAULTS` · `SHAPES` · `SHAPE_CLIPS` · `FONTS` · `CANVAS_PRESETS` | The catalogues |

**`opts` is how the three surfaces differ — and it is the only way they may differ:**

- `placeholders` — the **builder** designs against a property that may lack values, so an
  empty field falls back to its preview copy. The **generator must not**: an Agent-2 slot
  on a single-agent listing renders **empty**, never the words "Agent 2 · Name".
- `overrides` — the generator's "change photo" swaps, keyed by element id, so a re-render
  (agent switch, platform switch) keeps the chosen photo.
- `tagPhotos` — stamp `data-el-id` / `data-orig-src` so the overlay can target property
  photos and "reset to original" can restore them.
- `paintBackground` — the bulk manager draws into a bare div, so the kernel paints the
  canvas colour/gradient onto it.
- `showHidden` — render a hidden element anyway (unused; the escape hatch is deliberate).

The builder does **not** re-implement any of this: it binds `:style="CoreXAd.frameStyle(el)"`
and `x-html="CoreXAd.contentHtml(el, propertyData, { placeholders: true })"`, so Alpine's
reactivity drives the *same* functions the generator calls. What you design is what ships.

### 12.3 New element properties (all three surfaces, automatically)

`elOpacity` (per-element opacity) · `fontFamily` · `verticalAlign` (top/middle/bottom) ·
`hidden` · `locked` (editor-only) · and a shadow group (`shadowOn`, `shadowX`, `shadowY`,
`shadowBlur`, `shadowColor`, `shadowOpacity`).

**Where a shadow is painted depends on what carries the geometry** — this is not cosmetic,
it is the difference between a correct shadow and a wrong one:

- **text** → `text-shadow` on the text node
- **shape** (rounded/circle/pill/rect) → `box-shadow` on the *shape* node, so it follows the radius
- **line** → `box-shadow` on the bar, not the taller container
- **everything else** → `box-shadow` on the frame

**Clip-path shapes (triangle, diamond, pentagon, hexagon, star, chevron) cannot carry a
shadow at all** — `clip-path` clips the element's own box-shadow away to nothing. The
control is hidden for them (`CoreXAd.canShadow(el)`) with the reason stated in the panel.
`filter: drop-shadow()` *would* trace the silhouette, but html2canvas ignores CSS filters,
so the preview would show a shadow the downloaded PNG does not have — a WYSIWYG break.
Both `text-shadow` and `box-shadow` are html2canvas-safe. This is a deliberate, honest
limit, not an oversight.

Legacy templates are untouched: every field falls back through `def()`, so an element
saved before this change renders **byte-identically** (a legacy shape with no `shapeType`
still reads `borderRadius` as a %). Covered by the back-compat block in the JS test.

### 12.4 Typography

`resources/views/corex/properties/_ad-fonts.blade.php` is the **one** stylesheet every ad
surface loads: Figtree, Inter, Poppins, Montserrat, Oswald, Bebas Neue, Playfair Display,
Lora. A family the builder offers but an ad page never loads would silently fall back to
Figtree — the designer approves the preview and the PNG comes out in the wrong face. Adding
a family means adding it to `FONTS` in the kernel **and** to that partial; nothing else.
`AdRenderKernelTest` fails if the two lists drift apart.

Every capture path now `await document.fonts.ready` before html2canvas, or the rasteriser
snapshots the fallback face.

### 12.5 The drift guard

`tests/Feature/Properties/AdRenderKernelTest.php` (runs in `dev-check`) asserts that:
- every ad surface loads the kernel and the font sheet;
- **no ad surface re-declares** `SHAPE_CLIPS`, `IMAGE_FIELDS`, `NON_TEXT_FIELDS`,
  `FIELD_DEFAULTS` or `hexToRgba` — a bare declaration means a second renderer has been
  born, and second renderers drift;
- every font in the kernel's `FONTS` is actually loaded by `_ad-fonts`.

`tests/js/ad-render-kernel.mjs` (`node tests/js/ad-render-kernel.mjs`) exercises the render
logic itself against the shipped kernel — the four drift bugs, the new properties, legacy
back-compat, HTML escaping, and the photo-override path. 31 checks.

---

## 13. Ad Builder — the editor (AT-252)

> Status: LIVE · 2026-07-13 (Andre)

The builder was a drag-and-drop canvas with no history, no alignment, no layers and no
keyboard. Designing anything precise meant nudging numbers in the side panel. It is now a
real editor.

**History.** Full undo/redo (`Ctrl+Z` / `Ctrl+Shift+Z` / `Ctrl+Y`), 120 deep. Continuous
changes are **coalesced** — dragging a slider or holding an arrow key is one history entry,
not one per frame — via `commitCoalesced(key)`, which opens a burst on the first change and
closes it after 600ms of quiet. A drag/resize/rotate gesture snapshots on mousedown and
commits **once** on mouseup, and only if something actually changed. `Clear` is undoable, so
it no longer needs a confirm dialog.

**Snapping.** Two independent modes, both suspended while **Alt** is held:
- **Guides** — snaps the moving element's left/centre/right and top/middle/bottom edges to
  the same six lines on every *unselected* element, plus the canvas edges and centre.
  Magenta guide lines show what caught. Object guides **win over the grid** (a designer means
  the other element, not the nearest 10px), and each axis resolves independently.
- **Grid** — snap to a configurable grid (default 10px), with an optional visible overlay.
  Resizing snaps the **moving edges**, not the origin: dragging the east handle snaps the
  right edge, and leaves the untouched left edge alone.

The snap threshold is `6 / zoom`, so it stays a constant ~6px on *screen* at any zoom.

**Selection.** Multi-select by shift-click or by marquee-dragging the canvas. Selection is
tracked by element **id**, not index, so it survives restacking and undo. Every panel control
applies to the **whole selection** — restyling six labels is one action. Dragging a
multi-selection moves the element under the cursor with snapping and the rest follow by the
same delta, so relative layout is preserved.

**Align & distribute.** Left/centre/right/top/middle/bottom — to the **canvas** when one
element is selected, to the **selection's bounding box** when several are. Distribute
horizontally/vertically (3+). "Fill canvas" stretches to full bleed.

**Layers panel.** A real stack, top-of-the-ad first: drag to restack, show/hide, lock/unlock,
**delete**, click to select. `zIndex` is re-seated as a dense `1..n` run after every reorder —
which also keeps it **positive**: a negative z-index child would paint *behind* the canvas
background, because `#canvas` creates no stacking context. **Hidden = absent from the ad** —
the generator skips hidden elements, so hiding is a design decision, not just an editor
convenience. The panel is also the only way to reach an element that is completely covered by
another one, since it can't be clicked on the canvas.

**Lock means lock — including against `Del`.** Locked elements can't be dragged, marquee-caught
or nudged, and `deleteSelected()` **skips** them (it says how many it kept). A padlock that
guards a background photo against every accident *except the most destructive one* is not a
padlock. The escape hatch is the layer row's own **trash button**, which deletes that one
element regardless of its lock — an unambiguous click on one specific row is an explicit act,
not an accident. Every deletion is a single undo step.

**Handles.** 8 resize handles (corners + edges), **Shift** keeps the aspect ratio on a corner,
plus a free rotate handle (**Shift** snaps to 15°). All of it lives in a selection overlay that
is a **sibling** of the elements, not a child — an element box is `overflow:hidden` and would
clip any handle sitting on its edge (which is why the old single SE handle rendered half-cut).
Everything counter-scales by `1/zoom` so it stays a constant size on screen.

**Keyboard.** Arrows nudge 1px (Shift = 10px / one grid step) · `Ctrl+D` duplicate ·
`Ctrl+C/X/V` copy/cut/paste (repeat-paste steps, never stacks) · `Ctrl+A` select all ·
`Del` delete · `Esc` deselect · `Ctrl+S` save · `Ctrl+]`/`[` forward/backward,
`+Shift` to front/back · `Ctrl +/−/0/1` and `Ctrl+scroll` zoom · `?` opens the shortcuts
panel. Shortcuts are inert while a form field has focus.

**Zoom.** Real controls (in/out/fit/100%, `Ctrl+scroll`) replacing an auto-fit *getter* that
read `offsetWidth` on every evaluation and so never recomputed reliably on resize. Zoom is now
reactive state; "fit" re-fits on window resize and on canvas-size change.

**Safety.**
- An **unsaved-changes guard** on navigation (`beforeunload`) — the builder could previously
  lose an hour's work to a stray Back click. A dot next to the name shows unsaved state.
- **Editor chrome is excluded from the export.** `exportForMarketing()` captures `#canvas`,
  and the selection toolbar/handles/empty-state live *inside* it — they were being rasterised
  into the exported PNG. Capture now clears the selection, sets a `capturing` flag that
  suppresses every outline, and all overlays carry `data-html2canvas-ignore`.
- **Preview mode** hides all editor chrome to check the artwork alone.

**Deliberately NOT in this slice:** inline text editing on the canvas (the panel's Text field
is the single entry point); grouping; free rotation of a multi-selection (the bounding box is
axis-aligned); starting a custom template from a pre-built one (pre-builts are server-rendered
Blade, not `layout_json` — a real conversion, specced separately if wanted).

---

## 11. Files to create / modify

- `app/Http/Controllers/CoreX/PropertyAdTemplateController.php` — property-aware builder,
  creator-or-permission auth, agency-scoped reads.
- `app/Http/Controllers/CoreX/PropertyController.php` — `ad()` agency-scoped template query
  + edit-rights flag per template.
- `app/Models/PropertyAdTemplate.php` — `canBeManagedBy(User)` helper.
- `config/corex-permissions.php` — `properties.ad_templates.manage` key + role defaults.
- `resources/views/corex/properties/ad-builder.blade.php` — branding, route fix, property
  link, expanded range.
- `resources/views/corex/properties/ad.blade.php` — branding, route fix, 10 new picker
  cards + generator blocks, per-template edit-rights gating.
- `resources/views/corex/properties/_ad-templates.blade.php` — 10 new template layouts +
  branding/logo resolution.
- `.ai/CHAT_STARTER.md` — status update.

### Printable Brochure (§10c)
- `app/Services/Properties/PropertyBrochureService.php` — brochure data + dompdf PDF (new).
- `resources/views/corex/properties/_brochure.blade.php` — dompdf-safe A4 partial (new).
- `resources/views/corex/properties/brochure-pdf.blade.php` — A4 PDF wrapper (new).
- `app/Http/Controllers/CoreX/PropertyController.php` — `brochure()` method + `ad()` passes
  `$brochureData` for the picker card.
- `routes/web.php` — `corex.properties.brochure` route.
- `resources/views/corex/properties/ad.blade.php` — featured always-first brochure card.
- `app/Http/Controllers/Tools/AdManagerController.php` + `resources/views/tools/ad-manager.blade.php`
  — brochure first in the catalogue + A4 preview + per-property PDF links.
- `tests/Feature/Properties/BrochurePdfTest.php` — route/scope/data coverage (new).

### Render kernel + Ad Builder editor (§12, §13 — AT-252)
- `public/js/corex-ad-render.js` — **the** renderer; `window.CoreXAd` (new).
- `resources/views/corex/properties/_ad-fonts.blade.php` — the one ad font sheet (new).
- `resources/views/corex/properties/ad-builder.blade.php` — history, snapping, multi-select,
  align/distribute, layers, 8 handles + rotate, zoom, keyboard, preview, unsaved guard;
  renders through the kernel instead of its own copy.
- `resources/views/corex/properties/ad.blade.php` — renders through the kernel; awaits
  `document.fonts.ready` before capture.
- `resources/views/tools/ad-manager.blade.php` — renders through the kernel (fixes the four
  drift bugs in §12.1).
- `tests/Feature/Properties/AdRenderKernelTest.php` — the drift guard (new).
- `tests/js/ad-render-kernel.mjs` — render-logic checks against the shipped kernel (new).
