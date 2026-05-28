# Map module — Owner PII egress map (Phase A — POPIA evidence artefact)

> **Audience:** internal compliance + PPRA file.
> **Branch:** `feature/map-workspace-overhaul`.
> **Date:** 2026-05-28.
> **Author:** Phase A audit for the Agent/Seller server-side gate.
>
> This document enumerates **every endpoint and payload** in the map module
> that can emit owner Personally Identifiable Information (PII) to the
> browser, the field-level shape of each leak, and whether that PII ships in
> **both** view modes today (= current live leak) or is already correctly
> suppressed.
>
> Owner PII on the map is governed by **POPIA** (lawful basis required to
> process personal information about a data subject) and the **PPRA Code of
> Conduct** (a property practitioner may not disclose another seller's
> personal details to a buyer or unrelated third party without basis). The
> Seller/Agent toggle is the technical control that enforces the gate: in
> Seller View the server **must never emit** the listed PII field set.
> CSS/JS hiding is non-compliant — the bytes must not leave the server.

---

## 1. The "Owner Detail" field set (what we must suppress in Seller View)

These are the fields that constitute owner PII for the map module. Any
appearance of any one of them in a Seller-View response is a live leak.

| Field | Description |
|-------|-------------|
| `owner_name`                 | Natural person's full name (scheme owner). |
| `owner_id_number`            | South African ID or passport (full or partially masked). |
| `owner_phone`                | Owner's contact number — wa.me deeplink source. |
| `owner_email`                | Owner's email address. |
| `purchase_date`              | Date the current owner acquired the property. |
| `purchase_price`             | Price the current owner paid (financial profile of the data subject). |
| `bond_holder`                | Bank holding the bond — financial profile. |
| `bond_amount`                | Bond amount — financial profile. |
| `bond_date`                  | Bond date. |
| `length_of_ownership`        | Derived from `purchase_date` — financial/personal profile signal. |
| `buyer_name`                 | Counterparty name on a sold comp (raw_row_json). |
| `seller_name`                | Counterparty name on a sold comp (raw_row_json). |
| `agent_name`/`agent_phone`/`agent_email` | Competitor listing agent contact (PPRA — disclosure of another practitioner's contact details to a seller without basis). |

Any payload field carrying or rendering one of these values must be
stripped server-side in Seller View. Display labels of fields not yet on
schema (e.g. ID number on `scheme_owners`) are also suppressed — disclosure
of a placeholder still indicates an audit/probing surface to a non-agent.

---

## 2. Trust source — viewMode flag

The Seller/Agent flag enters the system from two distinct request paths
today. Both currently **default to Agent (= PII-visible) when no flag is
supplied** — this is the most significant POPIA violation in the audit.

| Path | File:line | Source | Default | POPIA-safe? |
|------|-----------|--------|---------|-------------|
| Bounds pin fetch | `app/Http/Controllers/Map/MapController.php:55` (validation), `:118` (`MapBoundsRequest` construction) | `?viewMode=agent\|seller` query param | `'agent'` | **No — default unsafe.** Omitted param = Agent View = PII flows. |
| Card endpoints (×5) | `MapController.php:294, 330, 349, 411, 425, 528, 583, 640, 695` | `$request->query('viewMode', 'agent')` | `'agent'` | **No — default unsafe.** Each card endpoint independently reads the query string with the same unsafe default. |

`MapBoundsRequest::isSellerView()` at `app/Services/Map/MapBoundsRequest.php:94`
returns `$this->viewMode === 'seller'` — a strict equality test, so any
falsy/missing/unknown viewMode flag is treated as Agent. The flag itself is
never validated against a user permission on the server side; the only
gatekeeper today is the client (`localStorage.getItem('corex.map.view_mode')`
at `resources/views/corex/map/index.blade.php:420`).

**Required state (Phase B):**

1. Default `viewMode` on every endpoint = `'seller'` (default-safe).
2. Agent View only honoured for users with the **`access_prospecting`**
   permission key (the existing key that already gates access to the MIC
   module — the canonical surface where owner PII is otherwise visible).
   Any user lacking that permission gets Seller View even if they pass
   `viewMode=agent`.
3. Toggle in the UI re-fetches all open payloads from the server — the
   browser cache is purged on toggle. (The current Blade does this for
   pins and single-detail; composite list view is documented as not
   re-fetching — Phase B closes that gap.)

---

## 3. Egress map — per surface

### 3.1 `GET /corex/map/pins` — bounds pin fetch (the volume surface)

**Controller:** `app/Http/Controllers/Map/MapController.php::pins` (L46).
**Service:** `app/Services/Map/MapPinService::getPinsInBounds` (L60–133).

| Layer | File:line | Owner PII fields emitted | Suppressed in Seller View today? |
|-------|-----------|--------------------------|----------------------------------|
| `hfc_listings` (H) | `MapPinService::hfcListings` (L210–297) | none (subtitle is property metadata, not owner) | n/a — no PII |
| `sold_comps` (S) | `MapPinService::soldComps` (Mrcr + Psc branches) | None on the pin payload itself. `buyer_name`/`seller_name` only surface in the **card** endpoint (§3.3), not on the bounds pin. | n/a — no PII on pin |
| `active_listings` (P / A) | `MapPinService::activeListings` | none on the pin payload | n/a |
| `mic_subjects` (M) | `MapPinService::micSubjects` | none on the pin payload | n/a |
| `scheme_owners` (O) | `MapPinService::schemeOwners` (L756–814) + `toRecord` (L162–207) | **`subtitle = owner_name`** (pin L805), plus the record-shape `owner_phone` + `owner_email` keys (always null on the pin layer because the table has no phone/email columns, but the keys themselves are always present in the payload — defaulted by `toRecord` at L190–191). | **Yes — partially.** `MapPinService::redactSchemeOwnerIdentity` (L150–160) sets `subtitle/owner_name='Owner'` and `owner_phone/owner_email=null` when `$req->isSellerView()`. This requires the `viewMode=seller` flag to actually be on the request — see §2 trust-source caveat: **the default behaviour for missing/malformed flags is Agent, so a non-authorised browser receives the owner_name in subtitle.** |
| `tracked_properties` (T) | `MapPinService::trackedProperties` | none on the pin payload itself | Layer dropped wholesale in Seller View at `MapPinService.php:93` — already correct. |

**Cluster/composite — `LocationGrouper::buildHoverSummary`**
(`app/Services/Map/LocationGrouper.php:413–500+`): builds composite hover
text from `rec['title']` + `rec['subtitle']`. For scheme_owners the
subtitle has already been redacted upstream when Seller View is honoured
— so the hover/cluster tooltip text inherits the redaction. **No
independent PII channel.**

### 3.2 `GET /corex/properties/{property}/map-card` — HFC listing card

**Controller:** `MapController::propertyCard` (~L286+).
- `facts[]`: address/type/price/status/agent_id. No PII.
- `sensitive_facts[]` (L318–322, set when `viewMode === 'agent'`):
  `['label' => 'Listing agent', 'value' => 'Agent #' . $property->agent_id]`.
  An *internal* agent identifier (HFC user id), not a contact detail. Low
  risk, but still strips in Seller View today.
- **Live leak?** No. Sensitive section is empty in Seller View.

### 3.3 `GET /corex/map/sold/{layerId}` — Sold comp card

**Controller:** `MapController::soldCard` (L327) →
`soldCardFromMrcr` (L514–567) **or** `soldCardFromPsc` (L569–626).

| Branch | File:line | sensitive_facts fields | Suppressed in Seller View today? |
|--------|-----------|------------------------|-----------------------------------|
| `mrcr:*` | L561–565 | empty array (no columns on MRCR for buyer/seller today) | Yes (empty regardless). |
| `psc:*` | L617–624 | `buyer_name`, `seller_name`, `agent_name` from `raw_row_json` | Yes — `sensitive_facts` unset at L340 when `viewMode !== 'agent'`. **Default still Agent-unsafe (§2).** |

### 3.4 `GET /corex/map/active/{layerId}` — Active listing card

**Controller:** `MapController::activeCard` (L346) → `activeCardFromMrcr`
(L628–681) **or** `activeCardFromPal` (L683–758).

| Branch | File:line | sensitive_facts fields | Suppressed in Seller View today? |
|--------|-----------|------------------------|-----------------------------------|
| `mrcr:*` | L666–669 | empty array (none on schema) | Yes. |
| `pal:*` | L741–747 | `agent_name`, `agent_phone`, `agent_email` from `raw_row_json` (competitor practitioner contact) | Yes — `sensitive_facts` unset at L357 when `viewMode !== 'agent'`. **Default still Agent-unsafe (§2).** |

### 3.5 `GET /corex/map/mic-subject/{report}` — MIC subject card

**Controller:** `MapController::micSubjectCard` (L363–415).
- `sensitive_facts[]` (L411–413): empty array, gated by `viewMode === 'agent'`.
- No owner PII emitted.
- **Live leak?** No.

### 3.6 `GET /corex/map/scheme-owner/{owner}` — Scheme owner card (highest-risk endpoint)

**Controller:** `MapController::schemeOwnerCard` (L422–496).

`sensitive_facts[]` is populated at L470–492 when `$isAgentView` is true:

| Field on `sensitive_facts` | Source |
|----------------------------|--------|
| `Owner`                    | `$owner->owner_name` |
| `ID number`                | `maskIdNumber($owner->owner_id_number)` + `value_raw` (full ID, unmasked) for copy + `va_lookup` flag |
| `Phone`                    | `$owner->owner_phone` |
| `Email`                    | `$owner->owner_email` |
| `Date acquired`            | `$owner->purchase_date` |
| `Purchase price`           | `$owner->purchase_price` |
| `Length of ownership`      | derived |
| `Bond holder`              | `$owner->bond_holder` |
| `Bond amount`              | `$owner->bond_amount` |
| `Bond date`                | `$owner->bond_date` |

**Suppressed in Seller View today?** Yes — entire `sensitive_facts` block is
omitted when `$isAgentView === false` (L463). **But the default in the
absence of a flag is `'agent'` (L425) → unauthorised callers with no
viewMode param receive the entire block.**

Additionally — Seller View **still** returns the `subtitle` as `'Sectional
Scheme unit'` (L447) and the facts include `Building address` from the
joined `market_reports` row — both POPIA-safe (building-level, not unit-
level identity).

### 3.7 `POST /corex/map/activity/log` — fire-and-forget audit logging

**Controller:** `app/Http/Controllers/Map/MapActivityController.php`.
- Server-side audit-trail writer for events like
  `contact_owner_launched`, `id_copied`, `prospect_launched`, etc.
- Returns only `logged: true, event_id, ...` and (for prospect_launched)
  a `redirect_url` to opportunities.show / seller-outreach compose.
- **No owner PII ships back to the client.** The `id_copied` event
  explicitly records that an ID was copied without ever receiving the
  value (per the L243–246 comment, the value stays in the agent's
  clipboard). The `contact_owner_launched` handler loads a SchemeOwner
  internally to fire `MapContactOwnerLaunched`, but only returns the
  event id.
- **Live leak?** No.

### 3.8 `GET /corex/presentations/{presentation}/spatial-pins`

**Controller:** `MapController::presentationPins` (L175–283).
- Returns subject + sold + active pin arrays with
  `id, layer, lat, lng, title, subtitle, price, date, detail_url`.
- `title` = `raw_row_json['address']` (property address — not owner).
- `subtitle` = price/date formatted text.
- **No owner PII in the bounds-style payload.** Drill-down to the card
  endpoint inherits the gates in §3.3/§3.4.
- **Live leak?** No.

### 3.9 `GET /corex/map/saved-searches` (index/store/update/destroy)

**Controller:** `MapSavedSearchController` (out of scope for owner PII —
saves filter state, not record data).

### 3.10 Cluster tooltip / heat-layer tooltip

There is no dedicated cluster or heat-layer tooltip endpoint. The hover
text on a cluster (`hover_summary` in §3.1) is built from the same
`title` + `subtitle` strings that flow through the
`MapPinService::redactSchemeOwnerIdentity` redaction. **No independent
PII channel exists.** Phase B does not need to add a new server gate
here — keeping the redaction at the record level guarantees the
downstream summary is clean.

### 3.11 Map exports

There is no server-side export endpoint at the time of this audit
(`grep -rn 'map.export\|map_export'` returns no matches). If one is
added later, it must extend the same gate as the bounds endpoint and
suppress the owner PII field set (§1) in Seller mode.

---

## 4. Summary — live leaks vs. correctly-gated surfaces

| Surface | Owner-PII present | Today: gated correctly? | Phase B fix |
|---------|-------------------|--------------------------|-------------|
| `/corex/map/pins` — `scheme_owners` subtitle | yes (`owner_name`) | **partial** — gate exists but default is Agent | invert default to Seller; honour Agent only with `access_prospecting` |
| `/corex/map/scheme-owner/{owner}` | yes (full owner dossier) | **partial** — gate exists but default is Agent | as above |
| `/corex/map/sold/{layerId}` (psc) | yes (`buyer/seller/agent_name`) | **partial** — same default issue | as above |
| `/corex/map/active/{layerId}` (pal) | yes (competitor `agent_name/phone/email`) | **partial** — same default issue | as above |
| `/corex/properties/{id}/map-card` | minimal (agent_id internal ref) | partial — same default issue | as above |
| `/corex/map/mic-subject/{report}` | none on payload | yes (empty sensitive_facts) | tighten anyway for consistency |
| `/corex/presentations/{p}/spatial-pins` | none | yes | none required |
| `/corex/map/activity/log` | none returned | yes | none required |
| Cluster/heat tooltips | none independent | yes (inherits record redaction) | none required |
| Exports | n/a (no endpoint) | n/a | future-proof — same gate when added |

---

## 5. Permission model selection

Per the prompt instruction *"use the existing permission model; if none
fits, report — don't invent one"*: the existing key **`access_prospecting`**
(`config/corex-permissions.php:305`) is the correct gate for Agent View on
the map.

**Why:** Owner PII on the map originates from `scheme_owners` rows (which
live in the MIC / Prospecting module) and from `raw_row_json` on
presentation comps (also a Prospecting/MIC artefact). Any user with
`access_prospecting` already has full visibility of owner PII through the
MIC opportunity surfaces, scheme owner detail pages, and the BM team
dashboard. Gating map Agent View on the same key means the map honours
the same compliance boundary as the rest of the MIC surface — no new
permission to coordinate with PPRA, no role-matrix churn.

**Roles that already hold `access_prospecting` today:**
`super_admin` (wildcard), and explicitly granted to broker/manager/agent
roles at `config/corex-permissions.php:539, 613, 664`.

**Users without `access_prospecting`** (e.g. compliance-only, finance-only,
external client logins) get Seller View regardless of any client-side
toggle attempt — payloads omit the PII field set entirely.

---

## 6. Field-level test matrix for Phase B "prove" step

For each row below, the test asserts the **payload at the JSON-network
layer** (not the rendered UI), so the bytes-on-the-wire compliance is
verified, not merely CSS state.

| Test | Caller | viewMode flag | Permission | Expected payload contents |
|------|--------|---------------|------------|---------------------------|
| T1 — Seller default | bounds endpoint | omitted | `access_prospecting` granted | `scheme_owners` pin `subtitle = 'Owner'`, no `owner_phone/email` keys leaking beyond `null`. Default = safe. |
| T2 — Seller explicit | bounds endpoint | `seller` | `access_prospecting` granted | Same as T1. |
| T3 — Agent (authorised) | bounds endpoint | `agent` | `access_prospecting` granted | `subtitle = owner_name`, `owner_phone/email` carried if present on row. |
| T4 — Agent (unauthorised) | bounds endpoint | `agent` | permission missing | **Seller-shaped response.** Override the request — non-authorised callers cannot lift the gate via querystring. |
| T5 — Scheme-owner card, Seller default | `/corex/map/scheme-owner/N` | omitted | `access_prospecting` granted | `sensitive_facts` key absent or empty. |
| T6 — Scheme-owner card, Agent authorised | `/corex/map/scheme-owner/N` | `agent` | granted | Full `sensitive_facts` block. |
| T7 — Scheme-owner card, Agent unauthorised | `/corex/map/scheme-owner/N` | `agent` | denied | `sensitive_facts` absent (server-overridden to Seller). |
| T8 — Psc sold card, Seller default | `/corex/map/sold/psc:N` | omitted | granted | `sensitive_facts` absent — no `buyer_name`/`seller_name`. |
| T9 — Pal active card, Seller default | `/corex/map/active/pal:N` | omitted | granted | `sensitive_facts` absent — no competitor agent contact details. |
| T10 — Pin with no owner data | bounds | either mode | either permission | Pin renders normally; null owner fields don't 500 the response. |
| T11 — Toggle refetch | bounds | switch agent→seller | granted | Cache cleared client-side; next fetch returns Seller-shaped payload server-side. |

---

## 7. Phase B implementation deltas (preview — not part of Phase A)

1. **`MapController::resolveViewMode(Request)`** — single private helper
   that all card endpoints + the bounds endpoint call. Returns `'agent'`
   only when (a) request asks for `viewMode=agent` **and** (b) user has
   `access_prospecting`. Otherwise returns `'seller'`.
2. Invert all `query('viewMode', 'agent')` defaults to call the helper.
3. Move the `MapBoundsRequest::viewMode` derivation to the same helper so
   the bounds endpoint shares the gate.
4. `MapPinService::redactSchemeOwnerIdentity` retains its current shape —
   the upstream change ensures it's invoked correctly.
5. **Bounds pin payload — `toRecord` (L162–207)** is left as-is for
   non-PII fields; the existing `redactSchemeOwnerIdentity` upstream + the
   `tracked_properties` Seller-View suppression already cover the leaks
   from that surface.
6. Sold/active card endpoints: when computing `sensitive_facts`, use the
   resolved viewMode (not the raw querystring).
7. Front-end (`resources/views/corex/map/index.blade.php`): the Agent
   View toggle pill is only rendered/active when the server flags the
   user as authorised (a `data-can-see-agent-view` boolean on the page
   root, set from the controller). Unauthorised users see Seller-only.

Phase B implementation follows in the same prompt; this file is the
compliance artefact for the PPRA file.
