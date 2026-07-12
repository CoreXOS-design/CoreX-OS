# E-Sign Contract Walk-Test — conductor task (ready to run)

> **Status:** PLAN OF RECORD (Johan, 2026-07-11). Ready to fire on the rig **tonight, after the
> DR2 gate walk clears**.
> **Owner:** the conductor (m4). **Environment:** **qa1**.
> **Time:** ~1 session. **Blocks:** every Phase-1 estimate in the e-sign build plan.
> **Reads with:** `.ai/audits/2026-07-11-esign-v3-gap-analysis.md` (§0-A, §6) ·
> `.ai/specs/esign-ceremony-v3.md` (canonical, `a346eba1` + `2cc8ca85`).

---

## Why this test is the starting line

The **contract-driven role-block engine** landed in late May and is on `main` **and** `Staging`:

| Commit | What it does |
|--------|--------------|
| `4d5eb28c` | CDS importer **stamps the `data-role-block` contract on every import** |
| `1fe10836` | **Contract-driven renderer** — `expandWithLooping()` queries `//*[@data-role-block]` first; clustering / LCA guessing demoted to a fallback |
| `02c8f5fb` | **Docs only** (105 lines) — *documents* the one-time backfill command |

The May spec defined a walk-test to prove this end-to-end. **It never ran.** Until it does,
"the contract engine is built" is an untested claim, and the whole mandate-ceremony lane is
sized on faith.

### Two corrections to the test as originally written — read before starting

1. **The subject changes.** The May spec names **template 111**. **Template 111 does not exist** —
   not on live, staging or qa1, not even soft-deleted. Nor do **116 / 117 / 119** (the ids the
   settled spec names as the *Sales Mandate Pack composition*). They are repo blade-file and
   seeder artifacts that never reached a database. **Do not hunt for them.**
   → **Run on a freshly imported EATS.** It is a real multi-party mandate *and* the actual launch
   document, so the test doubles as the first step of the import work (build-plan L1.1).

2. **The starting state is honest, and it is zero.** `data-role-block` appears on
   **0 of 67 templates on staging, 0 of 64 on qa1, 0 of 65 on live**, and **0 blade views in the
   repo**. The backfill has **never been run**. Everything today renders through the **legacy
   clustering fallback**. This test is about the **contract** path — you are proving new
   behaviour, not regression-testing existing behaviour.

---

## THE KNIFE-EDGE SIGNAL

> ### ❌ `RoleBlockExpansionService: rendering unnormalised template via legacy clustering`
>
> **If this line appears in the log at any point during section B or C, the contract did not take
> and the test has FAILED.** It is the single sharpest pass/fail signal in the exercise. Tail the
> log for it throughout; do not rely on the UI looking right — the legacy path also produces
> plausible-looking output, which is exactly why this went unproven for six weeks.

```bash
tail -f storage/logs/laravel.log | grep -i "legacy clustering\|RoleBlockExpansion"
```

---

## Preconditions

- [ ] qa1 HEAD contains `4d5eb28c` and `1fe10836` — verify, don't assume:
      `git merge-base --is-ancestor 4d5eb28c HEAD && git merge-base --is-ancestor 1fe10836 HEAD`
- [ ] DR2 gate walk has cleared (this test follows it on the rig).
- [ ] Log tail running with the knife-edge grep above.
- [ ] Record the starting contract coverage on qa1 (expected: **0**).

---

## A. Import — proves `4d5eb28c` (the importer enforces the contract)

- [ ] Import the **EATS** source document through the CDS builder / importer.
- [ ] **`editor_state.tagged_html` contains `data-role-block`** — stamped **automatically**, with
      **no manual patching**. *(This is the entire point of commit A. If it needs a hand-edit, the
      importer is not enforcing the contract.)*
- [ ] `data-role-block-segment` hints present where the document has distinct role segments
      (identity / address / contact).
- [ ] The generated blade view carries the contract through to the rendered HTML.

## B. Multi-party session — proves `1fe10836` (the contract-driven renderer)

- [ ] Start an e-sign flow on the imported EATS with **two sellers**.
- [ ] **Wizard Step 4/5 renders per-party:** each seller gets their **own** role block, cloned from
      `[data-role-block="seller"]` — two identity blocks, two address blocks, correctly indexed
      (`seller_1_*` / `seller_2_*` / `__r2`).
- [ ] **ZERO legacy-clustering log lines.** ← the knife edge.
- [ ] **Signature surfaces:** `signature-line` renders **one line per recipient** for the seller
      role (two sellers → two lines); the practitioner line renders once.

## C. Recipient signing view — proves the surfaces are real and correctly attributed

- [ ] Seller 1 opens their token link: **their own block is highlighted / editable.**
- [ ] Seller 2's block is visible but **not editable by seller 1** — confirm `authoriseWebFieldWrite`
      denies the cross-party write **and** that the denial writes a `web_fields_save_denied` audit row.
- [ ] Per-page initials appear for the recipient, and **"Apply to All Pages" is NOT offered**
      (client consent profile — `isAgent` false).
      ⚠️ *Known limitation, do not treat as a pass:* this gate is **UI-deep only** today — there is
      no server-side rejection. Build-plan **P0-3** closes it.
- [ ] Seller 1 signs → **agent checkpoint fires** (`pending_agent_approval`) → `approveAndAdvance()`
      releases seller 2.
- [ ] On completion the document **files as its own record** with the correct `document_type_id`,
      linked to **both sellers** and the property.

## D. Fresh-import bonus — proves the cycle is closed, not patched

- [ ] Import a **second, previously-unseen** document.
- [ ] Run B and C against it **with no per-document code changes.**
- [ ] This is the real claim of the contract architecture: *the per-document patching cycle is
      over.* If this passes, the engine is trustworthy and Phase 1 holds its estimate.

## E. Backfill — proves the migration path for everything that already exists

- [ ] `php artisan docuperfect:normalize-templates --dry-run` on qa1 — inspect what it *would* do.
- [ ] Run it for real; confirm `data-role-block` lands in `editor_state.tagged_html`.
- [ ] **⚠️ The two-halves trap (AT-162 class).** The command writes **two** places:
      1. `editor_state.tagged_html` — **per-environment DB. Does NOT travel on a `git pull`.**
      2. **The blade view file on disk — which IS git-tracked.**
      → It must be run **locally**, the **rewritten blades committed**, *and* **re-run per
      environment** for the DB half. Verify nothing is lost or conflicted on the next `git pull`.
- [ ] Record which templates it **silently skips for having no `tagged_html`** (it will skip some —
      all three staging reference templates have none) and decide what happens to them.

---

## Exit criteria

**PASS** when a **freshly imported, never-before-seen multi-party document**:
- renders per-party role blocks and per-recipient signature surfaces,
- is signed by two parties through the agent checkpoint,
- files correctly against both contacts and the property,

**with ZERO legacy-clustering log lines and ZERO per-document code changes.**

**Until that is true, every Phase-1 estimate in the e-sign build plan is provisional.**

## On failure — what it costs

If the contract path does not take, **Phase 1 re-inflates** (the mandate long pole goes back from
**M** toward **L**) and the 1-August cut must be re-cut on the spot. Report the failing section
(A / B / C / D / E) and the first legacy-clustering log line — that pair localises the defect.
