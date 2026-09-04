# E-sign test matrix — Party Shape 3: company with directors, proxy signs

Owner: cc2. Fixture and standard for the 7-journey-variant × {single, web pack} suite,
run once cc1's browser harness (`/corex-staging/.ai/harness/esign/`) is available.

## Setup recipe (confirmed against current code, 2026-08-30)

1. Proxy nomination is **per-document, never permanent**. In the parties step
   (`resources/views/docuperfect/esign/wizard.blade.php:694-731`), an entity/company
   recipient row has a "Proxy — signs on behalf of the others in this role" checkbox
   (`x-model="r._is_proxy"`). Ticking it reveals a radio list of that entity's linked
   representatives; picking one writes `r._entity_proxy_contact_id` on the recipient row
   only — never back to the `contact_representatives` pivot. A fresh document for the
   same company always starts unproxied; the pick must be made every time.
2. Server-side, `ESignWizardController::expandEntityRecipients()` (`:4478`) reads that
   pick (`$overrideProxyRepId`, `:4540`) and resolves signers via
   `Contact::signingRepresentatives($overrideProxyRepId)` (`:4552`) — public wrapper
   around the private `proxyAwareRepresentatives()` (`app/Models/Contact.php:830`).
3. **CONFIRMED TRUE, unchanged**: when a proxy resolves, `$levelReps = collect([$proxy])`
   (`Contact.php:889`) — the collapsed directors never enter the recipient-building loop,
   so they never get a `SignatureRequest` row. Not a bug, deliberate design
   (`Contact.php:857-866`). Verified live against the fixture below: 3 signers with no
   proxy set, exactly 1 with a proxy set.

## The fixture (Staging, throwaway, clearly named)

- Company: Contact id **17719** — `TEST FIXTURE — AT-Matrix Proxy Holdings (Pty) Ltd`,
  entity, reg no `2026/999888/07`.
- Directors, all linked via `contact_representatives` with `capacity = 'Director'`,
  `signs_as_proxy = false` (proxy is picked per-document in the wizard, not persisted):
  - **17720** — TESTFIX Alpha Director (ID 8001015800081) — **this is the one who will be
    picked as proxy** in the wizard for every test run.
  - 17721 — TESTFIX Beta Director (ID 8202026800082)
  - 17722 — TESTFIX Gamma Director (ID 8403037800083)

Verified via `Contact::signingRepresentatives()`:
- No proxy override → **3** signers (all directors).
- Proxy override = 17720 (Alpha) → **1** signer (Alpha only). Confirms exactly the
  right number of signing parties before any browser test runs.

## What "correct" looks like on the finished document (the standard to check against)

Verified live via `RoleBlockExpansionService::composeEntityPartyText($company, true, 17720)`:

> TEST FIXTURE — AT-Matrix Proxy Holdings (Pty) Ltd (Reg: 2026/999888/07), herein
> represented by TESTFIX Alpha Director (ID: 8001015800081, Director), duly authorised
> representative, TESTFIX Beta Director (ID: 8202026800082, Director) and TESTFIX Gamma
> Director (ID: 8403037800083, Director)

So the finished document, wherever it names the seller party, must show **all three
directors by name and ID**, joined "X, Y and Z" (comma between, "and" before the last),
and the suffix **", duly authorised representative"** attached to Alpha's entry only —
Beta and Gamma get no such suffix. The company name + registration number precede the
list. This is order-independent (natural pivot-creation order, not proxy-first) — what
matters is that all three names appear, and exactly one (Alpha) carries the authority
phrase.

**Signing-slot standard**: the finished document's signature block/page must show
signature UI/space for **the proxy (Alpha) only** — no dangling unsigned line for Beta or
Gamma, and no signature request ever sent to Beta or Gamma's email/portal.

## Still to do (blocked on cc1's harness)

All 7 journey variants × {single document, web pack} = 14 runs, checked against the two
standards above (signer count = 1, clause names all 3 with the suffix on Alpha only).
Nothing run yet — holding for the harness path.
