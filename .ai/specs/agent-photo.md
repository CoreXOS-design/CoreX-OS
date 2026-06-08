# Agent Photo — Crop & Normalize Spec

> Status: **BUILD** on `Staging` · Author: Andre (with Claude) · 2026-06-06
> Module owner: Platform / Agents
> Related: [`agency-public-api.md`](agency-public-api.md) (agent card `photo_url`), user management, agent portal.

---

## 1. What this does and why

Every agent photo must be **identical in shape and size** wherever it renders — the
admin agents grid, the agent portal, presentation/signature footers, the property-detail
sidebar, and (via the public API) the agency website's "meet the team" cards and home
spotlight. Today agents upload arbitrary rectangles, so cards crop unpredictably and the
website looks inconsistent.

The fix is two halves:

1. **A crop box at upload time** — a square (1:1) cropper with a **face-position guide**
   (circle + face oval + eye line) so the uploader places the face the same way every time.
2. **A server-side normalizer** — every stored agent photo is forced to a **1200×1200
   square WebP**, regardless of what was uploaded or whether the cropper was used (bypass,
   legacy import, API). This is the guarantee, per the Robustness Charter (prevent-or-absorb):
   the cropper *prevents* bad input; the normalizer *absorbs* anything that slips past.

### Target format (single ratio — no safe-area math)

`1200 × 1200 px`, square (1:1), WebP (sRGB), quality tuned to **≤ ~500 KB**, minimum
source **800 × 800**. A 1200×1200 is a pixel-perfect fit in the agents grid, home spotlight,
home thumbnails, and the property-detail sidebar, with zero cropping surprises. Matches the
existing 600×600 square placeholder.

---

## 2. Pillar connections

| Pillar | Reads | Writes |
|--------|-------|--------|
| **Agent** (`User`) | `agent_photo_path` / `user_documents` profile_photo | normalized square photo stored back; surfaced via `profilePhotoUrl()` and the website `AgentResource.photo_url` |

No DB migration — same `agent_photo_path` column; the file behind it is now guaranteed square.

---

## 3. Components

- **`App\Services\Images\AgentPhotoNormalizer`** (GD — available locally + prod, unlike imagick).
  `store(UploadedFile $file, int $userId, ?string $existingPath): string` →
  EXIF-orients, center-crops to square, resamples to 1200×1200, encodes WebP at a quality
  that lands ≤500 KB, writes `agents/{userId}/photo.webp` on the `public` disk, deletes the
  prior file, returns the path. Rejects (throws) sources smaller than 800×800 on the short edge.
- **`<x-agent-photo-cropper name="agent_photo" :current="$url" />`** — reusable Blade
  component. Self-contained vanilla cropper (no new npm/build dependency): pick image →
  modal with a fixed square frame, pan/zoom, and a face guide overlay (inscribed circle,
  face oval, horizontal eye-line) → "Apply" renders a 1200×1200 WebP blob into the real
  (named) file input via `DataTransfer`, updates the preview. The plain `<input type=file>`
  it replaces still submits through the normal form POST.

### Upload paths that MUST route through the normalizer
1. `Admin\UserManagementController::store()` (create agent)
2. `Admin\UserManagementController::update()` (edit agent)
3. `Admin\UserManagementController::uploadFiles()` (files tab)
4. `Agent\AgentPortalController::uploadDocument()` (`document_type=photo`)

---

## 4. UI placement

- **Admin → Users → create/edit → Files card**: the Agent Photo field uses the cropper component.
- **Agent portal → Profile → photo upload**: the camera button opens the same cropper.

Both already exist on the page — no new navigation entry needed (non-negotiable #2 satisfied:
the control is in place the same day).

---

## 5. Acceptance criteria

- [ ] Uploading a non-square (e.g. 2000×1000) image stores a **1200×1200** square WebP.
- [ ] The cropper enforces 1:1, shows the face guide, and submits a 1200×1200 WebP.
- [ ] A source smaller than 800×800 is rejected with a clear validation message.
- [ ] Stored file is WebP and ≤ ~500 KB at quality tuning.
- [ ] Old photo file is deleted when replaced (no orphans; non-negotiable #1 = soft delete is
      for records — physical replaced media is cleaned).
- [ ] `profilePhotoUrl()` and the website `AgentResource.photo_url` return the square photo.
- [ ] Works in admin create, admin edit, files tab, and agent portal.
- [ ] `dev-check.ps1` passes with 0 new failures; a feature test covers the normalize-to-square guarantee.

---

## 6. Files

**Create**
- `app/Services/Images/AgentPhotoNormalizer.php`
- `resources/views/components/agent-photo-cropper.blade.php`
- `tests/Feature/Agents/AgentPhotoNormalizerTest.php`

**Modify**
- `app/Http/Controllers/Admin/UserManagementController.php` (3 paths → normalizer)
- `app/Http/Controllers/Agent/AgentPortalController.php` (photo path → normalizer)
- `resources/views/admin/users/create-edit.blade.php` (use cropper component)
- `resources/views/agent/portal.blade.php` (use cropper component)
