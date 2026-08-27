# E-sign regression harness

Drives all 6 e-sign flow shapes end to end on QA1, snapshotting the document
body at every link in the chain (Johan, 2026-08-27):

```
Template -> Property -> Recipients -> Details -> Fill & Review
  -> Sign & Send -> Preview -> Agent Signing Screen -> [rec 1 -> rec 2 -> ...]
```

Each link is diffed against the one before it. Two links (`Template ->
Property`, `Property -> Recipients`) are expected to add new content by
design and are not diffed strictly. Every other link must be byte-identical
to the one before it, aside from documented, stripped pagination furniture
(page numbers, signature-anchor role chips) on the live signing screen.

## Run it

```bash
cd /corex-qa1
node scripts/esign/regression/run.js                # all 6 shapes
node scripts/esign/regression/run.js --shape=D       # just shape D
node scripts/esign/regression/run.js --shape=B,C,D   # a subset
```

Requires the QA1 app reachable at `https://qatesting1.corexos.co.za` and
`php artisan tinker` runnable from the repo root (same box the app runs on).

## What it checks

- **The master chain assertion** (`0_MASTER_chain_holds_link_by_link`): the
  headline result. Names the exact link where the document body first
  diverges from the step before it, with before/after text.
- **Seven detailed assertions** (party consistency, no crossed identity, no
  duplicate Domicilium entries, deceased-in-clause-never-Domicilium, proxy
  expansion + signing-order count, blank-stays-blank, signature blocks) —
  these tell a lane WHAT specifically broke once the chain result says
  WHERE.

## What it does not (yet) check

- **The recipient-by-recipient signing chain** ("rec 1 matches agent, rec 2
  matches rec 1, ..."). This harness signs only as the agent (the one
  identity it's logged in as); completing every other party's signature in
  turn would need each recipient's own signing link, retrievable from
  Mailpit. Not built in the first pass under deadline — every run reports
  this honestly as "could not check" in that shape's notes, never as a
  silent pass.
- **The generated PDF**, for the same reason (needs full multi-party
  completion first).
- Agent-signing sometimes does not complete within this run's retry budget
  (Puppeteer flakiness on the per-field click loop, not a product issue as
  far as this harness can tell) — when that happens, `7_signature_blocks`
  reports `INCOMPLETE`, never a false pass.

## Test data

`fixtures.php` creates its own disposable, clearly-labelled contacts
("RegXxx HarnessFixture", email `@harness.test`) and ONE property
("REGRESSION HARNESS TEST PROPERTY — DO NOT EDIT"), idempotently — safe to
run before every harness invocation, never touches a real agent's data.
Deliberately does NOT link the fixture contacts to the property via
`contact_property` — see the comment in `fixtures.php` for why (the
property-select step auto-populates linked sellers as default recipients,
which contaminated the harness's own first run).

## Files

- `run.js` — CLI entry point, orchestrates everything, writes
  `reports/run-<timestamp>.json`.
- `shapes.js` — the 6 shape definitions (A–F).
- `fixtures.php` — idempotent disposable test-data setup.
- `lib/driver.js` — wizard-driving primitives (search, replace-modal,
  proxy, signing loop).
- `lib/capture.js` — page-state capture + parsing (Domicilium, clause,
  signature blocks) — reads the REAL rendered screen, never an internal API.
- `lib/assertions.js` — the master chain diff + the 7 detailed assertions.
- `lib/cookie.js` — mints a real session for user 22 via `php artisan
  tinker` (the same mechanism every manual verification pass this week
  used — not a new login, not a new user).

Read-only against the product itself: every flow stage and every assertion
is driven from / read off the real rendered UI. Fixture setup is the one
DB-level step, because creating Contacts/Properties is a different screen
from the one being regression-tested — see `fixtures.php`'s own header.
