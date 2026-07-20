# Portal Leads — Spec

> Unified enquiry-lead ingest & display across Property24 (P24) and Private Property (PP).
> Approved 2026-05-20. Owner: Andre. Sits under Real Estate pillar (Property + Contact + Agent).

---

## Why

Today, PP buyer enquiries arrive via `POST /api/pp/webhook` and create a `Contact` + `CommandTask`. P24 buyer enquiries are not captured at all — they live only in the P24 ExDev portal. Agents lose response-time minutes to portal-switching, and there is no single pane that shows the agency's incoming lead volume by portal or per property.

Portal Leads is a parallel, additive layer:
- A new `portal_leads` table records every inbound enquiry as a row, with raw payload preserved.
- A new P24 pull-job ingests P24 leads via Listing Service v53.
- The existing PP webhook is **not modified** — a CommandTask observer back-fills the same `portal_leads` row from PP-created tasks.
- A unified UI surfaces both portals, with real-time popup notifications and a per-property Intelligence panel.

## Pillars touched
- **Property** — leads link to a `Property` via `listing_id`; counts roll up into the Intelligence tab.
- **Contact** — every lead resolves to a `Contact` (existing or newly-created Buyer/Lead), preserving owner-agent if the contact already existed.
- **Agent (User)** — new contacts are assigned to the listing agent; popup notification fires to authenticated users of the same agency.

## Non-negotiable: PP zero-touch
The existing PP integration (controller, services, jobs, spec) is fully built and load-bearing. This module integrates with PP exclusively through a `CommandTask` observer filtering on `source_type = 'private_property_webhook'`. No file under `app/Http/Controllers/PrivateProperty/*`, `app/Services/PrivateProperty/*`, or related PP jobs is to be modified.

---

## Data model

### `portal_leads`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| agency_id | FK agencies | multi-tenancy scope |
| portal | enum('p24','pp') | |
| lead_type | string | Email / Phone / SMS / WhatsApp / Alert |
| listing_id | FK properties NULL | resolved property (CoreX) |
| listing_portal_ref | string NULL | the P24 or PP listing reference |
| contact_id | FK contacts NULL | populated after dedup |
| contact_exists | bool default false | true = matched an existing contact |
| existing_contact_agent_id | FK users NULL | owner of the existing contact, if matched |
| name | string | |
| email | string NULL | |
| phone | string NULL | |
| message | text NULL | |
| is_whatsapp | bool default false | |
| lead_source_raw | json | full portal payload (for PP: reconstructed from observer) |
| received_at | timestamp | when the portal received the lead |
| notified_at | timestamp NULL | when an in-app toast was shown |
| created_at / updated_at | timestamps | |

**Indexes**: `(agency_id, received_at)`, `(portal, listing_portal_ref, received_at)` (dedup), `listing_id`, `contact_id`.

### Seeded data
- `contact_sources` row: `"Property24"` (PP already seeded as `"Private Property"`).

---

## Resolution logic — when a P24 lead arrives

1. **Dedupe** — same `(portal, listing_portal_ref, email/phone, received_at)` already in `portal_leads`? Skip.
2. **Resolve the property** — call `TrackedPropertyMatchOrCreateService::matchOrCreate()` with the P24 listing ref + facts from the lead payload; bind `listing_id` to the resulting tracked → stock property if one exists, else leave null and store the listing ref.
3. **Resolve the contact** — search `contacts` where (`email` OR `phone`) match.
   - If found: `contact_exists = true`, `existing_contact_agent_id = matched_contact.created_by_user_id`. **Do not reassign.**
   - If not found: create `Contact` of type **Buyer** (per brief), assigned to the listing agent (`created_by_user_id = property->agent_id`), `contact_source_id = "Property24"`.
4. **Persist** the `portal_leads` row.
5. **Fire** `NewPortalLeadReceived($portalLead)` event.

## Resolution logic — when a PP lead arrives (via observer, additive)

When a `CommandTask` is created with `source_type = 'private_property_webhook'`:
1. Lookup the associated `Contact` (already created by PP webhook).
2. Lookup the associated `Property`.
3. Reconstruct minimal lead_source_raw from the task description + contact fields.
4. Insert into `portal_leads` (`portal = 'pp'`).
5. Determine `contact_exists` by checking if any *other* contact in the agency shares the email/phone — purely informational; the PP-created contact stays as owner.
6. Fire `NewPortalLeadReceived($portalLead)`.

---

## API & scheduling

- **`P24LeadService::pullLeads()`** — calls `GET /listing/v53/listings/leads?after=<iso8601>` on the existing `Property24ApiClient` (Basic Auth). Cursor stored in `cache('p24.leads.cursor')` (per agency if multi-tenant).
- **`PullP24LeadsJob`** — scheduled every 15 minutes via `routes/console.php`, `withoutOverlapping()`.

## Permissions

- New key `access_portal_leads` registered in `config/corex-permissions.php`, gating sidebar entry + route middleware + controller actions.

## UI

- **Route**: `GET /real-estate/portal-leads` → `PortalLeadController@index`. JSON variant at `GET /real-estate/portal-leads/poll` for the toast poller.
- **Sidebar**: new entry under Real Estate, after Core Matches.
- **Page**: filters (portal/date/agent/status) + table with row expansion for full message + raw payload. Status badges: green "New Contact" / neutral "Already Exists".
- **Agent filter semantics (AT-308, Johan ruling (a))**: filtering by an agent returns every lead **on that agent's LISTINGS / stock** — the listing's primary agent OR the co-listing second agent (`pp_second_agent_id`), mirroring `PortalLead::agentIds()` and `scopeVisibleTo`. It does **NOT** match on the enquiring contact's existing agent. (The original filter matched listing-agent OR contact-agent, but the row **displays** the listing agent — so a lead on agent X's listing whose buyer already belonged to agent Y matched the "X" filter yet rendered "Y", making the filter look broken. Filter-semantics now equal display-semantics.) The lead always belongs to the listing agent; who owns the buyer relationship is informational, not a filter axis.
- **"Agent" column**: shows the **listing agent** (the lead's owner), matching the filter exactly.
- **Cross-agent badge (AT-308)**: when the enquiring buyer already belongs to a **different** agent than the listing agent, the row shows an informational badge — "⚑ Buyer known to <other agent>", plus the **contact-created date** (`contacts.created_at`) and **last-interaction date** (`contacts.last_contacted_at`, the AT-59 marker). This lets the receiving agent/BM judge keep-vs-move. It is **informational only** — the lead is never auto-reassigned; keep-vs-move is agency policy, a human decision.
- **Property Intelligence tab**: append a "Portal Leads" panel partial — total + per-portal counts + leads table newest-first.
- **Toast**: Alpine.js component polling the JSON endpoint every 30s for `agency_id`-scoped leads with `notified_at IS NULL`, marks them notified on display.

## Acceptance criteria

- A test `portal_leads` row appears on the Portal Leads page and on its property's Intelligence tab.
- A new P24 lead (via mocked API response) creates a Buyer contact, fires the event, and shows a toast.
- A new PP lead (via mocked webhook payload) creates a Lead contact AND a `portal_leads` row, also fires the toast — without modifying PP files.
- All `scripts/dev-check.ps1` tests pass with zero new failures.

## Files

**New**
- `database/migrations/2026_05_20_000001_create_portal_leads_table.php`
- `app/Models/PortalLead.php`
- `app/Services/Syndication/Property24/P24LeadService.php`
- `app/Jobs/Syndication/Property24/PullP24LeadsJob.php`
- `app/Events/Leads/NewPortalLeadReceived.php`
- `app/Listeners/Leads/MarkPortalLeadForNotification.php`
- `app/Observers/CommandTaskPortalLeadObserver.php`
- `app/Http/Controllers/CoreX/PortalLeadController.php`
- `resources/views/corex/portal-leads/index.blade.php`
- `resources/views/corex/properties/intelligence/_portal-leads.blade.php`
- `resources/views/components/portal-lead-toast.blade.php`

**Modified (non-PP)**
- `routes/web.php` — Portal Leads routes
- `routes/console.php` — schedule `PullP24LeadsJob` every 15 min
- `app/Services/Syndication/Property24/Property24ApiClient.php` — add `getLeads(?string $after)`
- `app/Providers/AppServiceProvider.php` (or `EventServiceProvider`) — register observer + event listener
- `config/corex-permissions.php` — register `access_portal_leads`
- `resources/views/layouts/corex-sidebar.blade.php` — new nav entry
- `resources/views/corex/properties/show.blade.php` — include Intelligence panel partial
- `resources/views/layouts/corex-app.blade.php` — mount toast component
