# Agency Public API — Spec (DRAFT)

> Status: **BUILT (Phases 1–5) on `Staging`** — 54 feature tests, 0 new suite failures. Pending Johan review/merge.
> Author: Andre (drafted with Claude) · 2026-06-01 · built 2026-06-02
> Module owner: Platform / Integrations
> Related specs: [`multi-tenancy.md`](multi-tenancy.md), [`corex-domain-events-spec.md`](corex-domain-events-spec.md), [`listings.md`](listings.md), [`client-auth.md`](client-auth.md)

---

## 1. What this feature does and why

Agencies on CoreX will get **custom-built marketing websites** (separate, bespoke sites — not on the CoreX codebase). Those sites need their agency's live data: listings, property detail, agent profiles, branding. They also need to **stay fresh in real time** — when a listing changes in CoreX, the website should reflect it without a human re-publishing.

This spec defines the mechanism: a **per-agency public API** that an external website authenticates against, plus **webhooks** that push change notifications to that website. It is the same two-part pattern CoreX already uses for Property24 (CoreX is the source of truth; data flows *out* to the portal) and that every serious platform (Stripe, P24, Lightstone) uses:

- **REST API (pull)** — the website requests data when it renders or rebuilds (`GET /api/v1/website/listings`).
- **Webhooks (push)** — CoreX fires a signed HTTP callback to the website when a watched event occurs (listing published, price changed, listing withdrawn), so the site can refresh just that record. This is the "push like P24" behaviour.

### Critical design principle — ONE API, MANY KEYS

We do **not** generate a separate API (separate routes/code) per agency. That would be N copies of the same surface to maintain, version, and secure — a compounding-debt shortcut, forbidden by the CoreX Operating Principle.

Instead: **one versioned public API surface, built once.** The "Create API" action in the agency screen generates a **credential (API key + secret)** for that agency. Every request carries the key; CoreX resolves the agency from the key and `AgencyScope` (already the spine of the system) automatically restricts all data to that agency. Adding an agency = issuing a key, never shipping code.

### The website IS a Syndication Portal (integration, not a new island)

CoreX already has a **Syndication** system: each property has a Syndication panel where the user toggles the property onto portals — currently **Property24**, **Private Property**, and a built-in **"HFC Premium" / `web`** portal (the agency's own website, today driven only by `properties.published_at`). The agency website built on this API is **not a new mechanism** — it is the `web` portal made real:

- When an API key is created, the user **names the website** (e.g. "Home Finders Coastal"). That name becomes the **portal label** shown in the Syndication Portals settings ("Choose which portals appear…") and in each property's Syndication panel.
- A property only appears on the website once its **website portal is toggled on** in that property's Syndication panel — identical UX to how a property is pushed to P24 or PP today.
- Toggling the website portal on/off is exactly what fires the `listing.published` / `listing.removed` webhooks (§6). So the public API + webhooks become the **delivery transport for the `web` syndication portal**, the same way the P24/PP syndication services are the transport for those portals.

This means listings do **not** get a separate "show on website" flag — visibility is the existing **syndication toggle**. (Agents are not part of syndication, so they keep their own `show_on_website` flag — see §2.)

---

## 2. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Property** | Exposes Agency Stock (`properties`) listings + detail via the public read API | — (read-only in v1; webhooks emit on property change) |
| **Agent** (`User`) | Exposes public profile fields **only for agents flagged `show_on_website`** (name, photo, contact, FFC where public) | New per-agent `show_on_website` flag; create/update/delete sync to the website via webhooks |
| **Contact** | — (v1 is read-only; see §9 future: lead capture writes Contacts) | — |
| **Deal** | — | — |

### Three layers of "is this live?"

Visibility is gated at three independent levels, so nothing leaks before it's meant to:

1. **Agency master switch** — `agencies.website_enabled`. The website-live toggle on the agency API panel. When OFF, **no** public API responses and **no** webhooks fire for that agency, regardless of keys. This is the kill-switch.
2. **Per-key active state** — each API key can be active/revoked independently (a per-website on/off — see multiple-keys, §3.5).
3. **Per-record publish flag** —
   - **Listings:** the **website Syndication toggle** on the property (existing mechanism, §6.5) — a property reaches the website only when its website portal is on.
   - **Agents:** the per-agent `show_on_website` flag (agents aren't syndicated).
   Only enabled records appear in the API and trigger webhooks.

All three must be "on" for a record to reach a website.

v1 is **read + push only**. Inbound writes (lead capture from the website's contact form) are explicitly deferred to a v2 phase (§9) so the write surface gets its own scope/abuse design rather than being bolted on.

---

## 3. Data model / migrations

### 3.1 `agency_api_keys` (new table)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `agency_id` | FK → agencies | the tenant this key is scoped to |
| `name` | string | **the website name** asked for at key creation (e.g. "Home Finders Coastal"). Used as the **Syndication Portal label** in settings + each property's Syndication panel (§6.5). |
| `key_prefix` | string(12), indexed | non-secret public identifier shown in UI, e.g. `cx_live_a1b2` |
| `secret_hash` | string | **hash only** of the full secret (`hash('sha256', …)`); plaintext shown once on creation and never stored |
| `scopes` | json | array of granted scopes (see §3.3) |
| `webhook_url` | string, nullable | where CoreX POSTs webhook events |
| `webhook_secret` | text (encrypted cast) | HMAC signing secret for webhook payloads — mirrors existing `agencies.pp_webhook_secret` pattern |
| `rate_limit_per_min` | unsigned int, default 120 | per-key throttle |
| `last_used_at` | timestamp, nullable | telemetry |
| `expires_at` | timestamp, nullable | optional expiry |
| `revoked_at` | timestamp, nullable | revoke without deleting |
| `created_by` | FK → users, nullable | who issued it |
| timestamps + **softDeletes** | | non-negotiable #1 — no hard deletes |

Model `App\Models\AgencyApiKey` uses the `BelongsToAgency` trait so it is itself tenant-scoped.

### 3.5 Multiple keys per agency (explicit requirement)

An agency can hold **many** keys — one per website (production site, second brand, a third-party dev's staging build, etc.). Each key is its own row with its own scopes, `webhook_url`, rate limit, and active/revoked state. There is no "one key per agency" constraint anywhere. The "API Access" panel lists all of an agency's keys and manages them independently. A webhook event fans out to **every** active key of the agency that has `webhooks:receive` + a `webhook_url`, so all of an agency's sites stay in sync from one change.

### 3.6 New columns on existing tables

| Table | Column | Type | Purpose |
|-------|--------|------|---------|
| `agencies` | `website_enabled` | boolean, default false | master "website is live" switch (§2 layer 1) — gates all public API + webhooks |
| `users` | `show_on_website` | boolean, default false | per-agent publish flag (§2 layer 3) — only flagged agents reach the public API/webhooks |

`users.show_on_website` defaults to **false** — an agent is invisible to the public web until someone explicitly turns it on, never the reverse (prevent-or-absorb: no accidental PII egress).

### 3.7 Website settings (Company Settings → Website tab)

A small set of public-facing website settings, stored on `agencies` (extend the existing presentation/branding-settings pattern — see `2026_05_23_100001_add_presentation_settings_to_agency.php`). Current field set:

| Column | Purpose |
|--------|---------|
| `website_social_*` | facebook / instagram / linkedin / youtube handles |
| `website_contact_email` / `website_contact_phone` | public contact shown on the site (may differ from internal; falls back to agency `email` / `phone`) |
| `website_address` | public address shown on the site's contact block (falls back to agency `address`) |
| `website_open_hours` | JSON list of `{ days, hours }` rows the site renders as an opening-hours block — repeatable (weekdays, Saturday, public holidays, …); blank rows are dropped on save |
| `website_show_agents` / `website_show_listings` | section master toggles the API honours |

These are served read-only via `GET /api/v1/website/agency` so the website renders them. **Blank values are omitted from the response** (no empty `contact`/`social`/`open_hours` keys) so the website never renders an empty field — an unset section simply does not appear.

> **Deprecated (2026-06-06):** the `website_url`, `website_tagline`, and `website_about` columns are retained for backward compatibility but are no longer edited in the Website tab nor exposed by the API. The website owns its own hero/about copy and URL.

### 3.2 `agency_webhook_deliveries` (new table — delivery log / retry)

Records each webhook send attempt: `agency_api_key_id`, `event_name`, `payload` (json), `response_status`, `attempts`, `delivered_at`, `next_retry_at`, `failed_at`, timestamps. Enables retry-with-backoff and a "delivery history" view. SoftDeletes.

### 3.3 Scope catalogue (v1 — all read-only)

| Scope | Grants |
|-------|--------|
| `listings:read` | active Agency Stock listings (index + detail) |
| `agents:read` | public agent profile fields |
| `agency:read` | agency branding/contact (name, logo, colours, address) |
| `webhooks:receive` | eligible to receive listing/agent webhook events |

No `*:write` scopes ship in v1.

### 3.4 No change to `agencies` table

API keys live in their own table (an agency may have several — production, staging, third-party dev). Nothing added to `agencies`.

---

## 4. Authentication

- New guard **`auth:agency-api`** backed by a custom Sanctum-style token resolver (or a thin guard) that:
  1. Reads `Authorization: Bearer <full-secret>`.
  2. Splits prefix, looks up `agency_api_keys` by `key_prefix`, verifies `hash_equals(secret_hash, hash('sha256', presented))`.
  3. Rejects if `revoked_at`/`expired`/soft-deleted.
  4. Sets the resolved agency as the active tenant for the request so **`AgencyScope` filters every query automatically** — no per-endpoint agency filtering.
  5. Enforces the key's `scopes` per route (middleware `agency-api.scope:listings:read`).
  6. Applies per-key rate limiting (`rate_limit_per_min`) via Laravel `RateLimiter`.
  7. Updates `last_used_at` (throttled write).

Reuse the constant-time-compare + HMAC discipline already proven in `PpWebhookController`.

---

## 5. Endpoints (all under `/api/v1/website/*`, named, auto-listed at `/admin/api` per non-negotiable #7)

> **Catalog grouping:** the Admin → API page (`ApiCatalogController::groupFor()`) groups by the 3rd URI segment, so every endpoint below auto-appears under a dedicated **"API v1 — Website"** section at `/admin/api` — no catalog code change needed. (If a plain "**Website**" label is preferred over "API v1 — Website", that's a one-line tweak to `groupFor()` mapping the `website` resource — confirm at build time.) This is the requested "Website section where all the created API lives."

| Method | URI | Name | Scope |
|--------|-----|------|-------|
| GET | `/api/v1/website/listings` | `v1.website.listings.index` | `listings:read` |
| GET | `/api/v1/website/listings/{ref}` | `v1.website.listings.show` | `listings:read` |
| GET | `/api/v1/website/agents` | `v1.website.agents.index` | `agents:read` |
| GET | `/api/v1/website/agents/{id}` | `v1.website.agents.show` | `agents:read` |
| GET | `/api/v1/website/agency` | `v1.website.agency.show` | `agency:read` |
| GET | `/api/v1/website/ping` | `v1.website.ping` | (any valid key) |

Responses use dedicated **public API Resources** (`app/Http/Resources/WebsiteApi/*`) so the external contract is decoupled from internal model shape — we can refactor models without breaking agency sites. PII not meant for the public web (owner contact, internal notes) is **never** included.

**AgentResource public fields:** `id`, `name`, `designation` (the agent's role/title, e.g. "Principal Property Practitioner" / "Candidate Property Practitioner" — a public "meet the team" field), `email`, `phone`, `cell`, `photo_url`. The compliance **FFC number is NOT exposed** (§13 Q7).

**Agent-scoped listings:** `GET /api/v1/website/listings?agent_id={userId}` filters to a single agent's syndicated listings — used by the agent's website profile to show "their properties". Each listing's `agent` block carries `agent.id`, so the website links a listing card back to that agent's profile. (Testimonials carry the same `agent_id` + a `?agent_id=` filter — see `testimonials.md`.)

**ListingResource is P24-parity** — it carries the same rich marketing field set CoreX syndicates to Property24: location detail (complex/unit/floor/stand + street parts), `costs` (rates/levy/special levy), a `rental` block (lease period, deposit, rental amount, gross/net, per-period rates) populated only for rentals, `mandate_type`, `pet_friendly`, `spaces`, `features`, a categorised `gallery`, `video` (YouTube/Matterport/virtual tour), and upcoming `show_days` — alongside the core price/beds/baths/size/images/agent. Numeric fields are coerced to int/float for a clean contract.

---

## 6. Webhooks (the "push" half)

### 6.1 Trigger source — domain events (non-negotiable #9)

Webhooks subscribe to the **domain events catalogue**, NOT ad-hoc hooks. A queued listener `Listeners\Webhooks\DispatchAgencyWebhooks` subscribes to the relevant Property/Agent events and, for each agency key with `webhooks:receive` + a `webhook_url`, enqueues a delivery.

Events that should fire webhooks (emit if not already emitted — current `app/Events/Property/` only has `PropertySgDocumentSaved`, `PropertySuburbLinked`):

| Event | Webhook `event` name | Why |
|-------|----------------------|-----|
| Website Syndication toggled **on** for a property (incl. bulk-activate, §6.5.3) | `listing.published` | listing appears on site |
| Property updated (price, status, media) **while its website portal is on** | `listing.updated` | site refreshes the record |
| Website Syndication toggled **off**, or property sold/withdrawn/soft-deleted while on | `listing.removed` | site pulls it down |
| Agent created **with** `show_on_website=true`, or flag flipped on | `agent.published` | new agent card appears on site |
| Agent updated while `show_on_website=true` | `agent.updated` | site refreshes the agent card |
| Agent soft-deleted, or `show_on_website` flipped off | `agent.removed` | site pulls the agent card down |
| Article created **with** `is_published=true`, or `is_published` flipped on (incl. restore of a published article) | `article.published` | new article appears on the agent's profile |
| Published article's public content edited (`title`, `slug`, `excerpt`, `cover_image_path`, `body`, `link_url`, `tags`, `published_at`) | `article.updated` | site refreshes the article |
| Article `is_published` flipped off, or a published article soft-deleted | `article.removed` | site pulls the article down |

**Agent sync semantics (the requested create/update/delete behaviour):**
- An agent change only fires a webhook when it crosses the public boundary. Create/update of an agent that is *not* `show_on_website` fires **nothing** (it's not public).
- Turning `show_on_website` on → `agent.published`. Turning it off → `agent.removed`. Editing a public agent → `agent.updated`. Soft-deleting a public agent → `agent.removed`.
- This mirrors the listing publish logic exactly, so the website handles agents and listings with the same "published / updated / removed" pattern.

**Article sync semantics:** Identical "crosses the public boundary" rule, keyed on `is_published`. A draft article fires **nothing** (create, edit, delete) until it is published. Publish → `article.published`; unpublish → `article.removed`; edit of a published article's public fields → `article.updated`; soft-delete of a published article → `article.removed`. Wired via `AgentArticleObserver` → `ArticleVisibilityChanged` → `DispatchArticleWebhooks`. **Every `article.*` payload carries both `id` and `agent_id`** (the `removed` payload is `{ id, agent_id }`; published/updated carry the full `ArticleResource`) so the consuming site can bust its **per-agent** article cache off `data.agent_id`.

> Implementation note: emitting `listing.*` and `agent.*` cleanly requires the matching domain events (`PropertyPublished`/`PropertyUpdated`/`PropertyRemoved`, `AgentPublished`/`AgentUpdated`/`AgentRemoved`). Current `app/Events/Property/` has only `PropertySgDocumentSaved` + `PropertySuburbLinked`, and there is no `app/Events/Agent/` publish event yet — these are added to the catalogue (`corex-domain-events-spec.md`) and emitted from the Property/User observers at build time, per non-negotiable #9. We do NOT bolt webhook logic directly onto controllers.

### 6.2 Delivery

- POST JSON to `webhook_url`: `{ event, occurred_at, agency_id, data: { …resource… } }`.
- Sign with HMAC-SHA256 over the raw body using the key's `webhook_secret`; send as `X-CoreX-Signature` (same shape as PP webhook verification, so the receiving site verifies identically).
- Retry with exponential backoff (e.g. 1m, 5m, 30m, 2h, 6h) up to N attempts; log every attempt in `agency_webhook_deliveries`.
- Payloads are small (the changed resource); sites may treat the webhook as a "refresh this id" signal and re-pull via the REST API for the full record.

### 6.3 Operations (cron + .env) — built

Retries are driven by `next_retry_at` + a scheduled sweep, NOT queue-native backoff
(so a failed POST never marks the queue job failed or floods logs):

- `DeliverAgencyWebhook` (queued) records `response_status` / `delivered_at` /
  `attempts` / `next_retry_at` / `failed_at` / `last_error`; on failure it sets
  `next_retry_at` (backoff 1m/5m/30m/2h/6h) up to 5 attempts, then `failed_at`.
- `webhooks:retry-due` (artisan command) re-dispatches due rows; scheduled
  `everyMinute()->withoutOverlapping()` in `routes/console.php`.

**CoreX server (one-time, platform-wide — already provisioned on prod):**
- `.env`: `QUEUE_CONNECTION=database` (webhooks queue instead of blocking requests).
  No per-website keys live in CoreX's `.env`.
- Scheduler cron: `* * * * * cd /corex && php artisan schedule:run >> /dev/null 2>&1`
  (drives `webhooks:retry-due` + all other CoreX schedules).
- A queue worker (systemd/supervisor): `php artisan queue:work --queue=default --tries=1`
  (delivers the webhooks).

**Each website you build (per site):** the key + webhook secret go in THAT site's
env (`COREX_API_BASE`, `COREX_API_KEY`, `COREX_WEBHOOK_SECRET`) — surfaced in the
"Connecting a website" note in Admin → Agencies → API Access. Pushes require: master
switch ON + key has `webhooks:receive` scope + a `webhook_url`.

---

## 6.5 Syndication integration (how listings reach the website)

The website is a **Syndication Portal**. This section defines how the existing syndication system (currently hardcoded P24 / PP / `web`) extends to the named website portal(s).

### 6.5.1 Dynamic portal list

**DECIDED:** one portal per website (per-site control), and the legacy generic `web` / "HFC Premium" portal is **removed**.

Today the portal list is hardcoded in `settings.blade.php` and per-portal flags live in `performance_settings` (`syndication_{website,pp,p24}_enabled`). The website portal becomes **dynamic**: P24 and PP stay; the hardcoded `web` / "HFC Premium" entry is **deleted** and replaced by **one row per agency website key**, each labelled with that website's name. There is no generic catch-all website portal — "HFC Premium" simply becomes a normal named API key like any other agency's site (HFC will create it and repopulate via the bulk-activate button, §6.5.3).

The Syndication Portals settings panel and each property's Syndication panel therefore list:
- **Property24** (existing)
- **Private Property** (existing)
- **one row per agency website key** — labelled with the website name.

### 6.5.2 Per-property syndication state — pivot table (DECIDED)

Because each website is its own portal, per-property syndication state is **per (property × website)** — stored in a new pivot:

`property_website_syndication`:
| Column | Notes |
|--------|-------|
| `property_id` | FK → properties |
| `agency_api_key_id` | FK → agency_api_keys (the website) |
| `enabled` | bool — is this listing on this site |
| `status` | pending / submitted / active / deactivated / error (mirrors `pp_syndication_status`) |
| `last_submitted_at`, `activated_at`, `last_error`, `last_synced_at` | mirror the `pp_*` / `p24_*` tracking columns |
| timestamps + softDeletes | |

Uses `BelongsToAgency` (agency derivable via the property/key). This avoids the column-explosion of adding `web_*` columns per site to `properties`, and scales to any number of sites.

The per-property Syndication panel (`properties/show.blade.php`) renders one toggle **per website portal**; toggling routes through `WebsiteSyndicationController@toggle` (mirrors `P24SyndicationController@toggle`) — upserting the pivot row's `enabled` and emitting the domain event that drives the webhook to **that specific website's** `webhook_url`.

### 6.5.3 Bulk activate button (agency side)

Because portals are per-site, the bulk action is **per website key**. On each website's management row, an **"Add all Active listings"** button: one click upserts an `enabled` pivot row for that website for **every property with `status = 'active'`** in the agency (respecting multi-tenancy scope), in one batched action.

- Models the existing `batchToggleDefaultItems()` bulk pattern (`SettingsController.php:279`).
- Route: `POST /api/v1/.../website-syndication/{key}/bulk-activate` (named), permission-gated.
- Idempotent: properties already enabled for that site are skipped; only `status='active'` are touched (draft/sold/withdrawn are not auto-published).
- Fires one `listing.published` webhook per newly-enabled property to **that website's** `webhook_url` (queued, throttled) so all existing stock lands on a freshly-launched site in one action.
- Reports a summary ("142 active listings enabled, 8 already live") — no silent caps; if the set is large and batched, say so.

This is the "launch day" button: create the website's API key, build the site, click once, all active stock appears on that site.

---

## 7. UI placement & navigation (non-negotiable #2)

### 7.1 Admin → Agencies → (edit agency) → "API Access" panel

(`admin.agencies.create-edit` already exists.) Provides:

- **"Website is live" master toggle** (`agencies.website_enabled`) at the top of the panel — the single switch that turns the whole integration on/off (§2 layer 1). Clear copy: "When off, no website receives data, even with valid keys."
- **Generate API Key** button → modal asks **"What is this website called?"** (the `name` — becomes the Syndication Portal label), scopes (checkboxes), optional `webhook_url`, optional expiry.
- On create: show the **full secret once** in a copy-box with a clear "you won't see this again" warning; store only the hash.
- A **list of all the agency's keys** (multiple keys supported, §3.5) — one row per website. Per key: prefix, name, scopes, last used, status (active/revoked/expired), **Revoke**, **Regenerate secret**, **Edit webhook URL**, **View webhook deliveries**.
- Soft-deleted/revoked keys remain visible to Admin (recoverable) per non-negotiable #1.

No new top-level sidebar entry needed (lives inside the existing Agencies admin), but the panel must be reachable the same day it ships.

### 7.2 Per-agent "Show on website" control

On each agent (User edit / agent profile screen), add a **"Show on website"** toggle bound to `users.show_on_website`. Off by default. Flipping it fires the appropriate `agent.published` / `agent.removed` webhook (§6.1). A small status pill ("On website" / "Hidden") shows current state at a glance. Gated by the same permission that lets a manager edit that agent.

### 7.3 Syndication touchpoints (existing screens, extended)

- **Settings → Properties & Listings → Syndication Portals** (`corex/settings.blade.php:1688`): the website portal appears here under its website name, enable/disable like P24/PP.
- **Property → Syndication panel** (`properties/show.blade.php:758`): the named website portal toggle sits alongside P24 and Private Property, same UX.
- **"Add all Active listings" bulk button** (§6.5.3) lives on the website portal management area (agency side) — wherever the website key is managed.

### 7.4 Company Settings → new "Website" tab

The Company Settings page (`resources/views/admin/company-settings/index.blade.php`, `CompanySettingsController`) already uses an Alpine `activeTab` tab bar. Add a new **"Website"** tab whose key/label is registered alongside the existing tabs. It holds the §3.7 public website settings (URL, tagline/about, socials, public contact, section toggles) plus a convenience **"Visit website"** link and a read-only summary of which keys are live. Saving writes the `website_*` fields on the agency. This tab is the agency-facing place to manage *what the site shows*; the Admin API Access panel (7.1) is the super-admin place to manage *keys and the master switch*.

---

## 8. Permissions (non-negotiable #5)

Add to `config/corex-permissions.php`:

- `agency_api.view` — see API Access panel + keys
- `agency_api.manage` — generate / revoke / regenerate / edit webhook

Gate the panel, the routes (Admin side), and controller actions. Owner/Admin roles only by default. The **public** `/api/v1/website/*` routes are gated by the `auth:agency-api` guard + scope middleware, not the internal permission system.

---

## 9. Explicitly deferred (NOT in v1)

- **Inbound lead capture** (`POST /api/v1/website/leads` → creates a Contact/Lead with the agency scoped from the key). Needs its own write-scope design, spam/abuse protection (captcha/honeypot, rate limits), and Contact-pillar match-or-create (non-negotiable #10). Will be a v2 phase with its own spec section.
- Self-service key management by agency Admins (v1 is super-admin/Owner managed from the Agencies screen).
- OpenAPI/Swagger published doc site for third-party devs (v1 documents endpoints in `/admin/api` + a markdown integration guide).

These are deferred *by design with an upgrade path*, not "good enough for now" compromises on the v1 scope.

---

## 10. User flow

1. Super-admin opens **Admin → Agencies → [Agency] → API Access**.
2. Clicks **Generate API Key**, names it ("Production website"), ticks scopes (`listings:read`, `agents:read`, `agency:read`, `webhooks:receive`), enters the site's `webhook_url`.
3. CoreX shows the full secret once; admin copies it to the website's environment config.
4. The website calls `GET /api/v1/website/listings` with `Authorization: Bearer <secret>` → receives only that agency's active listings.
5. An agent changes a listing price in CoreX → `PropertyUpdated` domain event fires → `DispatchAgencyWebhooks` POSTs a signed `listing.updated` to the website → the site re-pulls and refreshes that listing.

---

## 11. Acceptance criteria

- [ ] A key generated for Agency A returns **only** Agency A's listings; a request with Agency A's key can never see Agency B's data (proven by a cross-tenant feature test).
- [ ] Full secret is shown exactly once; DB stores only the hash; presenting a wrong/revoked/expired key returns 401.
- [ ] Scope enforcement: a key without `agents:read` gets 403 on the agents endpoint.
- [ ] Rate limiting returns 429 past the per-key limit.
- [ ] **Master switch:** with `website_enabled=false`, every public endpoint returns no data and no webhooks fire, even with a valid active key.
- [ ] **Multiple keys:** an agency with two active keys (two sites) gets webhook deliveries fanned out to both; revoking one key stops only that site.
- [ ] **Website is a named portal:** the API key's website name appears as a Syndication Portal in settings and in each property's Syndication panel, alongside P24/PP.
- [ ] **Listing visibility via syndication:** `GET /website/listings` returns only properties whose website portal is toggled on; toggling on fires `listing.published`, toggling off fires `listing.removed`.
- [ ] **Bulk activate:** "Add all Active listings" enables the website portal for every `status='active'` property, skips already-live ones, fires a `listing.published` per newly-enabled property, and reports a summary count.
- [ ] **Agent visibility:** `GET /website/agents` returns only `show_on_website=true` agents; an agent with the flag off is absent.
- [ ] **Agent sync:** flipping `show_on_website` on fires `agent.published`; editing a public agent fires `agent.updated`; flipping off or soft-deleting fires `agent.removed`. Changes to a hidden agent fire nothing.
- [ ] Changing a listing fires the correct domain event and delivers a signed `listing.updated` webhook the receiver can verify with HMAC; failed deliveries retry and are logged in `agency_webhook_deliveries`.
- [ ] The **Company Settings → Website tab** saves the `website_*` fields and they surface in `GET /website/agency`.
- [ ] All website routes are named under `v1.website.*` and appear in `/admin/api` **grouped together under a "Website" section**.
- [ ] Keys soft-delete; revoked keys recoverable by Admin.
- [ ] Permissions gate the panel, the per-agent toggle, and management actions.
- [ ] `scripts/dev-check.ps1` passes with 0 new failures; new feature tests cover tenancy isolation, auth, scopes, master switch, agent visibility/sync, and webhook signing.

---

## 12. Files to create / modify (indicative — confirm at build time)

**Create**
- `database/migrations/*_create_agency_api_keys_table.php`
- `database/migrations/*_create_agency_webhook_deliveries_table.php`
- `database/migrations/*_add_website_enabled_and_website_settings_to_agencies.php`
- `database/migrations/*_add_show_on_website_to_users.php`
- `database/migrations/*_create_property_website_syndication_table.php` (the per-(property × website) pivot, §6.5.2)
- `database/migrations/*_remove_legacy_web_syndication_portal.php` (drop the hardcoded `web`/"HFC Premium" portal + its `syndication_website_enabled` setting, after the `published_at` audit — §13 Q2)
- `app/Http/Controllers/Website/WebsiteSyndicationController.php` — `toggle` + `bulkActivate` (mirrors `P24SyndicationController`)
- `app/Services/Syndication/Website/WebsiteSyndicationService.php` (mirrors `Property24SyndicationService`)
- `app/Models/AgencyApiKey.php`, `app/Models/AgencyWebhookDelivery.php`
- `app/Http/Middleware/AuthenticateAgencyApi.php` (+ scope middleware)
- `app/Http/Controllers/Api/V1/Website/{Listings,Agents,Agency}Controller.php`
- `app/Http/Resources/WebsiteApi/*`
- `app/Listeners/Webhooks/DispatchAgencyWebhooks.php`
- `app/Jobs/DeliverAgencyWebhook.php`
- `app/Events/Property/PropertyPublished.php` / `PropertyUpdated.php` / `PropertyRemoved.php` + `app/Events/Agent/AgentPublished.php` / `AgentUpdated.php` / `AgentRemoved.php` (if not already in the catalogue) + docs in `corex-domain-events-spec.md`
- `tests/Feature/AgencyPublicApi/*`
- Integration guide markdown (for the external web devs)

**Modify**
- `routes/api.php` — `/api/v1/website/*` group under `auth:agency-api`
- `config/corex-permissions.php` — `agency_api.*`
- `resources/views/admin/agencies/create-edit.blade.php` — API Access panel + master "website is live" toggle
- `app/Http/Controllers/Admin/AgencyController.php` (or new `AgencyApiKeyController`) — generate/revoke/regenerate
- `app/Http/Controllers/Admin/CompanySettingsController.php` + `resources/views/admin/company-settings/index.blade.php` — new **Website** tab
- `app/Http/Controllers/Admin/ApiCatalogController.php` — *(optional)* `groupFor()` tweak to label the `website` resource as a clean "Website" section (otherwise auto-groups as "API v1 — Website")
- Agent edit screen + its controller — **Show on website** toggle
- `resources/views/corex/settings.blade.php` (~1688) — make Syndication Portals list include the named website portal(s) dynamically
- `resources/views/corex/properties/show.blade.php` (~758) — add the website portal toggle to the per-property Syndication panel
- `app/Http/Controllers/CoreX/SettingsController.php` — `updateSyndicationPortals()` handles the dynamic website portal
- `app/Observers/PropertyObserver.php` + `app/Observers/UserObserver.php` (or equivalent) — emit publish/update/remove domain events
- `app/Providers/EventServiceProvider.php` — register `DispatchAgencyWebhooks`
- `.ai/CODEBASE_MAP.md`, `.ai/ROADMAP.md`, `.ai/CHAT_STARTER.md`

---

## 13. Open questions for review

1. ~~One portal per site vs shared portal~~ — **DECIDED: one portal per website** (pivot model, §6.5.2).
2. ~~Relationship to legacy "HFC Premium" portal~~ — **AUDITED 2026-06-02 (see `.ai/audits/legacy-web-portal-published-at-2026-06-02.md`). Removal is NOT safe to do blindly in the Phase 3 build.** The legacy "HFC Premium" portal is a LIVE production push-sync (`WEBSITE_SYNC_ENABLED=true` → `SyncPropertyToWebsite` job → themandatecompany.co.za), triggered by `published_at`, which is load-bearing across 30+ files (presentations, marketing, mobile, intelligence). **Phase 3 builds the new per-key syndication ADDITIVELY (pivot toggle + bulk-activate + named portals) alongside the legacy portal. Retiring the legacy push-sync is a separate, deliberate migration: cut themandatecompany.co.za over to the new pull API + webhooks, verify parity, then disable WEBSITE_SYNC_ENABLED and remove the portal/job/observer-dispatch.** `published_at` is untouched by this feature.
3. **Do agents get the same `show_on_website` treatment, or also a syndication-style flow?** Current plan: agents use the simple `show_on_website` flag (they're not properties, not in the syndication panel). Confirm that's the desired UX.
4. **Exact Website-tab settings** — confirm the final `website_*` field list (the §3.7 set is a starter). What does the website actually need to render from CoreX vs hold itself?
5. **Listing identity in the public API** — expose by internal id, a stable public `ref`, or slug? (Affects URL stability for the websites.)
6. **Media/images** — serve via signed CoreX URLs, or include CDN/public URLs in the payload? (P24 sync logic may already have an answer to reuse.)
7. **Which agent fields are "public"** — confirm with compliance what's safe to expose (FFC number? direct cell?). **Current shape (built):** `id`, `name`, `designation`, `email`, `phone`, `cell`, `photo_url`. FFC number stays hidden. `designation` added 2026-06-06 so websites can render the agent's title on the team card.
8. **Webhook retry ceiling + dead-letter** — how many attempts before we surface a "your endpoint is down" alert in the Agencies UI?
9. Do we want a **sandbox key type** (`cx_test_…`) for the web devs to build against before go-live, mirroring the PP `pp_sandbox` flag?
