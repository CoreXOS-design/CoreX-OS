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
