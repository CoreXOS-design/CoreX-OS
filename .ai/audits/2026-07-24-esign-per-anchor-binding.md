# E-sign per-anchor binding — root cause & fix (2026-07-24)

**Env:** QA1 / `corex_qa1`. **Doc:** 459 (`signature_templates.id` 90) — "EXCLUSIVE AUTHORITY
TO SELL – monday morning test", 2-seller (Anine Van der Westhuizen #1, Andre Roets #2)
+ agent Johan Reichel. Forensic test: a unique marker (a, b, c … z) entered at every
entry point in signing order, so every value is exactly traceable.

## Symptom
Generated PDF **and** the agent-review screen scattered the inputs: seller signatures
and every party's per-page initials collapsed to one mark; many entries dropped. The
agent's per-clause signatures varied correctly, the sellers' did not.

## Root cause
Two inconsistent ink-binding paths + one false assumption.

- **Seller signatures** (external web-signing) → `SigningController@completeWeb` →
  `CanonicalInkComposer::bakeInk()` used `representative($signatures)` — the FIRST
  captured image — painted onto **every** marker the signer owns. Comment stated the
  assumption: *"Apply-to-all yields identical captures, so a representative image is
  faithful."* False when captures differ per anchor.
- **Initials** (all parties) → `restoreStoredInitials()` (a4-page-styles.blade.php) built
  `byRecipient[party] = first image` and placed it in every one of that party's boxes.
- **Agent signatures** escaped: `SignatureController::embedSignaturesIntoHtml()` binds
  each capture to its own anchor (per-anchor keying) → correct.

The per-anchor index **exists** in both the captures (`{party}-sig-{n}` / `{party}-init-{n}`)
and the frontend numbering (document order), but the two collapsing paths keyed only by
signer ownership and discarded the index.

### Trace map (canonical = the review source), BEFORE fix
| Party | 4 signature anchors render | captured-but-dropped |
|---|---|---|
| Anine `seller` | `seller-sig-0` ×4 (BLED) | seller-sig-1/2/3 |
| Andre `seller_2` | `seller_2-sig-0` ×4 (BLED) | seller_2-sig-1/2/3 |
| Johan `agent` | agent-sig-0/1/2/3 (correct) | — |

Initials: each party's `init-0` bled across all 4 pages; init-1/2/3 dropped.
Ceremony: correct per-identity (seller_1='q'/09:52, seller_2='z'/09:53, agent='h'/09:50)
— it binds one value per identity, so no per-anchor multiplicity to collapse.
Faults were **BLED + DROPPED within the correct party**; no WRONG-PARTY, no MISPLACED.

## Fix (QA1 `89b6f5ab`) — one consistent per-anchor path, shared by screen + PDF
- `CanonicalInkComposer::paintOwnedMarkersByIndex()`: the k-th signature/initial marker a
  signer owns (document order) takes capture index k. Falls back to the representative
  when a marker has no distinct capture, so **adopt-once/apply-to-all** (one capture, or N
  identical) still fills every box with the same mark.
- `restoreStoredInitials()`: keeps each recipient's initials by index and gives the k-th
  page box `init-k` (same fallback). This is the ONE partial the review screen AND the PDF
  pagination both read → correct on both surfaces.

## Proof (QA1 doc 459, re-baked with the fixed code, in-memory)
- **Signatures** (exact hash trace on the re-baked canonical): Anine → seller-sig-0/1/2/3,
  Andre → seller_2-sig-0/1/2/3, Johan → agent-sig-0/1/2/3 — **4/4 distinct each, zero
  dropped, zero bled, zero wrong-party**.
- **Initials** (generated PDF, fixed `restoreStoredInitials` in Chromium): page 1 = a / j /
  squiggle (each party's init-0), **page 2 = c / k / t** (init-1) — vary per page.
- **Ceremony** unchanged and identical in canonical and PDF: 'q'/09:52, 'z'/09:53, 'h'/09:50.
- **Screen == PDF**: PDF generated from the same `forDisplay` canonical the review renders.
- **Regression**: Anine signing once (single capture) → all 4 seller anchors identical →
  apply-to-all preserved.

Note: the proof re-baked in memory; the fix applies to **new** signings. Existing docs (459)
keep their old stored canonical until re-baked/re-signed — backfill available on request.

**Scope:** QA1 only. No Staging/live without Johan's go. Files: `CanonicalInkComposer.php`,
`resources/views/docuperfect/signatures/partials/a4-page-styles.blade.php`.

## Second collapse (QA1 `1a7529ff`) — pagination re-anchor key
Verification with the ACTUAL captured glyphs (Johan TYPED a unique letter per entry
point, so captures are per-anchor by construction) exposed a second collapse that
`bakeInk` alone did not cover. Captured signatures: Anine l/m/n/r, Andre u/v/w/aa,
Johan b/d/e/i (all distinct). `bakeInk` bound them per-anchor in the canonical — but
the rendered page 3 still showed **n/n/n** for Anine (r on page 5).

Root cause: `paginateDocument`'s snapshot/re-apply (`_markerKey` = `party|type|data-marker-index`).
The composed CLAUSE signature markers carry **no `data-marker-index`** (only the final
attestation markers do), so all of a party's clause anchors shared the key
`seller|signature|`; the re-pagination pass overwrote them to the LAST one (sig-2 = n),
while the indexed final marker kept its own (sig-3 = r). A render-time re-collapse
after the canonical was already correct.

Fix: assign every signature/initial marker a stable per-anchor sequence in document
order per (party|type); `_markerKey` falls back to it when `data-marker-index` is
absent → index-less clause anchors keep unique keys. Idempotent, persists across passes.

Proof (doc 459 re-rendered): page 3 clause signatures now **l/m/n** (Anine), **u/v/w**
(Andre), **b/d/e** (Johan); page 5 finals r/aa/i. Every one of the 12 anchors renders
its own capture, document order, on BOTH the review screen and the PDF (same JS).

**Capture model:** signatures are PER-ANCHOR (each "sign here" captures its own image),
same as initials — NOT adopt-once. So identical clause signatures were a real bug, now
fixed. (Adopt-once, when a party reuses one signature, still renders the same mark at
every anchor via the representative fallback — regression-safe.)
