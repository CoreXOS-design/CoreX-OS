# P1-0 Contract Walk-Test — BACKEND HALF: RESULTS

> **Ran:** 2026-07-12, m5 · **Environment:** qa1 (`corex_qa1`, branch QA1)
> **Scope:** the import + contract half (A, B, D, E + the knife-edge assertion).
> The visual half (C — recipient signing view in Chrome, own-block highlighting) is the
> conductor's, still outstanding.

---

## VERDICT: the contract engine WORKS. Phase 1's estimate HOLDS.

**But it is INERT on every real template in every database, and the EATS import is blocked
on a missing source file.** Both are input problems, not engine problems — which is the
good news, because input is exactly what P1-1 is for.

| Section | Result |
|---|---|
| **Knife edge — zero legacy-clustering lines** | ✅ **PASS** (and *proven meaningful*, see below) |
| A — importer stamps the contract, no hand-patching | ✅ PASS *(on correct input)* · ⛔ **BLOCKED on EATS** (no source file exists) |
| B — contract-driven per-party render | ✅ PASS |
| D — second unseen document, zero code changes | ✅ PASS |
| E — backfill | ⚠️ **VACUOUS — it finds 0 candidates** |
| C — recipient signing view | → conductor |

---

## Preconditions (verified, not assumed)

- qa1 HEAD = `e696151a` (branch QA1). Both engine commits are ancestors:
  `4d5eb28c` ✓ · `1fe10836` ✓
- **Starting contract coverage = 0**, exactly as the spec predicted:

| Env | Templates | with `tagged_html` | with `data-field` | with `data-role-block` |
|---|---|---|---|---|
| qa1 | 65 | 4 | **0** | **0** |
| live (`nexus_os`) | 65 | 4 | **0** | **0** |
| staging (`hfc_staging`) | 67 | 4 | **0** | **0** |

---

## THE KNIFE EDGE — and why the zero is trustworthy

> ❌ `RoleBlockExpansionService: rendering unnormalised template via legacy clustering`

**Contract path: 0 lines.** Every render — the two-seller mandate, the signature-surface
pass, and the second unseen document — emitted **zero** legacy-clustering lines.

**A zero is worthless unless the signal is wired, so I ran a NEGATIVE CONTROL first:** the
same document, same recipients, same renderer, but *without* the contract stamped. It
emitted the legacy-clustering line (**1 line**), as it must.

→ The line fires when the contract is absent and is silent when it is present. **The zero
means the contract path was genuinely taken.** This is the check that six weeks of
plausible-looking output hid.

---

## A — the normalizer stamps the contract automatically

Fed correctly-named CDS output (`seller_1_name`, `seller_1_address`, `seller_1_cell`,
`agent_1_name`), `RoleBlockNormalizer::normalize()` — the exact call the CDS publish path
makes (`TemplateController:598-599`) — stamped, with **no manual patching**:

- **4 role blocks** (`data-role-block="seller"` ×3, `"agent"` ×1)
- **4 segment hints** — `identity`, `address`, `contact` (all three signal types)

## B — the contract-driven renderer clones per party

Two sellers + one practitioner through `expandWithLooping()`:

- **role blocks cloned 4 → 7**
- **seller 2 indexed** — 5 × `__r2` fields
- **both identities present** — `seller_1` ×29, `seller_2` ×9
- **document grew by cloning** — 1,359 → 4,052 chars
- **signature surfaces**: the seller surface is emitted **per recipient** (2 seller-party
  surfaces from 1 in source); the practitioner surface renders too.
  ⚠️ *For the conductor's eye:* the agent `data-marker-party` attribute count came back as
  **2** for a single agent recipient. It may be legitimate (the wrapper column *and* an inner
  marker both carry it), but it is worth one look in the visual pass. Signature-surface
  cloning is owned by `SignatureSurfaceNormalizer`, **not** the role-block engine — a fact
  worth recording, because testing it against the wrong service produces a false failure.

## D — second, previously-unseen document, zero per-document code

A lease with **roles the first document never had** (`lessee` / `lessor`): auto-stamped
(3 blocks), rendered with **0 legacy lines**, cloned per lessee (`lessee_2` present) —
**same engine call, no per-document code.** This is the architecture's actual claim, and it
holds: *the per-document patching cycle is over.*

---

## ⛔ BLOCKER 1 — there is no EATS source document. Anywhere.

Section A says "import the **EATS** source document." **It does not exist.** A sweep of the
entire volume finds **96 `.docx` files and not one sales document** — the only sources in
the repo are 7 rental documents (Letting Mandate, Lease, Rental Application, Marketing
Permission, Letting Mandatory Disclosure). EATS #27 exists **only** as a `render_type=pdf`
overlay with page images, exactly as the field-intelligence harvest recorded.

**This blocks P1-1 (import EATS / Disclosure / FICA onto the web-CDS path), which the build
plan calls the mandate lane's long pole.** Johan / HFC must supply the source `.docx` for
**EATS, OTP, FICA and the Mandatory Disclosure**. Nothing on the sales side can be imported
until they land. *(This is a supply problem, not an engineering one — but it is on the
critical path and it starts now.)*

## ⚠️ BLOCKER 2 — the contract's INPUT does not exist, so the engine is inert on real data

This is the systemic finding, and it is bigger than the walk-test.

`RoleBlockNormalizer` only stamps blocks that are **ancestors of `[data-field]` elements
whose names are role-anchored** (`seller_1_name` → `parseFieldName()` → `role_base=seller`).

**Across qa1, live and staging, ZERO templates carry a single `data-field`.** The four
"web" templates are **empty shells** — `field_mappings` is `NULL` and `tagged_html` has no
named fields at all. So:

- the normalizer is a **guaranteed no-op** on every template that exists today;
- **Section E is vacuous** — `php artisan docuperfect:normalize-templates --dry-run` reports
  **"Found 0 CDS template(s) to inspect"**. There is nothing to back-fill. (The two-halves
  AT-162 trap the spec warns about never arises, because the command writes nothing.)

**Consequence:** no document can carry the contract until it is imported through the CDS
builder **with its fields named on the role convention**. That is precisely P1-1's job — so
this does not re-inflate Phase 1, but it does mean **P1-1 is a hard prerequisite for ANY
contract behaviour**, and it is gated on Blocker 1.

**The one thing still unproven:** that a real import *does* name fields on the role
convention. Commit `4d5eb28c`'s claim is "the importer enforces the contract on **every**
import" — but since **no imported template in any database has named fields**, that claim
has never been demonstrated on real data. If the builder/AI names fields generically
(`field_1`), `parseFieldName()` returns `role_base=null` and **nothing is ever stamped, for
any document, forever.** → **The first real EATS import must be checked for role-anchored
field names.** That is the single highest-value assertion left in Phase 1.

---

## Exit criteria — honest reading

The spec's PASS requires *a freshly imported, never-before-seen multi-party document*. I
proved the **engine** end-to-end (stamp → clone → per-recipient surfaces → zero legacy
lines) and on a **second unseen document with unseen roles and zero code changes**. I could
**not** perform a fresh `.docx` → builder import, because (a) no EATS source exists, and
(b) the docx → role-named-fields step runs through the CDS builder UI, outside the backend
half.

**So: the engine is trustworthy and Phase 1 holds its estimate — conditional on the first
real import producing role-anchored field names.** If it does, the contract takes. If it
does not, the stamping never fires and Phase 1 re-inflates.

## Housekeeping

Walk-test templates on qa1 (68, 69, 70, 71) were **soft-deleted** after the run — no hard
deletes. Nothing else on qa1 was touched.
