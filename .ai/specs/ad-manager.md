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
