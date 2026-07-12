# HANDOFF → m4: the builder run (four real HFC templates)

> **From:** m5 · **2026-07-12** · **Env:** qa1 — `https://qatesting1.corexos.co.za`
> **qa1 HEAD:** `5360f5f1` (carries all seven P1-1 fixes)
> **Your half:** drive the CDS builder in Chrome — upload, review/adjust the AI's field
> mapping, save. **My half (done):** the pipeline underneath it, and the assertion after it.

---

## TL;DR

The importer was broken in six separate ways this morning, and every one of them is fixed and
on qa1. **The pipeline is proven end-to-end on the real EATS: 39 blanks, contract stamped,
role = `seller`.** What's left is the one step that is genuinely human judgment — *which blank
is which field* — and that's your run.

**The AI is no longer a liability on sales documents.** My earlier warning ("expect to override
every Lessor") is **obsolete** — the prompt now reads sales correctly. Treat its suggestions as
suggestions, not as garbage.

---

## Where to go

**`https://qatesting1.corexos.co.za/docuperfect/import`** → upload → review (tag + map) → generate.

The four source documents are on branch **`hfc-template-sources`** under
`.ai/templates/hfc-source/` (your own copies of Johan's uploads):

| file | what it is | blanks the importer will find |
|---|---|---|
| `exclusive-authority-to-sell-v10.docx` | EATS — the launch document | **39** |
| `fica-natural-person-v8.docx` | FICA | **12** |
| `seller-mandatory-disclosure-v7.docx` | Disclosure | **11** |
| `offer-to-purchase-v13-enviro.docx` | OTP — **WET-INK ONLY**, never e-sign (doctrine) | **129** |

If the blank count on screen does **not** match that column, stop and tell me — it means
something regressed in the scan, and every field mapping after the mismatch would land on the
wrong blank.

---

## What the AI will now suggest (measured on qa1, real run)

**EATS** — `seller=23, property=15, agent=1`, **zero lessor/lessee**. Labels come back
party-qualified and matchable: *"Seller Name"*, *"Seller 1 Physical Address"*, *"Erf Number"*,
*"Complex Name"*.

**Disclosure** — now identifies seller / buyer / agent / property (it mapped **nothing** before).
Most of its blanks are genuinely signing locations and dates → they *should* stay **manual**.
That is correct, not a miss.

**OTP (129 blanks)** — the big one, and the one that will most want your eyes. Expect to correct
more here than on the EATS.

**Still worth checking by hand:** anything the AI marks `confidence: low`, and any blank where a
party label sits *after* the blank (SA documents do this constantly — `[___] (the Seller)`).

---

## THE ASSERTION — what I run after you save

Assert on the **generated blade**, not on `editor_state.tagged_html`. That field holds the
*builder's* `data-tag-id` spans by design; the contract lives on the blade. (I burned two cycles
grepping the right string in the wrong artifact — don't repeat it.)

```bash
B=resources/views/docuperfect/web-templates/imported/<slug>.blade.php

grep -c 'data-field='      "$B"    # EATS must be 39 — every blank survived
grep -c 'data-role-block=' "$B"    # must be > 0 — the contract is stamped
grep -o 'data-role-block="[^"]*"' "$B" | sort -u   # must say seller — NOT lessor
```

⚠️ **The generator suffixes `-<id>` on a slug collision.** If a blade of the same name already
exists you'll get `exclusive-authority-to-sell-v10-93.blade.php` and the un-suffixed file will be
**stale**. Always grep the newest file (`ls -t`), or you will assert against a two-runs-old
artifact and get a false negative. (Also mine. Also cost me a cycle.)

**Green on the EATS = Phase 1 unlocked for m6.** Ping me and I'll run it and post the result.

---

## What's underneath you now (so you know what "should work" means)

Seven commits on `esign-p1-1-normalizer-role-source`, all on QA1, **33 tests green**:

1. **The engine and the importer finally speak.** The engine read the role out of the *field
   name*; the importer writes it in a *sibling attribute*. One bridge, used by both.
2. **The renderer had the same defect, worse.** The seller block cloned but the fields inside
   kept the same `data-field` — **seller 2's input would have landed on seller 1's field.**
3. **The importer was looking for the wrong character.** Blanks are ruled with **ellipses**, not
   underscores. EATS saw 3 of 39; OTP saw 2 of 129.
4. **One scan, not two.** Detection and span-injection disagreed on every document, and the
   importer maps AI suggestions to blanks **by index** — so every field after a divergence went
   onto the wrong blank.
5. **A generated template could silently DELETE its own blanks.** Unknown/typeless tags were
   erased, not skipped. All 39 EATS blanks vanished and the import reported success. Now a tag we
   can't read is absorbed as a manual field the agent can still fill, and logged.
6. **An imported document never carried the contract.** Only the builder path normalised; the
   importer path never did — which is why the backfill always found nothing to do.
7. **The AI prompt was rental-only.** Lessor/Lessee ×10, Seller/Purchaser ×0. Now decides the
   document type first, and knows that SA says *Purchaser* while the system says `buyer`.

## Housekeeping

qa1 is clean — every template, draft and blade I created while proving this is **soft-deleted /
removed** (0 leftovers). The DB is untouched apart from that. Your run starts from a clean board.
