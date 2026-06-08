# Contact Testimonials — Spec

> Status: **DRAFT — build in progress on `Staging`**
> Author: Andre (drafted with Claude) · 2026-06-06
> Module owner: Contacts / Platform-Integrations
> Related specs: [`agency-public-api.md`](agency-public-api.md), [`contacts.md`](contacts.md), [`multi-tenancy.md`](multi-tenancy.md), [`corex-domain-events-spec.md`](corex-domain-events-spec.md)

---

## 1. What this feature does and why

Agents collect glowing feedback from happy clients all the time — verbally, over WhatsApp, in emails. Today that gold is lost. This feature lets an agent **capture a testimonial directly on the Contact** that gave it, and lets a principal/admin **publish chosen testimonials to the agency's public website** with one tick — where they appear as social proof.

It is a first-class extension of the existing **Agency Public API** (the same one-API-many-keys + webhook transport that already powers listings and agents on agency websites). A published testimonial is pulled by the website via `GET /api/v1/website/testimonials` and pushed in real time via `testimonial.*` webhooks. Unpublish = it is removed from the website, both via the pull (it stops appearing) and a `testimonial.removed` webhook (the site pulls it down immediately).

### The two-step model (decided with the user)

1. **Capture (agents)** — on a Contact's **"Notes & Testimonials"** tab (the existing Notes tab, renamed). Any user with `access_contacts` can record a testimonial: the quote, a 1–5 star rating, and an editable public **display name** (defaults to the contact's full name — POPIA-friendly so e.g. "Andre R." can be shown instead of a full name). Capturing does **not** publish.
2. **Publish (principals/admins)** — in **Company Settings → Website → Testimonials**, every captured testimonial appears in a short/preview form. Clicking one expands the full text; a **publish tick box sends it to the website** (or unticks to remove it). Gated by the new `testimonials.publish` permission.

This separation means agents surface raw testimonials while the agency curates what goes public — no unvetted content reaches the website.

---

## 2. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Contact** | A testimonial is always attached to the Contact who gave it (`contact_id`). Renders on the contact's "Notes & Testimonials" tab. | New `contact_testimonials` rows enrich the Contact with relationship/social-proof history. |
| **Agent** (`User`) | Records who captured (`user_id`) and who published (`published_by_user_id`). | — |
| **Property / Deal** | — (v1 not linked; a future v2 may link a testimonial to the deal it came from). | — |

### Three layers of "is this live?" (inherited from agency-public-api §2)

A testimonial reaches a website only when **all three** are true:

1. **Agency master switch** — `agencies.website_enabled`. Kill-switch for the whole integration.
2. **Per-key active state** — the website's API key is active (not revoked/expired) and has the `testimonials:read` (pull) / `webhooks:receive` (push) scopes.
3. **Per-record publish flag** — `contact_testimonials.published = true` (the Settings tick box). Default **false** — nothing is public until explicitly published (prevent-or-absorb: no accidental PII egress).

---

## 3. Data model / migrations

### 3.1 `contact_testimonials` (new table)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `agency_id` | FK → agencies, **NOT NULL** | tenant — model uses `BelongsToAgency` |
| `contact_id` | FK → contacts, cascadeOnDelete | the Contact who gave the testimonial (pillar link) |
| `user_id` | FK → users, nullOnDelete, nullable | who captured it |
| `agent_id` | FK → users, nullOnDelete, nullable | **the agent the testimonial is about** — defaults to the capturing agent, selectable on capture. Lets the website show + link the agent and list an agent's testimonials. |
| `body` | text, NOT NULL | the full testimonial quote |
| `display_name` | string(150), NOT NULL | public author name shown on the website; defaults to the contact's full name, editable per testimonial |
| `rating` | unsignedTinyInteger, nullable | 1–5 stars (optional) |
| `published` | boolean, default **false** | the "send to website" tick (Settings) |
| `published_at` | timestamp, nullable | when first/last published |
| `published_by_user_id` | FK → users, nullOnDelete, nullable | who published |
| timestamps + **softDeletes** | | non-negotiable #1 — no hard deletes |

Indexes: `(agency_id, published)` for the public API query; `contact_id` for the tab.

### 3.2 New scope on `agency_api_keys`

No schema change. Add a scope constant to the `AgencyApiKey` model:

```
SCOPE_TESTIMONIALS_READ = 'testimonials:read'  → 'Read published testimonials'
```

Existing keys are unaffected; granting the new scope is done per key in the API Access panel (the scopes checkbox list reads `AgencyApiKey::SCOPES`).

---

## 4. Public API (read / pull)

Under the existing `/api/v1/website/*` group (`auth:agency-api` + `website.live` + `throttle:website-api`), add:

| Method | URI | Name | Scope |
|--------|-----|------|-------|
| GET | `/api/v1/website/testimonials` | `v1.website.testimonials.index` | `testimonials:read` |
| GET | `/api/v1/website/testimonials/{id}` | `v1.website.testimonials.show` | `testimonials:read` |

Returns only `published = true` testimonials of the key's agency, newest first. Shape via `App\Http\Resources\WebsiteApi\TestimonialResource`:

```json
{ "id": 12, "author": "Andre R.", "rating": 5, "body": "…", "date": "2026-06-06",
  "agent_id": 7, "agent": { "id": 7, "name": "Thandi Mbeki" } }
```

`agent_id` + `agent` let the website show the agent on the testimonial and link to `/agents/{agent_id}`.

**Agent-scoped queries (the agent-profile use case):**
- `GET /api/v1/website/testimonials?agent_id=7` — only that agent's published testimonials.
- `GET /api/v1/website/listings?agent_id=7` — only that agent's syndicated listings. Each listing already carries `agent.id` for the reverse link. (Filter added to the existing listings endpoint — see `agency-public-api.md` §5.)

So an agent's website profile makes two scoped calls (`listings?agent_id=` + `testimonials?agent_id=`) to render every property and testimonial connected to that agent.

Auto-appears under the **Website** section at `/admin/api` (non-negotiable #7 — route is named + under `api/`).

---

## 5. Webhooks (push) — the "API that sends it to the website"

Mirrors the agent webhook chain exactly (agency-public-api §6).

- **Event:** `App\Events\Website\TestimonialVisibilityChanged($testimonial, $action)` extends `AbstractDomainEvent`. `webhookEvent()` → `testimonial.published` / `testimonial.updated` / `testimonial.removed`.
- **Emitter:** `App\Observers\ContactTestimonialObserver` (failure-isolated; never breaks a save):
  - `created` + published → `published` (rare — capture doesn't publish).
  - `updated` + `published` flipped on → `published`; flipped off → `removed`.
  - `updated` + still published + body/display_name/rating changed → `updated`.
  - `deleted` + was published → `removed`.
- **Listener:** `App\Listeners\Webhooks\DispatchTestimonialWebhooks` — agency-wide fan-out to every active key with `webhooks:receive` + a `webhook_url`, gated by `agency.website_enabled`. Creates an `AgencyWebhookDelivery` and dispatches `DeliverAgencyWebhook` (existing job, HMAC-SHA256 signed). `removed` sends a minimal `{id}` payload; otherwise the full `TestimonialResource`.

**This is the requested behaviour:** tick publish → `testimonial.published` POSTed to the website + appears in the pull API. Untick → `testimonial.removed` POSTed + disappears from the pull API.

---

## 6. UI placement & navigation (non-negotiable #2)

### 6.1 Contact → "Notes & Testimonials" tab (capture)
The existing Contacts **Notes** tab (`resources/views/corex/contacts/show.blade.php`) is renamed **"Notes & Testimonials"**. A new **Testimonials** block sits above/below the notes list:
- "Add testimonial" form: quote (textarea, required), star rating (1–5, optional), public display name (prefilled with the contact's full name, editable).
- List of the contact's testimonials with author, rating stars, captured-by + date, a **published** status pill ("On website" / "Not published"), Edit and Delete (soft) actions.
- No publish control here — publishing is Settings-only (per decision).

### 6.2 Company Settings → Website → Testimonials (publish)
A new **Testimonials** section in the existing Website tab (`resources/views/admin/company-settings/index.blade.php`). Lists **all** agency testimonials in a short/preview form (truncated quote + author + rating + the source contact link). Each row expands (Alpine) to the full quote and carries the **publish tick box** that fires the toggle route. A status pill shows current state. Gated by `testimonials.publish`.

### 6.3 Navigation
Both touchpoints live on pages already in the sidebar (Contacts show; Company Settings). No new orphaned page.

---

## 7. Routes

**Capture (agent-facing, `permission:access_contacts` + `agency.required`, under the contacts group):**
```
POST   /contacts/{contact}/testimonials                  corex.contacts.testimonials.store
PUT    /contacts/{contact}/testimonials/{testimonial}    corex.contacts.testimonials.update
DELETE /contacts/{contact}/testimonials/{testimonial}    corex.contacts.testimonials.destroy
```

**Publish (settings, `permission:testimonials.publish`):**
```
PATCH  /admin/company-settings/{agency}/testimonials/{testimonial}/publish
       admin.company-settings.testimonials.toggle
```

**Public API (`auth:agency-api` + `website.scope:testimonials:read`):** see §4.

---

## 8. Permissions (non-negotiable #5)

Add to `config/corex-permissions.php`:
- `testimonials.publish` — "Publish Testimonials to Website" (action, module `agency_api`, admin section). Default-granted to `admin` (alongside `agency_api.manage`); `super_admin` via wildcard.

Capture (store/update/destroy on the contact) is gated by the existing `access_contacts` — identical to contact notes, so any agent who can open the contact can record a testimonial.

The public `/api/v1/website/testimonials` routes are gated by `auth:agency-api` + `website.scope:testimonials:read`, not the internal permission system.

---

## 9. Input space & robustness (BUILD_STANDARD §2/§3)

| Input | Decision |
|-------|----------|
| `body` empty | Required → reject with a clear message. Never hits DB. |
| `rating` empty | Optional → accept (null), API returns `null`. |
| `rating` out of 1–5 / non-numeric | Validate `nullable|integer|min:1|max:5` → reject. |
| `display_name` empty | Default to the contact's full name server-side; if the contact has no name, fall back to "Client". NOT-NULL column always supplied. |
| `display_name` whitespace | Trim before save. |
| Lazy shortcut: quote only, no rating, no name | Legal → must work end to end (name auto-fills, rating null). |
| Toggle publish on a testimonial of another agency | `AgencyScope` + explicit ownership check → 404. |
| Deleted contact | Testimonial cascades on contact delete; the public API/webhook guards on `agency.website_enabled` and never dereferences a missing contact (display_name is denormalised, so a soft-deleted contact still renders). |
| Toggle when `website_enabled = false` | Publish flag still saves; no webhook fires and the pull API returns nothing (master switch). No error. |

---

## 10. Acceptance criteria

- [ ] Contacts tab is titled **"Notes & Testimonials"**; notes behaviour unchanged.
- [ ] An agent can add a testimonial (quote + optional rating + editable display name) on a contact; it persists and lists; full CRUD (edit, soft-delete) present.
- [ ] The lazy path (quote only) works; display name auto-fills from the contact; rating may be null.
- [ ] Company Settings → Website → Testimonials lists all agency testimonials short-form, expands to full text, and the tick box publishes/unpublishes (permission-gated).
- [ ] `GET /api/v1/website/testimonials` returns only `published` testimonials of the key's agency; cross-tenant isolation proven; the route appears under "Website" at `/admin/api`.
- [ ] Ticking publish fires a signed `testimonial.published` webhook to every eligible key; unticking fires `testimonial.removed`; editing a published one fires `testimonial.updated`. Changes to an unpublished one fire nothing.
- [ ] Master switch off → no webhook, no API data, no error.
- [ ] `rating` out of range and empty `body` rejected with user-clear messages.
- [ ] Testimonials soft-delete; recoverable.
- [ ] `scripts/dev-check.ps1` passes with 0 new failures; feature tests cover tenancy isolation, scope enforcement, publish→webhook, master switch, and the input matrix.

---

## 11. Files to create / modify

**Create**
- `database/migrations/2026_06_06_100001_create_contact_testimonials_table.php`
- `app/Models/ContactTestimonial.php`
- `app/Events/Website/TestimonialVisibilityChanged.php`
- `app/Observers/ContactTestimonialObserver.php`
- `app/Listeners/Webhooks/DispatchTestimonialWebhooks.php`
- `app/Http/Resources/WebsiteApi/TestimonialResource.php`
- `app/Http/Controllers/Api/V1/Website/TestimonialsController.php`
- `app/Http/Controllers/CoreX/ContactTestimonialController.php`
- `tests/Feature/Testimonials/*`

**Modify**
- `app/Models/Contact.php` — `testimonials()` HasMany
- `app/Models/AgencyApiKey.php` — `SCOPE_TESTIMONIALS_READ` + SCOPES entry
- `app/Providers/AppServiceProvider.php` — observe `ContactTestimonial`, register `TestimonialVisibilityChanged → DispatchTestimonialWebhooks`
- `routes/api.php` — `/website/testimonials` group
- `routes/web.php` — capture routes (contacts group) + publish toggle (company-settings)
- `app/Http/Controllers/Admin/CompanySettingsController.php` — `toggleTestimonial()`
- `resources/views/corex/contacts/show.blade.php` — rename tab + testimonial capture/list UI
- `resources/views/admin/company-settings/index.blade.php` — Testimonials publish section in the Website tab
- `config/corex-permissions.php` — `testimonials.publish`
- `.ai/CODEBASE_MAP.md`, `.ai/CHAT_STARTER.md`

---

## 12. Explicitly deferred
- Linking a testimonial to the Deal/Property it arose from (v2).
- Testimonial photos/avatars on the public site (v2 — needs media handling).
- Website-side moderation workflow beyond the single publish tick.
</content>
