# Contact Consent — tri-state ledger + client self-service

> Module spec. Pillar: **Contact**. Read with `.ai/specs/compliance.md` and
> `.ai/specs/client-auth.md`.
> Last updated: 2026-06-18 (Andre)

---

## 1. What this does and why

POPIA/CPA require a per-contact record of what the person has consented to and,
crucially, what they have **refused**. The original implementation modelled
consent as a single boolean per type: an active `contact_consent_records` row
meant "consent given"; its absence meant "not given". There was no way to record
an explicit **"No — do not contact me this way"** as distinct from "we never
asked", and the client themselves could not see or set any of it — only an agent
could, from the web Contact page.

This spec introduces a **tri-state consent ledger** and exposes it to the client
through the mobile client portal:

| State | Meaning | UI |
|-------|---------|----|
| `given` | The person agreed | green |
| `declined` | The person explicitly refused — "do not contact me this way" | **red** |
| *not recorded* (no active record) | Never captured | neutral |

The same ledger is read and written by the agent (web + agent-mobile) and by the
client (client mobile portal). A change on either side is immediately visible on
the other — there is one source of truth on the Contact pillar.

## 2. Pillars

- **Contact** — owns the consent ledger (`contact_consent_records`) and the
  denormalised channel opt-out flags. Reads + writes.
- Cross-pillar: emits `Contact\ContactConsentChanged` (domain events catalogue)
  on every change so downstream reactions stay decoupled.

## 3. Data model

`contact_consent_records` gains two columns (migration
`2026_06_18_120000_add_decision_and_source_to_contact_consent_records`):

| Column | Type | Notes |
|--------|------|-------|
| `decision` | `enum('given','declined')` default `given` | The tri-state value. Existing rows backfill to `given` (they were all "active = given"). |
| `source` | `string(30)` nullable | Audit origin: `agent_web`, `agent_mobile`, `client_app`, `public_link`, `import`, `system`. |

`given_by_user_id` is made **nullable** — a client self-service write has no
`User` actor. `source = client_app` + `given_by_user_id = null` is the honest
record that the client set it themselves.

The 7 consent types are unchanged (centralised on `Contact::CONSENT_TYPES`):
`fica_processing`, `marketing_communications`, `data_sharing`, `channel_email`,
`channel_sms`, `channel_whatsapp`, `channel_call`.

**One active record per type.** Writing a new decision *supersedes* the prior
active record (`revoked_at` stamped, reason `Superseded by new decision`) and
appends a fresh row. The full given→declined→given history is preserved as the
audit chain; the current state is always the single non-revoked row.

### Enforcement

- **4 channel types** enforce immediately: `recomputeChannelConsent()` sets
  `opt_out_email/sms/whatsapp/call = true` whenever the latest active record is
  `declined` (or absent), which `canSendVia()` already honours across the
  comms layer.
- `fica_processing`, `data_sharing`, `marketing_communications` are recorded and
  surfaced (red/green) but do **not** yet auto-trigger a downstream gate — see §7.

## 4. Model API (`App\Models\Contact`)

- `setConsent(type, decision, method='electronic', userId=null, source='agent_web', documentId=null): ContactConsentRecord`
  — supersede + append; observer recomputes channel flags.
- `clearConsent(type, userId=null, reason=null): void` — revoke the active record
  (back to *not recorded*) and recompute.
- `consentDecision(type): ?string` — `given` | `declined` | `null`.
- `consentStates(): array` — every type with its decision + meta (for the API).
- `recordConsent(type, method, userId, documentId=null)` — retained, delegates to
  `setConsent(..., decision=given, source=system)` for existing callers
  (`MarketingConsentService::optInContact`).

## 5. Agent web (Contact page → Consent tab)

Each type renders its current state (green `Given` / red `No` / neutral
`Not recorded`) with two buttons — **Given** and **No** — plus a **Clear** link.
Posts to `corex.contacts.consent.record` with `decision=given|declined`, or
`corex.contacts.consent.revoke` to clear. Red is the explicit-refusal signal the
agent must not miss.

## 6. Client mobile portal API

Auth: `auth:sanctum` + `client.ability` (a `ClientUser`). The signed-in client is
mapped to their Contact in the current agency via
`ClientPortalController::resolveContact()`. Every write is logged to
`contact_access_log` via `ClientAuthService::log()`.

### `GET /api/v1/client/consent`  (`client.consent.index`)
```json
{
  "agency_id": 12,
  "consents": [
    { "type": "channel_email",  "label": "Email",    "group": "channel",    "decision": "given",    "recorded_at": "2026-06-18T09:00:00Z" },
    { "type": "channel_sms",    "label": "SMS",      "group": "channel",    "decision": "declined", "recorded_at": "2026-06-18T09:01:00Z" },
    { "type": "channel_whatsapp","label": "WhatsApp","group": "channel",    "decision": null,       "recorded_at": null },
    { "type": "channel_call",   "label": "Phone Call","group": "channel",   "decision": null,       "recorded_at": null },
    { "type": "marketing_communications","label":"Marketing Communications","group":"marketing","decision":null,"recorded_at":null },
    { "type": "fica_processing","label":"FICA Processing","group":"compliance","decision":"given","recorded_at":"..." },
    { "type": "data_sharing",   "label":"Data Sharing","group":"compliance","decision":null,"recorded_at":null }
  ]
}
```

### `POST /api/v1/client/consent`  (`client.consent.update`)
Body: `{ "type": "channel_email", "decision": "given" | "declined" | "clear" }`
- `given` / `declined` → `setConsent(type, decision, 'electronic', null, 'client_app')`
- `clear` → `clearConsent(type, null, 'Cleared by client')`

Returns the same shape as the GET (the full refreshed list) so the app re-renders
from one response. `decision=declined` on a channel immediately flips the
`opt_out_*` flag, so the agency stops contacting the client that way the moment
they tap **No**.

Errors: `409` (no agency selected), `404` (no Contact in agency), `422`
(bad type/decision), `401` (missing `client` ability).

## 7. Deliberately deferred (flagged, not shipped half-built)

Bridging a `marketing_communications = declined` into the **seller-outreach
send-gate** (`MarketingConsentService` / `MarketingSuppression`) is NOT wired in
this build. The existing `optInContact` is an all-or-nothing "resume ALL
messaging" that re-grants channel consents — bridging the granular marketing
toggle to it would clobber a client's per-channel choices. A correct bridge needs
its own granular opt-in path. Until then marketing decline is recorded and shown
but does not auto-suppress outreach. Owner: Johan to approve the granular bridge
design.

## 8. Acceptance criteria

- [ ] Migration adds `decision` + `source`; existing rows read as `given`.
- [ ] Agent web consent tab shows green/red/neutral and writes both decisions.
- [ ] `GET /api/v1/client/consent` returns all 7 types with the client's decisions.
- [ ] `POST /api/v1/client/consent` sets given/declined/clear; a channel decline
      flips the matching `opt_out_*` flag (verified via `canSendVia`).
- [ ] A write on the client side is visible on the agent web page and vice versa.
- [ ] Endpoints appear in Admin → API (`/admin/api`) automatically.
- [ ] `ClientConsentTest` passes.

## 9. Files

- `database/migrations/2026_06_18_120000_add_decision_and_source_to_contact_consent_records.php` (new)
- `app/Models/ContactConsentRecord.php` (decision/source fillable + consts)
- `app/Models/Contact.php` (setConsent/clearConsent/consentDecision/consentStates, recompute, CONSENT_TYPES)
- `app/Observers/ContactConsentRecordObserver.php` (correct event payload)
- `app/Http/Controllers/CoreX/ContactController.php` (recordConsent accepts decision)
- `resources/views/corex/contacts/show.blade.php` (consent tab tri-state UI)
- `app/Http/Controllers/Api/V1/ClientPortalController.php` (consentIndex/consentUpdate)
- `routes/api.php` (client consent routes)
- `tests/Feature/Api/Client/ClientConsentTest.php` (new)
