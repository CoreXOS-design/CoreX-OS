# PHASE 1 VERDICT — the one assertion

> **Ran:** 2026-07-12, m5 · **qa1** · **Subject:** the REAL `exclusive-authority-to-sell-v10.docx`
> (Johan's blank HFC EATS, 52,563 bytes — verified genuine: HFC letterhead, FFC 202615038880000,
> "EXCLUSIVE AUTHORITY TO SELL").

---

# 🔴 RED

**The importer does NOT produce role-anchored field names. The `data-role-block` contract is
never stamped — not for the EATS, not for any document, ever.**

The engine is fine (proved yesterday: given `seller_1_name`, it stamps, clones, and renders with
zero legacy-clustering lines). **The importer and the engine simply do not speak the same
language**, and nothing in between translates.

**The role is known at every single step. It is just never put where the engine looks.**

---

## The miss, exactly

The engine reads the role **out of the field name**:

```
RoleBlockNormalizer::normalize()  →  xpath '//*[@data-field]'  →  parseFieldName(name)
    parseFieldName("seller_1_name")  →  role_base='seller', idx=1, sub='name'   ✅ stamps
    parseFieldName("contact.full_name") →  role_base=NULL                        ❌ skipped
```

The importer writes the role **into a sibling attribute**:

```php
// DocumentTemplateGenerator.php:318-326  — what actually lands in tagged_html
$dataField = $sourceType . '.' . $sourceColumn;      // "contact.full_name"
...
'<span class="field" data-field="contact.full_name" data-contact-type="seller" ...>'
                                 ^^^^^^^^^^^^^^^^^^  ^^^^^^^^^^^^^^^^^^^^^^^^^^
                                 engine reads THIS   role actually lives HERE
```

And the AI that names the fields is *specified* to do it that way — this is verbatim from its own
prompt contract (`ImporterAiService::fieldPrompt()`, ll. 233-246):

```
"1": {"label": "Lessor Address", "key": "contact.address_residential",
      "pillar": "contact", "assigned_to": "lessor", "confidence": "high"}
```

`key` is pillar-dotted; the role goes in `assigned_to`. **So even a perfect AI run produces zero
role-anchored names.** This is not an AI-quality problem or a config problem — it is a contract
mismatch baked into the design of both halves.

## Proof — three independent legs, all red

| # | Leg | Result |
|---|---|---|
| 1 | Real EATS through the production parse (`DocxParserService::parse`) | **0 of the produced keys are role-anchored**; `parseFieldName()` returns `role_base=NULL` |
| 2 | `RoleBlockNormalizer::normalize()` on generator-shaped `tagged_html` (`contact.full_name` + `data-contact-type="seller"`) | **0 role blocks stamped** |
| 3 | Render that template with two sellers | **the knife-edge line FIRED** — `rendering unnormalised template via legacy clustering` (1 line) |

Control, same run: `parseFieldName("seller_1_name")` → `role_base='seller', idx=1, sub='name'` ✅ —
the engine works the instant it is fed the convention it expects.

**Note on the AI:** both `ANTHROPIC_API_KEY` and `OPENAI_API_KEY` are set on qa1, so the naming step
was live. It is irrelevant to the verdict either way — leg 2 and leg 3 are independent of the AI, and
the AI's own output format (above) can never satisfy the engine.

---

## Phase 1's first fix — and it is small

**Do not change the importer's naming.** `contact.full_name` + `data-contact-type` is the right
shape for the CDS/pillar system, and it is used everywhere else. **Teach the normalizer to read the
role from the attribute the generator already writes.**

In `RoleBlockNormalizer::normalize()`, when `parseFieldName($name)['role_base']` is `null`, fall back
to the element's **`data-contact-type`** (which already carries `seller` / `lessor` / `agent` — all
valid `ROLE_BASES`), and derive the **segment** from the source column. The segment maps the
normalizer already has line up exactly with the column names the generator emits:

| Normalizer constant (already exists) | Generator's `source_column` |
|---|---|
| `IDENTITY_SUBS` = `full_name`, `name`, `id_number`, `surname`… | `contact.full_name`, `contact.id_number` ✅ |
| `ADDRESS_SUBS` = `address`, `physical_address`, `address_1`… | `contact.address_residential` ✅ |
| `CONTACT_SUBS` = `phone`, `cell`, `mobile`, `email` | `contact.cell_phone`, `contact.email` ✅ |

So: **one extra role source + reuse the existing segment maps.** No importer change, no AI-prompt
change, no re-import of anything. Multi-party indexing is unaffected — the renderer clones per
recipient off the role block, so the instance index never needed to be in the field name.

**Estimate: S.** It is the first thing Phase 1 should do, and it unblocks everything behind it.

---

## What this does to the Phase 1 estimate

**It holds — but only because the fix is small and lands before the imports.**

- The **engine** is proven sound (yesterday's walk-test: stamps, clones per party, zero legacy lines,
  a second unseen document with unseen roles and zero code changes).
- The **documents** have now landed (all four).
- The **only** thing standing between them is this one-attribute translation.

Sequence it as: **fix the normalizer first, then import.** If the three remaining templates
(`fica-natural-person-v8`, `seller-mandatory-disclosure-v7`, `offer-to-purchase-v13-enviro`) are
imported *before* the fix, they will all import **without the contract**, every render will take the
legacy clustering path, and they will each need a re-normalisation pass afterwards.

⚠️ **The stretch goal should therefore be re-ordered, not cancelled.** Importing them tonight is
still right — but the normalizer fix is a prerequisite for those imports *carrying the contract*.
(OTP remains **wet-ink only** per doctrine — it imports for the pack flow, never for e-sign.)

## Secondary finding (P1-1 import quality, not the verdict)

The production parse detected only **3 fields** in the real EATS. The document has far more blanks
than that. This does not affect the verdict (all three legs are red regardless), but it means the
EATS import will need field-detection review before it is usable — worth a look during P1-1, and
worth knowing before anyone assumes an import is "done" because it completed.

## Housekeeping

Verdict template (qa1 id 72) **soft-deleted** after the run. Yesterday's walk-test templates
(68-71) remain soft-deleted. Nothing else on qa1 was touched. No code was changed — this was a
read-only verdict run, as briefed.
