# Ad Manager — Module Spec

> Status: ACTIVE — build in flight on `AT-7-Finish-ad-manager-for-CoreX`
> Last updated: 2026-08-02 (Johan) — §14 numeric feature display format + icon
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
is the single entry point); free rotation of a multi-selection (the bounding box is
axis-aligned); starting a custom template from a pre-built one (pre-builts are server-rendered
Blade, not `layout_json` — a real conversion, specced separately if wanted).
**Grouping was deferred here — built in §13.1.**

---

## 13.1 Element grouping — select and move together

> Status: LIVE · 2026-08-02

**What/why.** Building a multi-element composite (e.g. a price badge + its
background shape, or an agent photo + name + phone as one unit) meant re-selecting
every piece by hand — shift-click each one, every time — with no way to move the
composite as a single thing by default. A group is a **persisted multi-select**,
not a new geometry concept: elements sharing an `el.groupId` behave as one unit
everywhere a selection is FORMED, and the existing multi-select drag/nudge/rotate/
lock/delete/duplicate logic then applies completely unchanged, because all of it
already operates on whatever is in `selIds` — grouping only had to change how
`selIds` gets FORMED, nothing about what happens once it's formed.

**Selection routes through `groupMembers(id)`/`expandToGroups(ids)`** in all three
places a selection is formed — `elMouseDown()` (plain click and shift-click),
the marquee-drag mouseup, and `selectFromLayers()` — so a plain click on ANY
member selects the whole group, a marquee that catches even one member expands to
the whole group, and shift-click toggles the whole group on/off as a unit (never a
single member peeled off an otherwise-intact group).

**Group** (toolbar button, `Ctrl G`) bundles the current 2+-element selection into
one new group, overwriting any `groupId` its members already had — groups don't
nest; combining pieces of two existing groups (via shift-click) flattens them into
one new group. **Ungroup** (`Ctrl Shift G`) clears `groupId` from the current
selection — since selecting any one member already expands to the whole group,
this always ungroups the WHOLE thing, never a partial slice. The toolbar shows a
single toggling button (mirrors the existing Lock/Unlock pattern) gated by
`selIsGroup` — a getter that also requires the selection to be the group's ENTIRE
membership, not a shift-clicked subset, so the icon and tooltip never promise an
Ungroup that would actually leave part of the group behind.

**Duplicate/copy-paste remap group IDs** (`_remapGroups()`) — a duplicated or
pasted group becomes its OWN new group, never silently re-merged with the
original, which is what would happen if `groupId` were copied verbatim onto the
clone. A small link icon in the Layers panel marks a grouped row (discoverability
— nothing else in the panel would otherwise reveal that a row belongs to a group).

**Top-bar "Group…" picker (an alternative to shift-click-first).** A dedicated
`Group…` button in the top toolbar (`toggleGroupPick()`) enters an explicit
picking mode instead of requiring the elements to already be selected: click any
element (canvas OR Layers panel — both route through the same toggle) to add or
remove it from the pending set, marquee-drag to add several at once (the marquee
is force-additive while picking, never a replace), then **Confirm** (shown in the
header, green accent) to group, or **Cancel**/`Esc` to back out with nothing
changed. Reuses `selIds` as the pending set — no parallel data structure — so
every touched element gets the SAME per-element `.selected` outline it always
gets; a lock doesn't block a pick (grouping never moves anything). While picking,
the floating per-element toolbar and resize handles are suspended
(`!groupPickMode` added to their `x-if`) so a stray click can't drag, resize or
delete something mid-pick — picking is a protected, single-purpose mode.

**`groupId` is builder-only**, the same treatment `locked` already gets (§12.3):
the kernel's `makeElement()` seeds it as `null` so the schema stays symmetric
across all three ad surfaces, but neither `frameStyle()` nor `contentHtml()` ever
reads it — the generator and bulk Ad Manager render a grouped element exactly like
an ungrouped one. A legacy element (saved before this change, no `groupId` key at
all) is simply ungrouped — `!el.groupId` is falsy for `undefined` — so nothing
about an existing template changes.

**Design-panel ungroup — everything, or a single item.** Clicking any member
always selects the WHOLE group (by design, above) — so there was previously no
way to target just one member for removal. When the current selection
`selIsGroup`, the Design tab now shows a **"Grouped — N elements"** block: a
list of every member (`selGroupMembers`) with its own small ✕ button —
`ungroupOne(el)` clears `groupId` on JUST that element, leaving the rest of the
group intact — plus an **"Ungroup All"** button (the existing
`ungroupSelected()`, same as `Ctrl Shift G`/the toolbar toggle). If removing one
member leaves a single element behind, `ungroupOne()` clears its `groupId` too
— a "group" of one is meaningless leftover state, not a real group.

**Deliberately not in this slice:** a whole-group bounding-box RESIZE that scales
every member proportionally — resizing still targets one element at a time, even
inside a group; free rotation of a group (inherits the existing multi-select
rotation limit, §13); nested groups (a group inside a group — grouping always
flattens to one level).

**Acceptance criteria**
- [x] Clicking any one member of a group selects every member; dragging any one
      moves the whole group together, preserving relative layout.
- [x] Group requires 2+ selected; Ungroup only fires on a selection that is a
      whole existing group.
- [x] Duplicating or pasting a group produces an independent new group, not a
      silent merge back into the original.
- [x] A template saved before this change opens with every element ungrouped.
- [x] Grouping has zero effect on the rendered ad — same PNG/HTML on the
      generator and bulk Ad Manager whether an element is grouped or not.
- [x] The top-bar Group… button enters a picking mode; clicking/marqueeing toggles
      membership without dragging, resizing or deleting anything; Confirm requires
      2+ picked; Cancel/Esc discards the pick with zero mutation.
- [x] The Design panel, when a group is selected, offers both "Ungroup All" and a
      per-member "remove just this one" control; removing down to one member
      dissolves that leftover member's group too.

---

## 14. Numeric feature fields — display format + icon (Beds/Baths/Garages/Parking)

> Status: LIVE · 2026-08-02

**What/why.** The Beds/Baths/Garages builder fields only ever rendered a bare
number (`"3"`). An agent building a custom template had no way to show
`"3 Bedrooms"` with correct singular/plural, or to pair the value with a
real-estate icon — both standard on the printable brochure (§10c) but missing
from the customisable Ad Builder. This closes that gap and adds a fourth
numeric field, **Parking** (previously brochure-only), to the builder catalogue
for consistency.

**Data model.** No new table/column — everything lives in the existing
`layout_json.elements[]` shape (§3). Three new per-element properties, valid
only on `field ∈ {beds, baths, garages, parking}`:

| Property | Values | Default |
|---|---|---|
| `numberFormat` | `'number'` \| `'label'` | `'number'` (bare number — unchanged legacy behaviour) |
| `icon` | a key into the kernel's `ICONS` map, or `null` | `null` (no icon) |
| `iconSize` | px, falls back to `fontSize` when unset | `null` |

A legacy element (saved before this change) carries none of these keys —
`el.numberFormat === 'label'` is false for `undefined`, so it renders exactly
as before. Icon default is **off**, including for brand-new elements the
agent hasn't touched, so nothing changes until the agent opts in.

**Singular/plural.** `"label"` format renders `"{number} {word}"` with the
word chosen by count — Bedroom/Bedrooms, Bathroom/Bathrooms, Garage/Garages —
mirroring the brochure's existing rule exactly (`_brochure.blade.php`). A real
half (e.g. 1.5 baths) is kept and pluralises. **Parking never pluralises**
("Parking" at any count) — same convention the brochure already uses. Handles
the full input space per BUILD_STANDARD.md §2: empty on the **generator**
renders nothing (never `"undefined Bedrooms"`); empty on the **builder**
(`opts.placeholders`) falls back to the field's preview, still formatted;
non-numeric garbage falls back to the raw string rather than throwing.

**Icon set.** A curated 12-icon real-estate set (Bed, Bath, Garage/Car,
Parking, Size, House, Location, Key, Pool, Garden, Door, Price Tag) — single-
path/simple-shape SVGs, `viewBox="0 0 24 24"`, `fill="currentColor"` so an
icon always follows the element's own text colour (no separate icon-colour
control). The Bed/Bath/Garage/Parking icons are the SAME paths already
proven in the printable brochure. Icon is purely decorative/optional — an
agent can pair any icon with any of the four fields (e.g. a Key icon on
Garages), it is not locked to a "matching" icon.

**Parking as a new field.** `Property::spaceCount(string $type): int` is the
single derivation (sum of `spaces_json` entries of that `type`) — previously
duplicated as a private method on `PropertyBrochureService`; that now
delegates to the Property method so the brochure and the Ad Manager can never
compute two different Parking counts for the same listing.
`Property::adData()['parking']` is `''` when the count is 0 (no data to show,
same "hide the zero" convention as the brochure's specs bar), else the count
as a string — matching how `beds`/`baths`/`garages` are already emitted.

**Rendering (one kernel, three surfaces — §12).** `contentHtml()`'s shared
text-field branch prefixes an inline `<span>` icon (sized to `iconSize` or
`fontSize`, `margin-right` scaled to size) before the value span — it is not
a separate canvas element, so it always moves and aligns with the text as one
unit. Because all three ad surfaces (Builder, single-property generator, bulk
Ad Manager) render through `corex-ad-render.js`, the format/icon choice
appears identically on all three the moment it's saved — no per-surface work.

**Builder UI.** A "Display as" select (Number only / Number + label) and a
6-column icon grid (mirrors the existing Shape picker's visual-swatch
pattern, §13) appear in the property panel only when the selected element's
field is one of the four numeric fields. Selecting an icon shows an "Icon
size" px input. Panel: `ad-builder.blade.php`, right after the frame/opacity
controls, before the Features chooser block.

**Acceptance criteria**
- [x] A Beds/Baths/Garages/Parking element defaults to a bare number — no
      visual change to any template saved before this change.
- [x] "Number + label" pluralises correctly at 0, 1, 2+, and a real half
      (baths); Parking never pluralises.
- [x] An icon renders inline, sized and coloured correctly, on Builder,
      generator and bulk Ad Manager alike (one kernel, no drift).
- [x] Empty value on the generator renders nothing; on the builder falls
      back to the preview; non-numeric input never throws.
- [x] `Property::spaceCount()` is the only Parking-counting code path;
      `PropertyBrochureService` and `adData()` agree on every listing.
- [x] `tests/js/ad-render-kernel.mjs` and `AdRenderKernelTest.php` pass.

### 14.1 "Garages / Parking" combined field — for bulk ad runs across mixed listings

> Status: LIVE · 2026-08-02

**What/why.** Bulk **Tools → Ad Manager** applies ONE template across MANY
properties in a single run (§10b). A template built with a plain **Garages**
field prints blank (or "0 Garages" in label mode) on any listing that only
has parking bays, and a **Parking** field does the mirror-image wrong thing
on a listing with a garage — there is no single template that reads correctly
across a mixed batch. New field **`garages_or_parking`** (catalogue label
"Garages / Parking") resolves **per property, at render time**: garages if
the listing has any (`> 0`), else parking, else hidden — never both, never a
"0" value. The two existing standalone **Garages** and **Parking** fields are
unchanged and still exist for a template that must always read one specific
source.

**Resolution (`resolveGaragesOrParking(prop)`)** — garages wins whenever it
is a real positive count; "no garage" covers both an explicit `"0"` and an
absent/empty value the same way, because a bulk run cannot assume every
listing's garage column was ever populated. Falls to parking only when
garages resolves to nothing. Neither present → `null`, which renders **empty**
on the generator (the same "hide the zero" convention as the Parking field
and the printable brochure's specs bar) and the label-format word for
whichever source won ("1 Garage" / "3 Garages" / "2 Parking" — Parking still
never pluralises).

**No new backend data.** The resolver reads the SAME `prop.garages` /
`prop.parking` values `Property::adData()` already emits — no new PHP, no new
column. It is a value-selection rule at the rendering layer, not a new data
source.

**Builder UI — nothing to add.** `garages_or_parking` is a member of
`NUMERIC_FEATURE_FIELDS` (§14), so it automatically gets the same "Display
as" + icon-picker panel as Beds/Baths/Garages/Parking with zero additional
Blade code — the panel is gated on membership in that list, not on a
per-field template block.

**Builder preview (no real property yet).** Falls back to the field's
`preview` value read as a **garages** count/word ("2 Garages") — "defaults to
garages" holds even before a real listing is attached.

**Acceptance criteria**
- [x] A listing with a garage shows the garages count/word; a listing with
      only parking bays shows the parking count/word from the SAME template
      element — no per-property re-editing needed for a bulk run.
- [x] A listing with neither renders empty, never "0 Garages".
- [x] Works identically in bare-number and "Number + label" mode.
- [x] Carries an icon exactly like the other three numeric fields (no special
      icon-switching — the icon is the agent's own choice, independent of
      which source resolved).
- [x] `tests/js/ad-render-kernel.mjs` covers both-present / zero-explicit /
      absent / neither / builder-placeholder / icon paths.

---

## 14.2 "Size / Land Size" combined field — vacant land has no floor size

> Status: LIVE · 2026-08-02

**What/why.** Same shape of problem as §14.1, reported the same way: a fixed
"Size m²" field shows the property's floor size — but **vacant land has no
floor size at all**, only a stand/erf size. Before the placeholder-leak fix
(§17, found investigating this exact report), a vacant-land listing didn't
even render blank — it showed the field's design-time **preview text
("450 m²") as if it were the property's real size**, on the actual generated
ad. New field **`size_or_land`** resolves per property: floor size
(`size_m2`) if the property has one (`> 0`), else the land/erf size
(`erf_size_m2`), else hidden — never both, matching `garages_or_parking`'s
exact resolution shape.

**Data.** `Property::adData()` already exposed `size_m2` **pre-formatted**
(`"450 M²"` string, via PHP `number_format()`) for the existing plain "Size
m²" field — changing that would break every template already using it. So
this adds two NEW raw (unformatted) keys instead: `floor_size_m2` (mirrors
`size_m2`'s own value, just not pre-formatted) and `land_size_m2`
(`erf_size_m2`, previously not exposed to the Ad Manager at all). The
combined field does its OWN formatting client-side (`formatSizeNumber()`,
matching PHP's `number_format()` thousands-separator convention exactly, so
"1,250 M²" reads identically whichever field shows it) so both candidates
can be formatted identically regardless of which one wins.

**The "Erf" suffix is deliberate, not decoration.** Showing land size with
no indication it ISN'T the floor size would be exactly the kind of ambiguous
real-estate marketing copy CoreX exists to prevent (a buyer could easily read
"450 M²" as house size when it's actually the stand size). Floor size shows
bare (`"450 M²"`, matching the existing plain field's convention exactly);
land size always appends `" Erf"` (`"2,754 M² Erf"`) — the standard SA term
for a stand/plot, per `CLAUDE.md`'s South African context.

**No icon/"Number + label" panel** — unlike Beds/Baths/Garages/Parking,
size has no singular/plural concept, so `size_or_land` is deliberately NOT a
member of `NUMERIC_FEATURE_FIELDS` (that set specifically gates the icon +
display-format panel, §14) — it needs no extra per-element configuration
beyond the position/style every element already gets.

**Acceptance criteria**
- [x] A listing with a floor size shows it, bare, exactly like the existing
      plain Size m² field.
- [x] Vacant land (no floor size, real erf size) shows the erf size with the
      "Erf" suffix — verified against a real property (id 1290) via Tinker.
- [x] A listing with neither renders empty, never a fabricated size.
- [x] Thousands-separator formatting matches the plain field's PHP
      `number_format()` output exactly.
- [x] `tests/js/ad-render-kernel.mjs` covers both-present / zero-explicit /
      absent / neither / builder-placeholder / thousands-separator paths.

---

## 17. Placeholder-leak fix — a missing field must never fabricate data onto a real ad

> Status: LIVE · 2026-08-02 — found investigating the §14.2 report, fixed the same day

**What/why.** While diagnosing "vacant land shows a fabricated 450 m²",
traced `textValue()`'s generic fallback (every text field that isn't one of
the special-cased ones above) and found: **`return el.preview || el.label ||
'';` ran unconditionally, ignoring `opts.placeholders` entirely.** The
Agent-2 fields and the numeric feature fields (§14) already had their own
`if (!opts.placeholders) return '';` guard — but the generic path every OTHER
field falls through (`reference`, `address`, `agent_phone`, `agency_name`,
`website`, `size_m2` before §14.2, and any custom/decorative field with no
matching property key) did not. **This meant any of those fields, on any
property missing that data, rendered its DESIGN-TIME PREVIEW TEXT on the
actual generated ad as if it were real** — a property with no captured
floor size showed "450 m²", one with no reference showed "REF 12345", one
with a blank agent phone column would have shown "082 000 0000" — all
fabricated, all indistinguishable from real data to whoever received the ad.

**Fix.** The generic fallback now carries the exact same guard the
special-cased fields already had: `if (!opts.placeholders) return '';` before
falling back to `el.preview || el.label`. The Builder still shows every
field's preview copy when the real value is missing (so designing against an
incomplete property still looks right); the generator and bulk Ad Manager now
render nothing rather than fiction. This generalises a rule that was already
correct for two special cases to the one function that governs all of them —
"fix the class, not the instance" (BUILD_STANDARD §6).

**Acceptance criteria**
- [x] Every generic text field with no real data renders empty on the
      generator, never its design-time preview.
- [x] The Ad Builder is completely unaffected — still shows every preview
      when designing against an incomplete property.
- [x] A field WITH real data is completely unaffected either way.
- [x] `tests/js/ad-render-kernel.mjs` covers the generic path directly
      (`reference`, `address`, `agent_phone`) plus the untouched real-data case.

---

## 15. Agent Image — renamed from "Avatar", plus a shape picker

> Status: LIVE · 2026-08-02

**What/why.** The catalogue label read **"Agent 1 · Avatar"** / **"Agent 2 ·
Avatar"** — renamed to **"Agent Image"** to match the rest of CoreX's copy
(`profilePhotoUrl()`, the My Portal profile page, etc. all say "photo"/"image",
never "avatar"). This is a **display-label change only** — the underlying field
keys (`agent_avatar`, `agent_2_avatar`) are unchanged, so nothing about a saved
template's data shape moved. A pre-existing element's OWN saved `label` (which
may have been hand-edited, or is simply the old catalogue text baked in at
creation time) is untouched — only the catalogue text a fresh drag reads from
changes; per-element labels have always been editor-set copy, never a live
lookup.

**Shape picker.** The Agent Image previously offered only a plain numeric
"Border Radius (px)" field — in practice used to fake a circle by setting it
larger than half the box. It now gets the **same shape picker as the
decorative Shape element** (§13): a 10-shape visual grid (Rectangle, Rounded,
Circle, Pill, Triangle, Diamond, Pentagon, Hexagon, Star, Chevron), reusing
`CoreXAd.SHAPES` and `CoreXAd.shapeCss()` for the swatch previews verbatim — no
new picker UI to design or maintain, and no separate "matching" rule (an agent
can put their photo in a star cutout if they want).

**Mechanism (`el.shapeType`, same property name the Shape element already
uses).** `frameStyle()` gains `avatarShapeCss(shapeType, borderRadius)`:
- A clip-path shape (Triangle…Chevron) sets `clip-path` from the SAME
  `SHAPE_CLIPS` map the decorative Shape element uses, and zeroes
  `border-radius` (the two would fight otherwise).
- `circle` → `border-radius:50%` (a true ellipse on a non-square box, the
  conventional profile-photo crop — NOT the old oversized-px hack).
- `pill` → `border-radius:9999px` (stays a stadium shape even on a wide box,
  distinct from `circle`).
- `rounded` → `el.borderRadius`px, a REAL adjustable corner radius (previously
  the only option was the oversized-hack number, which only ever looked like a
  circle no matter what value was entered).
- `rectangle` → `border-radius:0`.
The frame — not the `<img>` — carries the clip/radius (`overflow:hidden` on the
frame does the actual visual clipping, same pattern the Shape element already
uses for its own children); `imgTag()` needed zero changes.

**Backward compatible by construction.** An element with no `shapeType` at all
— every Agent Image saved before this change — skips `avatarShapeCss()`
entirely and falls through to the EXISTING `el.borderRadius || 0` handling, so
a legacy avatar (saved with the old `borderRadius:50` default) renders
byte-identical to before. A brand-new element defaults to `shapeType: 'circle'`
(seeded in `FIELD_DEFAULTS`), preserving today's circular default look — this
is a pure superset, not a behaviour change for anyone who does nothing.

**Deliberately not built (dropped mid-conversation on request):** a
"backgroundless" option — either a transparent fill behind the shape mask, or
suppressing the placeholder box shown when no photo is uploaded. Neither is
built; the existing tinted-box placeholder behaviour (`emptyPhotoHtml()`) is
unchanged.

**Acceptance criteria**
- [x] Catalogue reads "Agent 1 · Image" / "Agent 2 · Image", not "Avatar".
- [x] A legacy Agent Image (no `shapeType`) renders exactly as it did before —
      same circular crop, same `borderRadius` value.
- [x] A brand-new Agent Image defaults to a circle (no visual regression for
      an agent who never touches the new picker).
- [x] Every one of the 10 shapes, including clip-path shapes, can mask an
      Agent Image, identically for Agent 1 and Agent 2.
- [x] `tests/js/ad-render-kernel.mjs` covers the rename, the default, every
      shape branch, and the legacy fallback.

---

## 15.1 "Remove background" — client-side cutout for a plain-backdrop photo

> Status: LIVE · 2026-08-02

**What/why.** The request's own example: an agent's headshot on a **white studio
backdrop** should lose that backdrop and show only the person, so the photo sits
directly on the ad's own background/colour instead of carrying a visible white
box around it. This is scoped to the Agent Image element (`agent_avatar`/
`agent_2_avatar`) only, same as the shape picker (§15) — not a general image
tool.

**This is NOT AI/ML person segmentation** — no model, no third-party API (no
`remove.bg`-style cost or network dependency, no photo ever leaves the browser).
It is a **flood-fill colour cutout**, the same class of technique behind
PowerPoint's "Remove Background": sample the backdrop colour, then flood-fill
transparency inward from seed pixels that colour-match (within a tolerance) —
stopping wherever the colour changes sharply. A pixel only turns transparent if
it is both colour-matched AND reachable from a seed without crossing that edge,
so **a white shirt collar in the middle of the photo survives** (it's not
connected to any seed) while an actual solid/near-solid backdrop is removed.
Works best on the case it's built for — a plain, evenly-lit, roughly-solid-
colour backdrop (the common studio-headshot shape) — not a photo with a
busy/textured/gradient background, which is a materially harder problem this
deliberately does not attempt.

**Fixed 2026-08-02, in two rounds — a real photo (Retha's) exposed both ends
of this trade-off in production, same day.**

*Round 1 — the shirt got swept away with the backdrop.* The original version
sampled the backdrop colour from all FOUR image corners and seeded the flood
fill from the ENTIRE frame border, including the bottom row. A headshot crop
routinely has the subject's shoulders/shirt reaching the bottom (and sometimes
lower-side) edge of the frame — when that garment is a similar tone to the
backdrop (a white shirt on a white/light backdrop is the common case, not an
edge case), seeding from the bottom edge let the fill flow straight from the
backdrop into the garment. Fixed: **seeds no longer include the bottom row**,
side-column seeding is **restricted to the upper half of the frame**
(`sideSeedLimit = h * 0.5`), and **corner sampling uses only the TOP-LEFT/
TOP-RIGHT corners** (bottom corners are often clothing, and averaging them in
had pulled the sampled "backdrop colour" toward the clothing colour). Tolerance
tightened 40→26 now the sample is cleaner.

*Round 1 ALSO added a hard floor* (`noRemoveBelow = h * 0.82`, "never erase
the bottom ~18% of the frame, full stop") as a backstop, reasoning that
restricting seeds alone doesn't stop the fill reaching a garment via
propagation through legitimately-connected background above the seed line.

*Round 2 — the floor overcorrected: "left ~20% at the bottom."* Reported the
same day. The floor is BLIND — it has no idea whether that band is actually
backdrop or clothing, and Retha's actual photo has genuine visible backdrop
below the shoulders (not every crop has the garment reaching the literal
bottom pixel), which the floor now refused to touch. **The floor is removed.**
Propagation is unrestricted by y-position once a pixel is seeded — a
background pixel below the seed line still connects upward to more background
regardless of its row — so genuine connected backdrop is correctly cleared
all the way down again. What actually stops a real garment from being eaten is
what a colour algorithm can legitimately reason about: not seeding from the
ambiguous bottom edge, sampling the backdrop colour from a clean spot, and a
tolerance tight enough to catch a real (even modest) colour difference.

**The remaining, accepted limit — stated, not silently patched around:** a
garment truly colour-IDENTICAL to the backdrop, with zero edge between them,
gives no signal any colour-based algorithm can use, and will still be swept in
too. A blind position-based floor could paper over that one synthetic
worst-case, but at the cost of breaking the much more common real case (a real
photo where the garment DOES differ from the backdrop, even subtly, and
genuine backdrop legitimately extends low in the frame) — precisely the
regression Retha's photo demonstrated. `tests/js/ad-render-kernel.mjs` covers
all three shapes explicitly: a garment with a real colour difference survives
even at the bottom edge; genuine low-frame backdrop is removed; a
colour-identical garment is knowingly, explicitly swept in (asserted, not
discovered by surprise).

**Mechanism.** A checkbox — "Remove background" — in the Agent Image panel sets
`el.removeBackground`. The kernel's `imgTag()` adds `onload="window.CoreXAd.
stripBackground(this)"` to the `<img>` when that's on; `stripBackground()`
downscales the photo onto an offscreen `<canvas>` (capped at 500px on the
longest side, so a full-resolution profile photo is never slow), runs the
flood-fill (`_floodFillTransparent`/`_cornerColor`), and swaps the `<img>`'s
`src` to the resulting transparent-PNG data URL. **One `onload` attribute is
the ONLY per-surface change** — it fires identically whether the `<img>` was
inserted via Alpine's reactive `x-html` (Builder) or `renderLayout()`'s
imperative `innerHTML` (generator/bulk manager), so nothing else needed
touching in any of the three surfaces.

**Same-origin only** (relies on the existing `Property::adSafeImageUrl()`
resolution, §10e, that already makes html2canvas work) — a genuinely
cross-origin photo makes the canvas "tainted", `getImageData()` throws, and the
function resolves to `null`: the original photo keeps showing rather than the
ad breaking. Errors of any kind degrade the same way — this never crashes an ad.

**Cached per source URL, re-processed only once even across a bulk run.** A
`_bgRemovalCache` keyed by the ORIGINAL `<img>.src` stores the in-flight/settled
Promise, so if the same agent's photo appears across many properties in a bulk
Ad Manager run, only the FIRST occurrence actually runs the flood-fill; every
other `<img>` for that same URL gets the cached result instantly. Re-loading the
SAME processed data URL (the swap itself triggers a second `load` event) is
guarded by `img.dataset.bgStripped`, so it can't loop.

**Capture-timing safety.** Both capture paths — the single-property generator's
`_capture()` and the bulk Ad Manager's `downloadRow()` — now `await
CoreXAd.backgroundRemovalsSettled()` before calling `html2canvas`, alongside
their existing fixed-delay buffers (80ms/60ms — precedent already in this
codebase for the identical font-loading race, §12.4-adjacent). This is a
best-effort guarantee, not a formally provable one — genuinely slow processing
(a very large source photo before downscaling, or a slow device) could in
theory still race a capture that fires immediately; flagged here rather than
silently assumed solved.

**Toggling off reverts cleanly.** `imgTag()` always starts from `imageSrc()`'s
resolved ORIGINAL photo URL — turning `removeBackground` off simply stops
adding the `onload` hook on the next render, so the element shows the
untouched original photo. Nothing is ever mutated on the `Property`/`User`
model or `prop` data; the swap only ever touches the live `<img>` DOM node.

*Round 3 (2026-08-02, same day) — enclosed holes and hard edges (property
3080, agent Elize Reichel).* Investigated first, empirically, before touching
any code: two hard-edged white discs sat at the ear/earring positions, and a
pale block sat near the collar/shoulder, all left completely untouched by
rounds 1–2 — because they're genuine backdrop colour that the border-seeded
flood fill can never reach in the first place. A hoop earring encircles a
disc of visible backdrop; a lapel gap does the same — both are fully enclosed
pockets, connected to no border, so no seed ever reaches them. Separately, the
cutout's edge was hard/aliased (binary alpha, 0 or 255, nothing between) —
correct per the flood-fill's own logic but visibly "pasted on" compared to a
studio matte cut.

Fixed with two new passes, run after the existing flood fill, inside the SAME
`_processBackgroundRemoval()` pipeline:

- **`_fillEnclosedHoles()`** — a second connected-components pass over
  backdrop-coloured pixels the first pass left opaque. A pocket is filled
  ONLY if (a) it touches NO frame border anywhere along its connected
  boundary — anything that does is exactly the garment case rounds 1/2
  already protect and is left strictly alone — and (b) it is at least
  `HOLE_MIN_PX = 30` pixels, measured at the real 500px working resolution.
  (b) exists because an eye catch-light is ALSO an enclosed near-white pocket
  within the same colour tolerance, and had to be ruled out empirically before
  shipping this: measured on a real photo, catch-lights ran 19px and under
  while real holes (the earring, the collar gap) ran 46–851px — a clean
  separation with margin on both sides, not a threshold picked by feel.
  Verified against Retha's and Kym's photos too (previously-clean cutouts,
  not just Elize's) — no new artifacts introduced.
- **`_featherAlpha()`** — a 1px-radius (3×3) box blur applied to the ALPHA
  CHANNEL ONLY, never the colour channels, so a hard cut-out boundary reads as
  a soft anti-aliased line instead of a binary jump — most visible on fine
  hair strands. Colour-channel blurring was deliberately avoided: it would
  bleed backdrop colour into edge pixels, the opposite of the goal.

The collar/shoulder pale block is only PARTIALLY fixed by this round: the
portion of it that's fully enclosed is now correctly filled, but the portion
that touches the frame border is — correctly — left alone, for the same
reason a real garment must be. That's the accepted boundary of a
border-connectivity technique, not an oversight; see the existing "remaining,
accepted limit" note above, which this round doesn't change.

**Deliberately not built:** any adjustable tolerance/threshold control (a fixed
value tuned for a light, roughly-solid backdrop); manual background-colour
picking (always auto-sampled from the corners); a "transparent fill behind the
shape mask" or "no placeholder box when the photo is missing" option (both
explicitly dropped from scope on request, see §15's "Deliberately not built").

**Acceptance criteria**
- [x] A plain white/near-white backdrop is removed; the person is preserved.
- [x] A white patch fully inside the subject (not touching the image border)
      is NOT removed — proves this is a border-connectivity flood fill, not a
      naive global colour threshold.
- [x] A garment with a real (even modest) colour difference from the backdrop
      is NOT removed even touching the bottom edge — round 1's regression
      (Retha's shirt), reproduced realistically (not a same-colour edge case).
- [x] Genuine backdrop connected all the way to the bottom of the frame IS
      removed — round 2's regression ("left ~20% at the bottom"), fixed by
      removing the blind height floor round 1 added.
- [x] A garment truly colour-identical to the backdrop is knowingly swept in
      too — the accepted, stated limit of a colour-based cutout, asserted
      explicitly rather than left as a surprise.
- [x] Toggling the checkbox off reverts to the untouched original photo.
- [x] A cross-origin/tainted photo degrades to showing the original — never a
      broken/blank image, never a thrown error visible to the user.
- [x] The SAME source photo across many bulk-run properties is only processed
      once (cache hit for every subsequent occurrence).
- [x] A fully-enclosed backdrop-coloured pocket (e.g. through a hoop earring)
      at least `HOLE_MIN_PX` is filled — round 3's fix for the ear-disc
      artifact.
- [x] A small enclosed near-white pocket (e.g. an eye catch-light) below
      `HOLE_MIN_PX` is NOT filled — proves the size floor protects genuine
      facial highlights, not just an assumption.
- [x] A backdrop-coloured patch that touches the frame border is never
      touched by the enclosed-holes pass, no matter its size — the
      garment-safety guarantee holds through round 3, not just rounds 1–2.
- [x] The cutout edge is feathered (intermediate alpha at the boundary), not a
      hard binary 0/255 jump — round 3's fix for aliased/hard edges.
- [x] `tests/js/ad-render-kernel.mjs` covers the flood-fill algorithm directly
      (synthetic pixel buffers — no real Canvas/Image needed), the
      onload-hook emission/omission, the enclosed-holes pass (filled vs. not
      filled vs. border-touching), and edge feathering.

---

## 16. Generator fixes — the "zoom" glitch, a canvas-size mismatch, and a picker preview

> Status: LIVE · 2026-08-02

**Reported by Johan against a real custom template** ("For Sale Template",
`property_ad_templates.id=1`) — clicking Generate did "a weird zoom in", the
Agent Image looked stretched, and elements (the agency logo named specifically)
appeared out of position. Inspected the actual saved `layout_json` via Tinker
rather than guessing.

**Confirmed and fixed — the zoom glitch.** `_capture()` briefly sets
`#ad-scale-wrapper`'s CSS `transform` to `none` so html2canvas can grab the
design at its true native pixel size. But that wrapper sits inside a box sized
to the SCALED-DOWN preview with `overflow:hidden` — removing the transform
made the full-size canvas render behind that small clip window, so the user
saw the preview snap to a cropped, zoomed-in corner of the design for the
whole capture window (fonts + `backgroundRemovalsSettled()` + the 80ms buffer
+ the actual html2canvas run), then snap back. Fixed with a **capture veil** —
a `data-html2canvas-ignore` overlay (`capturingPreview`) covering the preview
with a spinner for exactly that window, wrapped in `try/finally` so it (and
the transform) are ALWAYS restored even if the capture throws — previously an
error mid-capture could leave the preview permanently un-scaled until some
unrelated reactive trigger happened to fix it.

**Confirmed and fixed — a latent canvas-size bug (general fix, not proven to
be THIS template's specific cause).** `get cfg()` returned
`platforms[canvasPreset]` — the STANDARD size for whatever preset name is
stored — instead of the template's own saved `canvasW`/`canvasH`, whenever
`canvasPreset` happened to match a real platform key. `canvasPreset` is just
the label of the last preset button clicked in the Ad Builder; a designer can
pick a preset THEN resize the canvas, leaving the preset name stale relative
to the actual dimensions every element was positioned against — sizing the
LIVE preview/canvas wrong while `_capture()` (which always read
`canvasW`/`canvasH` directly) rendered correctly, so any mismatch would only
ever surface at generate time. Fixed: `cfg` now always trusts the template's
own `canvasW`/`canvasH`, never the preset. (This template's own
`canvasPreset` is the literal string `"custom"` — not a real platform key — so
its OWN `cfg` was already resolving correctly via the old fallback; this fix
protects every OTHER custom template that started from a real preset and was
then resized, which this bug would have silently mis-sized.)

**§16.1 — the Agent Image "stretch", root-caused and fixed.** A follow-up
investigation (`MODE:INVESTIGATION` — no code changed until Johan confirmed
the diagnosis, per the Conductor & Lane Intake Protocol) traced every line
touching this element's geometry in both the live preview and the
`html2canvas` capture and found **no code path in this app that treats them
differently** — same DOM,
same `cfg`, same `frameStyle()`/`imgTag()` output either way (§12's whole
premise — one kernel, no drift — held up under scrutiny). The remaining
candidate: **`html2canvas` 1.4.1 has long-documented gaps in its CSS
`object-fit` support** — it can rasterise an `<img>` at its raw intrinsic
aspect ratio stretched to fill the box instead of correctly cropping it the
way the live browser (correctly) does, exactly the "enlarged/stretched, breaks
past the frame" symptom reported. Every image field uses precisely the
pattern this class of `html2canvas` bug targets: `overflow:hidden` on the
frame (`frameStyle()`) + `width:100%;height:100%;object-fit:cover|contain` on
the child `<img>` (`imgTag()`).

**Fix: pre-bake the crop, don't fight the rasteriser.** Same principle
already established for the printable brochure's location pin (§10c — "an
inline SVG's point gets clipped in dompdf; a raster sizes predictably") —
when a rendering engine has a known gap in a CSS feature, don't rely on it;
pre-compute the correct pixels yourself. New kernel function
`prepareImagesForCapture(root)` walks every `<img>` inside the capture root,
and for each one whose `object-fit` is `cover` or `contain` (`fill` is left
alone — it's supposed to stretch), draws the SAME crop `object-fit` would
onto an offscreen `<canvas>` sized to the element's own box (geometry
factored into a pure, unit-tested `objectFitRect(fitVal, boxW, boxH, natW,
natH)` — cover picks `Math.max` scale and crops, contain picks `Math.min`
and letterboxes transparently), then swaps the `<img>`'s `src` to that
pre-cropped PNG data URL. By the time `html2canvas` captures it, the image
genuinely **is** the right shape — there is nothing left for its object-fit
handling to get wrong, regardless of the exact nature of the underlying gap.
`img.decode()` is awaited before capture so the swapped src is actually
painted-ready; a same-origin/CORS failure on any one image is caught and that
image is simply left as-is (never a thrown error, never breaks the ad).

**A restore function is mandatory and always called in `finally`** — the live
preview, the "change photo" overlay, and "reset to original" all still read
the ORIGINAL `<img src>`/`data-orig-src` outside the capture window; the swap
is real but strictly temporary, exactly mirroring how the capture veil
(above) restores the wrapper's transform.

**Fixed the class, not the instance (BUILD_STANDARD §6).** The same
`html2canvas` gap can hit ANY image field, and a grep of every `html2canvas(`
call site found **three**, not one — the single-property generator
(`ad.blade.php` `_capture()`, already covered above), the Ad Builder's own
"Export for Marketing" (`ad-builder.blade.php` `capture()`), and the bulk Ad
Manager (`tools/ad-manager.blade.php` `downloadRow()`). All three now call
`prepareImagesForCapture()` immediately before their `html2canvas()` call.
The Ad Builder's `capture()` was ALSO missing the `backgroundRemovalsSettled()`
await every other capture path already had (§15.1) — a sibling gap, fixed
alongside it.

**§16.2 — the dead platform-selector for a custom template, fixed.** The
Facebook/Instagram/Story/WhatsApp/Custom buttons (`ad.blade.php`) stayed fully
clickable and visually "active" for a custom template even though `cfg`'s
custom-template branch never reads `platform` at all — clicking them is a
complete no-op, yet "Facebook 1200×628" showing highlighted while the actual
export renders the template's own (possibly totally different, e.g. square)
saved size is exactly what read as "the size I picked isn't what exported."
The platform row is now hidden entirely (`x-if="template !== 'custom'"`) for
a custom template, replaced with a plain, honest, read-only line — the
template's actual `cfg.w`×`cfg.h` — plus a direct **"Change in Ad Builder →"**
link (new `_customTemplateId`, set alongside `_customLayout` in
`selectCustomTemplate()`) to the one place that size can actually be changed.

**Built — custom template picker previews (the separate feature ask).**
Custom (agency-built) template cards showed only a letter avatar
(`tpl.name.charAt(0)`) where pre-built cards already show a live, real-data
thumbnail (server-rendered Blade, scaled via `transform:scale()`). Custom
templates can't use that — `layout_json` is client-side JSON, not a Blade
partial — so each card mounts `CoreXAd.renderLayout()` directly (`x-init`,
using the page's own `propertyData` + `{placeholders:true}` so a template
with no chosen agent/photo still shows sensible placeholder copy, same as the
Ad Builder).

**Fixed same day — the first version rendered noticeably smaller than the
pre-built previews.** It reused the OLD compact list-row card (`.custom-tpl-
card`/`.custom-tpl-thumb`, a 100×52px icon-sized box next to the name) rather
than the pre-built cards' big-thumbnail grid layout (`.tpl-card`/`.tpl-thumb`,
`width:100%; aspect-ratio:1200/628`, in the same `minmax(300px,1fr)` grid).
Custom template cards now use the IDENTICAL `.tpl-card`/`.tpl-thumb` markup
and grid as pre-built cards, so every card in the picker is the same size.
The harder part: pre-built thumbnails are always designed at exactly
1200×628 and rescaled to the card's actual responsive width by the existing
`fitThumbs()` (`.tpl-thumb-inner` scaled by `clientWidth/1200`, re-run on
resize/search/step-change) — a custom template can be ANY canvas size, so
`customThumbStyle(tpl)` first **contain-fits it, centred, into that SAME
1200×628 logical reference frame** (not a smaller ad-hoc box), and
`fitThumbs()` then scales that whole frame down to the card's real width
exactly as it already does for pre-built cards — no new resize/observer code
needed, since the custom cards use the exact same `.tpl-thumb`/`.tpl-thumb-
inner` class names `fitThumbs()` already queries. Dead CSS from the old
compact layout (`.custom-tpl-card`, `.custom-tpl-thumb`, `.custom-tpl-badge`
— the last one already unused before this) removed.

**Acceptance criteria**
- [x] Clicking Generate/Download/Export never visibly flashes a cropped,
      zoomed-in view of the design.
- [x] A capture error always restores the preview's transform and clears the
      veil — never leaves it stuck un-scaled.
- [x] A custom template whose `canvasPreset` matches a real platform name but
      whose actual `canvasW`/`canvasH` differs renders at ITS OWN size, live,
      not the preset's.
- [x] Every custom template card in the picker shows a live, correctly-
      proportioned preview of the actual design, not a letter avatar.
- [x] A custom template's picker preview is the SAME on-screen size as a
      pre-built template's — both use `.tpl-card`/`.tpl-thumb` and the same
      `fitThumbs()` responsive scaling, only the reference-frame contain-fit
      differs.
- [x] Every `html2canvas()` call site (single-property generator, Ad Builder
      export, bulk Ad Manager) pre-bakes the object-fit crop before capturing,
      via `CoreXAd.prepareImagesForCapture()`; each restores the original
      `<img src>` in `finally` so the live preview/"change photo"/"reset to
      original" paths are never affected.
- [x] `objectFitRect()`'s cover/contain geometry is unit-tested directly
      (`tests/js/ad-render-kernel.mjs`) — crop vs letterbox, centring on both
      axes, matching-aspect no-op, and the zero-size/zero-natural-size guard.
- [x] A custom template's platform-size row is hidden (not just inert) and
      replaced with the template's real size + a link to change it in the Ad
      Builder.

---

## 18. Property-type template variants — one template, different designs per property type

> Status: LIVE · 2026-08-02

**What/why.** Johan's own example: a template built for a house needs
Bedrooms/Bathrooms; the same template used on a vacant land listing has no
floor size at all — only a stand/erf size. Until now the only fix was a
second, separate template kept in sync by hand. This gives a saved template
a "Design for" picker: a **Default** design (used by any property type
without one of its own) plus, per property type, an optional **fully
independent alternate design** — its own canvas AND its own elements —
created by cloning the current Default and then editing it separately.
Most valuable for the bulk Ad Manager and the single-property generator,
where the SAME template runs across a mixed portfolio (houses, vacant land,
apartments, …) without an agent hand-picking a different template per
listing.

**This replaced an earlier, narrower attempt shipped the same day** (a
per-element `visibleFor` checklist toggling individual elements on/off per
type). Johan's actual ask was a full alternate design per type, not
element-by-element show/hide — corrected before that version reached wide
use; see the git history for the superseded commit. The mechanism below is
what shipped.

**Data model — `layout_json.variants`.** A template's `layout_json` keeps its
existing top-level shape (`canvasW`, `canvasH`, `canvasBg`, `elements`, …) as
the **Default** design — unchanged, so every template saved before this
feature needs no migration and no template loses anything. A NEW sibling key,
`variants`, is a map keyed by the EXACT property-type name (the same string
the property's own "Property Type" dropdown uses — Settings-driven per
agency, never a hardcoded taxonomy) to a full alternate design of the same
shape (`canvasW`/`canvasH`/`canvasBg`/`canvasBgMode`/`canvasBgFrom`/
`canvasBgTo`/`canvasBgAngle`/`canvasPreset`/`elements`). A property type with
no entry in `variants` simply uses the Default.

**Resolution — one function, both runtimes.** `CoreXAd.resolveTemplateLayout
(layoutJson, propertyTypeRaw)` (kernel, pure, unit-tested) and
`PropertyAdTemplate::resolvedLayoutFor(?string $propertyTypeRaw)` (PHP model
method, same cases unit-tested) both do the identical thing: case-
insensitive/trimmed match of the property's type against `variants` keys;
match → that variant's design; no match (blank type, no variants at all, or
a type nobody made custom) → the Default. Two implementations exist only
because resolution has to happen in two different runtimes — the bulk Ad
Manager resolves server-side (PHP, one property's real classified type at a
time, inside `AdManagerController::generate()`'s per-property loop, verified
via Tinker to make two properties of different types in the SAME batch
render genuinely different designs — 1000×500 custom canvas + a
`custom_text` element for a vacant-land property vs. the Default 1200×628
`beds` element for a house, one call, one template); the single-property
generator and Ad Builder picker thumbnails resolve client-side (JS, from
`propertyData.property_type_raw`). Never a broken/empty render over missing
classification data — no match always falls back to the Default design.

**New data key — `Property::adData()['property_type_raw']`.** The exact,
untransformed `property_type` column value (e.g. `"Vacant Land / Plot"`),
kept deliberately separate from the existing `'property_type'` key (which
uppercases for on-ad DISPLAY, §10's "Type" element) so matching never
depends on that display string's formatting choices.

**Ad Builder — the "Design for" bar.** A pill row above the canvas: "Default"
(always first, always exists) plus one pill per the agency's own active
Property Type. Clicking a type that's still using Default clones the CURRENT
Default design into a brand-new independent variant and switches straight to
editing it; clicking an already-custom type (badged "Custom") just switches
to it; a "Revert to Default" link deletes the active variant and falls back.
Only `elements`/`canvasW`/etc are ever "live" — whichever design is
currently active — while every OTHER design sits parked in plain JS state
(`variants` for custom ones, `_defaultLayout` for the Default while a
variant is being edited) until switched back to. This re-uses every existing
drag/resize/undo/group/layers mechanic completely unchanged: none of that
code has any idea a variant switch ever happened, it just keeps operating on
`elements`/`canvasW` as it always did. Undo history resets on switch — each
design gets its own fresh stack, since undoing a drag on one design has no
meaning on a completely different one. A loaded real property (`?property=`)
auto-opens on ITS OWN design if the template already has a matching variant,
so the Ad Builder previews exactly what that property's ad will look like.

Verified by direct behavioural simulation (the pack/unpack logic isn't
unit-testable in the current jsdom-less harness, being tightly coupled to
the full Alpine component — the same limitation `stripBackground()`'s DOM-
touching wrapper already had in §15.1, worked around there by testing only
its pure algorithmic core): create Default content → make a Vacant Land
variant (confirmed it starts as an exact clone) → edit the variant → switch
to Default (confirmed unaffected by the variant edit) → switch back to the
variant (confirmed the edit persisted) → read the save payload while the
variant is active (confirmed BOTH the Default and the variant are present,
correctly separated) → revert (confirmed the variant is deleted and the
Default is restored). Every step passed before this shipped.

**Deliberately not built this round:** a higher-level "property category"
taxonomy above the agency's own configured type names (e.g. bucketing
Farm/Commercial under one label) — the agency's own exact type names are
more precise and need no new taxonomy invented or kept in sync; canvas-size
mismatch warnings when a variant's dimensions differ from the Default's
(they're free to differ — the clone is just a starting point) — not
reported as an issue and adds a UI affordance nobody asked for; any change
to the pre-built (non-custom) ad templates — those are hardcoded Blade
markup per design, not `layout_json`-driven, and were never part of "the
template builder" this was asked for.

**Acceptance criteria**
- [x] A template with no `variants` key at all (every template saved before
      this feature) resolves to its own unchanged design for every property
      type — no migration needed.
- [x] A property whose exact (case-insensitive/trimmed) type matches a
      variant resolves to THAT variant's canvas AND elements, not the
      Default's.
- [x] A property whose type has no matching variant — including a blank/
      unclassified type — resolves to the Default design.
- [x] Two properties of different types, in the SAME bulk-generate batch,
      using the SAME selected template, resolve to genuinely different
      designs (verified via Tinker through the real `generate()` endpoint,
      not just the model method in isolation).
- [x] In the Ad Builder, giving a property type its own design starts as an
      exact clone of the current Default, then edits independently of it —
      editing the variant never mutates the Default, and vice versa.
- [x] Switching between Default and a variant, back and forth, always shows
      each one's own correct, current content — never stale, never bleeding
      into the other.
- [x] The save payload includes the Default (top level, unchanged shape) and
      every custom variant (`variants`), including whichever one is
      currently being edited at save time.
- [x] Reverting a variant deletes it and falls back to Default, without
      resurrecting the deleted content.
- [x] A real property loaded via `?property=` auto-opens the Ad Builder on
      its own matching variant, if one exists.
- [x] `tests/js/ad-render-kernel.mjs` and
      `tests/Unit/Properties/PropertyAdTemplateVariantTest.php` cover
      `resolveTemplateLayout()`/`resolvedLayoutFor()` identically: exact
      match, case/whitespace tolerance, no-match fallback, blank/missing
      type fallback, no-`variants`-key fallback.

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

### Numeric feature display format + icon (§14)
- `public/js/corex-ad-render.js` — `NUMERIC_FEATURE_FIELDS`, `FEATURE_LABELS`, `ICONS`,
  `ICON_LIST`, `parking` field/defaults, label formatting in `textValue()`, icon rendering
  in `contentHtml()`/`iconHtml()`.
- `app/Models/Property.php` — `spaceCount()` (new, shared with the brochure); `adData()`
  gains the `parking` key.
- `app/Services/Properties/PropertyBrochureService.php` — delegates Parking counting to
  `Property::spaceCount()` instead of its own private copy.
- `resources/views/corex/properties/ad-builder.blade.php` — "Display as" + icon-picker
  panel for the four numeric fields.
- `tests/js/ad-render-kernel.mjs` — label/pluralisation/icon/legacy-back-compat checks.

### "Garages / Parking" combined field (§14.1)
- `public/js/corex-ad-render.js` — `garages_or_parking` field/defaults/catalogue entry,
  `resolveGaragesOrParking()`, its `textValue()` branch. No backend/Blade changes — reuses
  existing `prop.garages`/`prop.parking` and the existing numeric-feature panel.
- `tests/js/ad-render-kernel.mjs` — both-present / zero / absent / neither / builder-preview
  / icon coverage.

### "Size / Land Size" combined field + placeholder-leak fix (§14.2, §17)
- `public/js/corex-ad-render.js` — `size_or_land` field/defaults/catalogue entry,
  `resolveSizeOrLand()`, `formatSizeNumber()`, its `textValue()` branch; the generic
  `textValue()` fallback now guards on `opts.placeholders` (the actual bug fix, affects
  every field that falls through to it, not just size).
- `app/Models/Property.php` — `adData()` gains `floor_size_m2`/`land_size_m2` (raw,
  unformatted) alongside the existing pre-formatted `size_m2`.
- `tests/js/ad-render-kernel.mjs` — both-present / zero / absent / neither / builder-preview
  / thousands-separator coverage for the new field; generic-fallback placeholder-leak
  coverage (`reference`, `address`, `agent_phone`, plus the untouched real-data case).

### Element grouping (§13.1)
- `public/js/corex-ad-render.js` — `makeElement()` seeds `groupId: null` (builder-only,
  unread by `frameStyle()`/`contentHtml()`).
- `resources/views/corex/properties/ad-builder.blade.php` — `groupMembers()`,
  `expandToGroups()`, `selIsGroup`, `groupSelected()`, `ungroupSelected()`, `_remapGroups()`;
  group-aware `elMouseDown()`/marquee/`selectFromLayers()`; toolbar Group/Ungroup button;
  `Ctrl G`/`Ctrl Shift G`; shortcuts panel entry; Layers panel group indicator; top-bar
  `Group…` picker (`groupPickMode`, `toggleGroupPick()`/`cancelGroupPick()`/
  `confirmGroupPick()`) + header Confirm/Cancel bar; Design-panel "Grouped — N
  elements" block (`selGroupMembers` getter, `ungroupOne()`) with a per-member
  ✕ plus an "Ungroup All" button.

### Agent Image rename + shape picker (§15)
- `public/js/corex-ad-render.js` — catalogue labels; `isAgentAvatarField()`,
  `avatarShapeCss()`; `frameStyle()` applies the shape mask to Agent Image elements;
  `FIELD_DEFAULTS` seeds `shapeType: 'circle'`, `borderRadius: 16` for both agent avatar
  fields.
- `resources/views/corex/properties/ad-builder.blade.php` — shape-picker panel block
  (reuses the existing Shape-element grid markup) for `agent_avatar`/`agent_2_avatar`;
  suppresses the plain Border Radius input for those two fields only.
- `tests/js/ad-render-kernel.mjs` — rename, default-circle, every shape branch, legacy
  fallback coverage.

### "Remove background" cutout (§15.1)
- `public/js/corex-ad-render.js` — `stripBackground()`, `_processBackgroundRemoval()`,
  `_floodFillTransparent()`, `_cornerColor()`, `backgroundRemovalsSettled()`,
  `_bgRemovalCache`; `imgTag()` adds the onload hook; `makeElement()` seeds
  `removeBackground: false`; round 3 adds `_fillEnclosedHoles()` (`HOLE_MIN_PX = 30`)
  and `_featherAlpha()`, both called from `_processBackgroundRemoval()` right after
  `_floodFillTransparent()`, and both exported on `CoreXAd`.
- `resources/views/corex/properties/ad-builder.blade.php` — "Remove background" checkbox
  in the Agent Image panel.
- `resources/views/corex/properties/ad.blade.php` — `_capture()` awaits
  `backgroundRemovalsSettled()` before `html2canvas`.
- `resources/views/tools/ad-manager.blade.php` — `downloadRow()` awaits
  `backgroundRemovalsSettled()` before `html2canvas`.
- `tests/js/ad-render-kernel.mjs` — flood-fill algorithm (synthetic pixel buffers),
  onload-hook emission/omission, enclosed-holes fill/no-fill/border-touching cases,
  edge feathering.

### Generator fixes + picker previews (§16)
- `resources/views/corex/properties/ad.blade.php` — `capturingPreview` + capture veil
  (`try/finally` in `_capture()`); `get cfg()` always trusts the custom template's own
  `canvasW`/`canvasH`; `customThumbStyle(tpl)` + live `CoreXAd.renderLayout()` mount per
  custom-template picker card, replacing the letter-avatar thumb; `@keyframes ad-spin`.

### Agent Image "stretch" root-cause fix + dead platform-selector fix (§16.1, §16.2)
- `public/js/corex-ad-render.js` — `objectFitRect()` (pure crop geometry, unit-tested),
  `prepareImagesForCapture()` (pre-bakes the crop onto an offscreen canvas, swaps `<img
  src>`, returns a restore function).
- `resources/views/corex/properties/ad.blade.php` — `_capture()` calls
  `prepareImagesForCapture()` before `html2canvas()`; platform-selector row hidden for
  `template === 'custom'`, replaced with a read-only size + "Change in Ad Builder →" link;
  new `_customTemplateId` (set in `selectCustomTemplate()`, cleared in `selectTemplate()`).
- `resources/views/corex/properties/ad-builder.blade.php` — `capture()` gains the SAME
  `backgroundRemovalsSettled()` await and `prepareImagesForCapture()` call the other two
  capture paths already had (a sibling gap, BUILD_STANDARD §6).
- `resources/views/tools/ad-manager.blade.php` — `downloadRow()` calls
  `prepareImagesForCapture()` before `html2canvas()`.
- `tests/js/ad-render-kernel.mjs` — `objectFitRect()` cover/contain/matching-aspect/
  zero-size coverage.

### Property-type template variants (§18)
- `app/Models/Property.php` — `adData()` gains `'property_type_raw'` (exact,
  untransformed `property_type`, kept separate from the display-uppercased
  `'property_type'` key already there).
- `public/js/corex-ad-render.js` — `resolveTemplateLayout(layoutJson,
  propertyTypeRaw)` (pure, unit-tested), exported on `CoreXAd`; `renderLayout()`
  itself is unchanged (reverted to its pre-§18 form — resolution happens
  BEFORE calling it, not inside it).
- `app/Models/PropertyAdTemplate.php` — `resolvedLayoutFor(?string
  $propertyTypeRaw)`, the PHP mirror, unit-tested identically
  (`tests/Unit/Properties/PropertyAdTemplateVariantTest.php`).
- `app/Http/Controllers/Tools/AdManagerController.php` — `generate()` resolves
  each property's OWN layout INSIDE the per-property loop (was resolved once,
  outside the loop, shared by the whole batch); `$row['cw']`/`['ch']` now come
  from that property's resolved layout too.
- `app/Http/Controllers/CoreX/PropertyAdTemplateController.php` — `builder()`
  fetches the agency's active `property_setting_items` (group
  `property_type`) and passes `$propertyTypeOptions` to the view.
- `resources/views/corex/properties/ad-builder.blade.php` — the "Design for"
  pill bar (`#varbar`); `variants`/`activeVariantType`/`_defaultLayout` state;
  `_packActiveVariant()`/`_unpackVariant()`/`switchVariant()`/
  `makeCustomVariant()`/`revertVariant()`; new `layoutJsonFull` getter feeds
  `save()` (was building the payload inline from top-level fields); `init()`
  auto-opens a loaded real property's matching variant.
- `resources/views/corex/properties/ad.blade.php` — `selectCustomTemplate()`
  and the picker's thumbnail/`customThumbStyle()`/element-count line all
  resolve via a new `resolvedTemplateLayout(tpl)` helper instead of reading
  `tpl.layout_json` directly.
- `resources/views/tools/ad-manager.blade.php` — picker thumbnails (`loadPreviews()`)
  resolve via a new `thumbLayout(t)` helper (feeds both the render call and
  the thumbnail's aspect-ratio sizing); the bulk-generated rows themselves need
  no client-side resolution — the server already resolves them per property.
- `tests/js/ad-render-kernel.mjs` — `resolveTemplateLayout()` coverage: exact
  match, case/whitespace tolerance, a variant's own canvas background coming
  along with its elements, no-match fallback, blank/missing-type fallback,
  no-`variants`-key fallback.
- `tests/Unit/Properties/PropertyAdTemplateVariantTest.php` — same cases,
  server-side.
