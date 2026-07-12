# P1-1 — the import pipeline: five fixes, the assertion GREEN, and what m4 needs

> **2026-07-12, m5** · branch `esign-p1-1-normalizer-role-source` · all five commits on **QA1**
> Follows the P1-0 RED verdict. **Phase 1's blocker is cleared.**

---

## THE ASSERTION — GREEN ✅

Run on the **real** `exclusive-authority-to-sell-v10.docx`, on qa1, through the production services:

| document | blanks | `data-role-block` | segments |
|---|---|---|---|
| **EATS v10** | **39** | **9** ✅ | identity ×1, address ×4, contact ×4 |
| **FICA v8** | 12 | 2 ✅ | — |
| Disclosure v7 | 11 | 0 ¹ | — |
| OTP v13 | 129 | 0 ¹ | — |

¹ *not a failure — zero of their blanks were mapped to a named field, see the AI-prompt finding below.*

**The contract stamps on a real, freshly imported HFC document, with every blank intact.** That
is what P1-0 said was impossible this morning, and it is what Phase 1 was gated on.

---

## The five fixes

| # | Commit | What was actually wrong |
|---|---|---|
| 1 | `8314a2cf` | **The engine and the importer never spoke.** The engine read the role out of the *field name* (`seller_1_name`); the importer writes it in a *sibling attribute* (`data-field="contact.first_name" data-contact-type="Seller"`). One canonical bridge (`resolveFieldElement`), used by **both** consumers. |
| 2 | `8314a2cf` | **The renderer had the same defect, with worse consequences.** `mutateCloneForInstance()` also skipped what it couldn't read, so the seller block cloned but the fields inside kept the **same `data-field` across clones** — colliding in the DOM: **seller 2's input would land on seller 1's field**, with no prefill. Data corruption in a signed mandate. |
| 3 | `bd67bfc9` | **The importer was looking for the wrong character.** Blank detection was `/_{2,}/`. HFC rules its blanks with **ellipses** — EATS: 3 underscores vs **35** ellipses; OTP: 2 vs **117**. The two launch-critical sales documents imported as near-empty shells *while reporting success*. |
| 4 | `9e03f7c3` | **One scan, not two.** Detection read `<p>` text; injection scanned the whole document and merged. They could never agree (EATS 44/39, OTP 143/129). The importer maps AI suggestions to blanks **by index**, so any divergence writes every later field onto the **wrong blank**. |
| 5 | `f812a5fa` | **A generated template could silently DELETE its own blanks.** ← *the root cause of the bad import* |
| 6 | `f2633a77` | **An imported document never carried the contract.** Only the CDS *builder* path normalised at publish; the *importer* path never did — which is why the backfill found nothing to do: there was never anything to find. It now stamps at birth. |

### Fix 5 in full — the one that mattered most

`processTagSpans()` dispatches on `$tag['type']` and **`return ''`**'d on both the orphan path and
the unknown-type path. An empty string does not *skip* a tag — **it erases the blank from the
document.** The import then completes, reports success, and produces a legal template whose
fill-in lines have quietly vanished. Hit for real: tags with no `type` fell through, and **all 39
blanks were erased from the EATS** — it came out reading *"I / We  the undersigned"* with nothing
to sign into, and nothing anywhere said so.

Now (BUILD_STANDARD §3, prevent-or-absorb): a typeless tag defaults to `input`; an unknown type or
an orphan span is absorbed as a **manual field the agent can still fill**, and logged at ERROR.
**Content is never destroyed.** An agent can fill a manual field; nobody can fill a deleted blank.

**Tests: 25 passing** across five files, including the whole-document guarantee — *all 39 blanks
survive, none disappear* — which is the test that would have caught this.

---

## ⚠️ WHAT m4 NEEDS TO KNOW BEFORE DRIVING THE BUILDER

**The AI's field suggestions are rental-only, and will be wrong on every sales document.**

`ImporterAiService::fieldPrompt()` mentions **Lessor/Lessee 10 times and Seller/Purchaser ZERO
times.** Its worked examples and its rules are all lease patterns. Consequences, measured:

- On the **EATS** — a *sales* mandate — the AI mapped the seller's name/address/contact to
  **Rental → Lessor** named fields. The contract stamped correctly, but with
  `data-role-block="lessor"` **on a sales document.** The machinery is right; the label is wrong.
- On the **Disclosure and the OTP**, it mapped **nothing at all** (0 of 11, 0 of 129) — it had no
  sales vocabulary to match against, so every blank fell through to manual.

**This is exactly why the mapping step is human judgment, and why your browser run is the real
import.** Expect to override the AI on the sales documents rather than accept it. In particular:
**every `Lessor` it proposes on the EATS should be `Seller`, and every `Lessee` a `Purchaser`.**

**Recommended follow-up (S, mine if you want it):** teach `fieldPrompt()` the sales vocabulary —
Seller / Purchaser, and the EATS/OTP/FICA/Disclosure patterns alongside the lease ones. Until then
the AI is a liability on sales docs, not a help. **It does not block your run** — the human mapping
is the authority — but it will make it slower and it will mis-suggest confidently.

### What to assert after your run

```bash
# on the freshly generated blade (NOT editor_state.tagged_html — that carries the
# builder's data-tag-id spans, by design)
grep -c 'data-role-block=' resources/views/docuperfect/web-templates/imported/<slug>.blade.php
grep -c 'data-field='      resources/views/docuperfect/web-templates/imported/<slug>.blade.php   # must be 39 for the EATS
```
Green = **Phase 1 unlocked for m6.**

---

## Housekeeping

All import artifacts **soft-deleted** (templates 73–84, their drafts) and the 8 generated blade
files removed. qa1 is clean: **0** walk-test / verdict / import templates live. Nothing else on
qa1 was touched.

## My own lost cycles — for the record

1. I lost the unified-scan edits once by switching branches before verifying the commit had
   landed, and had to re-apply them from scratch. Commit *then* switch.
2. I then wasted two more cycles on `git cherry-pick -q` — not a valid flag — so two "landed on
   QA1" reports were wrong until I checked the actual HEAD. **Verify the target moved; don't trust
   the command's exit.**
3. I asserted the contract against `editor_state.tagged_html` twice before realising the importer
   path stores the *builder's* HTML there by design, and the contract lives on the **blade**. I was
   grepping the right string in the wrong artifact — and then, once, in a **stale** blade file
   (the generator suffixes `-<id>` on slug collision, so my "fresh" file was two runs old).
   Three separate false negatives from never questioning what I was measuring.
