# Spec — External-Agency Viewing Pack (AT-367 follow-up)

**Status:** DRAFT — awaiting Johan's approval. **No code written.** Spec only.
**Author:** Claude (ESIGN/viewing-pack lane) · **Date:** 2026-08-04

---

## 1. The problem (business requirement)

Today a viewing/buyer pack is always built from a **contact in our own Buyers Pipeline** — the only
entry point is *Buyer Pipeline → open a buyer → "Build Viewing Pack"*, which POSTs a `contact_id`.
The pack, its title, its filename, and the cover's "Prepared for …" line all read from that contact.

But a common case has no contact: **an external agency phones us wanting to show THEIR buyer one of
OUR listed properties.** We still want to produce the same property pack (photos, one-pager, redacted
docs) — but we must **not** create a contact record for their buyer (they are not our client and do
not belong in our pipeline, our matching, our portal, or our reporting).

So: **produce a viewing pack that is tied to a PROPERTY (and optionally the external agency/agent's
name), not to one of our buyer contacts.** Identical output; different origin.

---

## 2. Guiding principle

The pack-generation pipeline is already **property-driven**, not contact-driven: properties are added
per pack, and the buyer-pack PDF / agent sheet / redaction all render from the properties. The contact
is used in only three narrow places — the **title**, the **cover "prepared-for" label / filename**, and
the **Core-Matches suggestion list** on the pack screen. So the change is small: **make the contact
optional, and where a contact is missing, fall back to the external agency/agent name.** Everything
downstream is reused unchanged.

`show()` already tolerates a null contact — Core Matches are guarded with `$buyer ? … : collect()`
(`ViewingPackController.php:87`). That is the shape the rest of the change follows.

---

## 3. Where it is initiated from

**Primary entry point: the Property page** ("show THEIR client one of OUR properties" starts from the
property). Add a **"Viewing pack for an external agent"** action on the property view. It opens a tiny
form with:
- the property **pre-selected** (the one they're on),
- **External agency name** (required — who asked),
- **External agent name** (optional),
- **Their buyer's name** (optional free-text label only — NOT a contact; purely to title the pack, e.g.
  "for J. Smith's buyer"). May be left blank.

Submitting creates the pack (property already attached) and drops the agent straight onto the normal
pack screen to add more properties / download — same as today.

**Secondary entry point (optional, low cost): the Viewing Packs index** — a "New external-agency pack"
button that opens the same form with an empty property picker. Nice-to-have; the property-page button
is the one that matches the real workflow. *(Johan to confirm whether both are wanted.)*

There is **no calendar involvement** — external packs are not tied to one of our appointments or
contacts, so the calendar "Create/Download viewing pack" states (contact-driven) are untouched.

---

## 4. Minimal info captured (no contact record)

An external pack stores only:
- `property_id`(s) — via the existing `viewing_pack_properties` rows (unchanged),
- `external_agency_name` (string),
- `external_agent_name` (string, nullable),
- `external_buyer_label` (string, nullable — free text, never a contact),
- `agent_id` / `agency_id` / `branch_id` — OUR owning agent + agency (unchanged),
- `contact_id` = **NULL**.

No `contacts` row is created. No portal link, no matching, no buyer-pipeline entry.

---

## 5. How it reuses the existing pack generation

Unchanged and reused verbatim:
- Add/remove/reorder properties, search properties (`viewing_pack_properties`).
- Document eligibility + **redaction** (`ViewingPackRedactionService`).
- **Buyer-pack PDF** (`ViewingPackBuyerPdfService`) and **agent sheet** (`ViewingPackAgentPdfService`) —
  property-driven; the only contact touch is the cover's name label (see §7).
- `show()` screen, archive/restore, visibility scoping (`isVisibleTo`), permissions.

The ONLY behavioural forks needed are: (a) allow a null contact; (b) render the external name where the
buyer name would go; (c) skip the contact-only features (Core Matches, buyer-portal link) when external.

---

## 6. What gets stored / tracked

- The pack is a normal `viewing_packs` row, distinguishable as external by `contact_id IS NULL`
  (add a tiny `ViewingPack::isExternal()` accessor for clarity).
- `external_agency_name` / `external_agent_name` / `external_buyer_label` live on the pack row.
- Audit/where-it-came-from: reuse the existing pack timestamps + `agent_id`. Optionally a `source`
  enum on the pack (`buyer_pipeline` | `external_agency`) if Johan wants an explicit tag rather than
  inferring from `contact_id IS NULL` — inferring is enough for v1.
- Reporting: external packs should be **excluded** from buyer-centric metrics (they have no contact);
  because they carry no `contact_id`, existing contact-joined reports naturally skip them.

---

## 7. Rendering / label changes (the only contact-coupled spots)

A single "display name" resolver decides what the pack is *for*:
- if `contact_id` set → the contact's name (today's behaviour),
- else → `external_agent_name` (or `external_agency_name`) + optional `external_buyer_label`,
  e.g. **"External agent — Acme Realty (J. Smith's buyer)"**.

Touch points that must use this resolver instead of the raw contact:
- `ViewingPackController::defaultTitle()` — currently typed `defaultTitle(Contact $buyer)`; make it accept
  a pack (or a resolved name) so an external pack gets a sensible title.
- `ViewingPackBuyerPdfService::buyerName()` — cover "prepared for" + PDF filename; fall back to the
  external label (it already falls back to 'the buyer' when empty, so this is a natural extension).

Everything else on the cover (agency logo, agent photo/name/contact — OUR agent) is unchanged.

---

## 8. Smallest clean change — file touch-list

**Migration (1 new file)**
- `database/migrations/xxxx_add_external_agency_to_viewing_packs.php`
  - `contact_id` → make **nullable** (change_column; keep the FK, nullOnDelete).
  - add `external_agency_name` (string, nullable), `external_agent_name` (string, nullable),
    `external_buyer_label` (string, nullable). *(Optional `source` enum — Johan's call.)*
  - run `php artisan schema:dump` after (test-bootstrap snapshot, non-negotiable #12a).

**Model**
- `app/Models/ViewingPack.php` — add the new columns to `$fillable`; add `isExternal(): bool`
  (`contact_id === null`) and `displayName(): string` (contact name OR external label). Make the
  `contact()` relation null-safe at call sites (already is in `show()`).

**Controller**
- `app/Http/Controllers/CommandCenter/ViewingPackController.php`
  - new `storeExternal(Request)` — validates `property_id` + `external_agency_name` (+ optional agent /
    buyer label), creates a `contact_id = null` pack, attaches the property, redirects to `show`.
    (Mirrors `store()` minus the contact.)
  - `defaultTitle()` — accept a pack / resolved name so external packs title correctly.
  - guard the two contact-only features for external packs: Core Matches (already guarded) and any
    **buyer-portal-link** creation (skip when `isExternal()`).

**Routes / nav / permissions**
- `routes/web.php` — one route `POST /viewing-packs/external` → `storeExternal`, gated by the existing
  `viewing_packs.create` permission (no new permission needed).

**PDF render**
- `app/Services/ViewingPack/ViewingPackBuyerPdfService.php` — `buyerName()` falls back to the external
  label. (Agent sheet inherits the same helper if shared.)

**UI (entry point)**
- `resources/views/corex/properties/show.blade.php` (or the property view in use) — add the "Viewing
  pack for an external agent" button + a small form/modal (property pre-filled + external name fields)
  POSTing to the new route.
- *(optional)* `resources/views/command-center/viewing-packs/index.blade.php` — "New external-agency
  pack" button opening the same form.
- `resources/views/command-center/viewing-packs/show.blade.php` — show the external agency/agent label
  where the buyer name currently appears (via `displayName()`); the Core-Matches panel simply renders
  empty/hidden for external packs.

**No changes** to redaction, property add/remove/reorder, document eligibility, or the calendar flow.

---

## 9. Acceptance criteria

1. From a property, an agent can start a viewing pack for an external agency **without creating a
   contact** — `contacts` table gains no row; the pack has `contact_id = NULL` + the external names.
2. The pack screen, buyer-pack PDF, agent sheet, and redaction all work identically to a normal pack;
   the cover/title/filename read the external agency/agent label instead of a buyer name.
3. External packs never appear in the Buyers Pipeline, never generate a buyer-portal link, and show no
   Core Matches.
4. Existing buyer-pipeline packs are completely unaffected (contact path unchanged).
5. Multi-tenancy intact — external packs carry OUR `agency_id`/`branch_id`; scoping/visibility unchanged.

## 10. Deliberately NOT in scope (v1)

- No external-contact CRM record, no portal access for the external buyer, no matching/prospecting.
- No new permission key (reuse `viewing_packs.create` / `viewing_packs.view`).
- No calendar/appointment linkage for external packs.
- No reporting dashboard for external packs beyond their natural exclusion from buyer metrics.

---

## 11. Open questions for Johan

1. Entry points: property page only, or **also** a "New external pack" button on the Viewing Packs index?
2. Do you want an explicit `source` tag on the pack, or is inferring "external = no contact" enough?
3. Cover label wording — "External agent — {Agency} ({buyer label})" or your preferred phrasing?
