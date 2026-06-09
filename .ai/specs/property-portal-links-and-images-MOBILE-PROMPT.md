# Mobile prompt — Property portal links + viewable images

Paste this into Claude Code in the CoreX mobile-app repo. The backend (CoreX OS
on Laravel) already ships everything described below — **do not change the
backend.** Build the app side only.

---

## What you're building

Two things on the **Property** screens:

1. **Portal links.** On a property's Overview, show tappable links to every
   public place the listing lives: the **company website**, **Property24**, and
   **Private Property** — plus any future portal, with **zero app changes**
   needed when a new one is added. The app already shows Property24; you're
   generalising that to a portal-agnostic list driven by the API.

2. **Viewable images.** Property images must load and display in the app, and
   the property's **first/cover image must be the same one the web shows**.

---

## Backend API (already live)

Base URL: same host + auth (Sanctum bearer token) as the existing mobile
property endpoints, e.g. `https://corex.hfcoastal.co.za/api/v1`.
(The legacy un-versioned `/api/mobile/...` paths still work too.)

### 1. Portal links — dedicated endpoint

```
GET /api/v1/mobile/properties/{id}/portal-links
```
Auth required. 403 if the property is outside the user's visibility scope.

**200 response:**
```json
{
  "property_id": 482,
  "portal_links": [
    {
      "portal": "website",
      "label":  "Company Website",
      "status": "live",
      "url":    "http://91.99.130.85:1050/property/3-bed-house-in-shelly-beach-482",
      "ref":    "EXT-482"
    },
    {
      "portal": "property24",
      "label":  "Property24",
      "status": "live",
      "url":    "https://www.property24.com/for-sale/shelly-beach/margate/kwazulu-natal/9123/115847291",
      "ref":    "115847291"
    },
    {
      "portal": "private_property",
      "label":  "Private Property",
      "status": "not_published",
      "url":    null,
      "ref":    null
    }
  ]
}
```

**Contract — read this carefully, it's the whole point:**

- `portal_links` is an **ordered array**. Render it by iterating — **never
  hardcode portal names.** When the backend adds a portal (e.g. Gumtree,
  Seeff network), a new object simply appears in this array and your UI shows
  it automatically. Do not switch on `portal` to decide whether to render.
- `portal`  — stable machine key (`website`, `property24`, `private_property`,
  …). Use it only for analytics / picking an icon, **not** for "should I show
  this row".
- `label`   — human text to display ("Property24"). Always present.
- `status`  — `"live"` or `"not_published"`.
  - `live` → the listing has a working public page. `url` is non-null. Show
    the row as a tappable "Open" / "View on {label}" button.
  - `not_published` → the listing isn't live there yet. `url` is null. Show the
    row greyed / "Not published" with no tap action (or hide it — your call,
    but **prefer showing it greyed** so the agent sees where they could still
    syndicate).
- `url`     — open in an external browser / in-app browser. Non-null **only**
  when `status === "live"`. Never construct portal URLs in the app; always use
  this value.
- `ref`     — the portal's listing reference (for display / support). May be null.

### 2. Portal links — also embedded in Overview

```
GET /api/v1/mobile/properties/{id}/overview
```
The Overview response now contains **both**:

- `portal_links` — the full canonical array described above (live + not_published).
  **Use this.**
- `placements`   — legacy key, **live portals only**, same object shape. Kept so
  the current build doesn't break. Migrate your UI to `portal_links` and drop
  your dependence on `placements`.

> Migration note: the old `placements` had the company website with `"url": null`
> (it was never openable) and used `portal` keys like `"website:7"`. The new
> `portal_links` gives the website a **real, openable URL** and a stable
> `"website"` key. Switch to `portal_links`.

You don't need a separate call if you're already loading the Overview — just read
`portal_links` off it. Use the dedicated endpoint only if you want links without
the rest of the Overview payload.

### 3. Images — what changed

All image URLs returned by the mobile property API are now **absolute**
(`https://host/storage/...`), so `<Image source={{ uri }}>` works directly —
no base-URL prepending in the app. Remove any client-side logic that prefixes a
host onto image paths; just use the URL as-is.

Affected fields (all already existed; values are now absolute + consistent):

- `GET /mobile/properties` → each item's `thumbnail`
- `GET /mobile/properties/{id}` → `thumbnail`, `gallery_images[]`,
  `gallery_categories.categories.{TagName}[]`
- `GET /mobile/properties/{id}/overview` → `cover_image`
- `POST /mobile/properties/{id}/images` → `url` (the uploaded image)

**Cover / first image = same as web.** `thumbnail` (list + detail) and
`cover_image` (overview) are now the property's first image across **all**
image groups in the same order the website uses
(dawn → noon → dusk → gallery → other). If a property looked different in the
app vs the web before, it will now match. Use `cover_image` / `thumbnail` as the
single hero image; use `gallery_images[]` for the full swipeable gallery.

---

## App work

### A. Portal links UI (Property Overview)

1. Read `portal_links` from the Overview response (or call the dedicated
   `/portal-links` endpoint).
2. Render a **"Where it's live" / "Portals"** section: one row per array entry.
   - Row = portal icon (pick by `portal` key, with a generic fallback for
     unknown keys) + `label` + a trailing action.
   - `status === "live"` → trailing "Open ↗" button that opens `url` in the
     device browser / in-app web view.
   - `status === "not_published"` → greyed row, label + "Not published", no tap.
3. **Future-proof:** the list MUST handle a `portal` value you've never seen.
   Unknown key → generic link icon + the server's `label`. Do not crash, do not
   filter it out.
4. If every entry is `not_published`, show a small empty-ish state ("Not yet
   published to any portal").

### B. Images

1. Use `cover_image` (overview) / `thumbnail` (list & detail) as the hero image.
2. Use `gallery_images[]` for the gallery viewer; `gallery_categories` if you
   group by room/tag.
3. URLs are absolute — feed them straight to your image component. Delete any
   host-prefixing helper.
4. After uploading via `POST .../images`, use the returned absolute `url` to
   show the new image immediately.

### C. Icons (suggested mapping)

| `portal` key       | icon            |
|--------------------|-----------------|
| `website`          | globe / home    |
| `property24`       | P24 logo / house|
| `private_property` | PP logo / key   |
| *(anything else)*  | generic link ↗  |

Keep this as a lookup with a default — never an exhaustive switch that throws
on miss.

---

## Acceptance criteria

- [ ] Property Overview shows a portal section listing **website, Property24,
      and Private Property** (when present in `portal_links`), each opening its
      `url` for `live` entries.
- [ ] A portal with `status: "not_published"` renders greyed (no tap), not hidden
      in a way that loses it.
- [ ] Adding a hypothetical 4th portal object to the API response makes a 4th row
      appear with **no app code change** (test by mocking the response).
- [ ] The company website link **opens** (it is no longer null/unopenable).
- [ ] Property images load on a real device (not just simulator) — absolute URLs.
- [ ] The cover/first image shown in the app **matches the web** for the same
      property.
- [ ] No client-side host-prefixing of image URLs remains in the codebase.

---

## Notes / gotchas

- Don't build portal URLs in the app. The backend composes them (slugs, refs,
  sandbox vs prod). You only ever open the `url` you're given.
- `placements` (Overview) is deprecated in favour of `portal_links`. Don't add
  new code against `placements`.
- `status` is the source of truth for "is this openable", not the presence of
  `url` alone — but in practice `live ⇔ url != null`. Treat `url == null` as
  not-openable defensively.
