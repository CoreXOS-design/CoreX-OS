# E-Sign Document Compiler — one-page digest (for Johan)

**Full spec:** `.ai/specs/esign-document-compiler-spec.md` · **Ticket:** AT-177 · **Status:** draft for your approval.

## The one idea
Today e-sign **stores a compiled structure but signs from frozen `merged_html` snapshots**, and "what fields does this template have?" has **six** competing answers. That inversion is the disease — it's why 5 live bugs shipped while 49 unit tests were green. **The compiler makes the compiled structure the *only* thing that can be signed.** When the signed artifact *is* the validated artifact, the entire staleness class becomes *unrepresentable* — there's nothing left for a freshness guard to guard.

## How it works (compile once, sign from canonical)
`ingest (DOCX/PDF) → segment into typed blocks → bind every field to a typed CoreX data-dictionary entry (ZAR, SA-ID checksum, erf/title, PPRA, dates — validation lives on the field) → declare party & signature topology as data → LINTER GATE → publish immutable hashed version → sign/render ONLY from that`.

**The linter gate is the whole point.** A template cannot publish unless: every field is bound, zero orphan mappings, every declared role has its anchors, every party/conditional combination resolves, web+PDF render **parity** passes, and the **legal e-sign block holds** (an OTP can't publish with e-sign on — Alienation of Land Act / ECTA, now a compile-time invariant not a runtime name-guess).

## Two doors (product)
1. **CoreX Standard SA pack** — included, we maintain it.
2. **"Your agency docs, CoreX compiles them"** — white-glove onboarding.
Both compile to the same structure. **The Compile Studio is internal** — agencies consume published templates, never the compiler. Three delivery modes (web e-sign / PDF wet-ink / download) all render from the one structure.

## Why it's safe to build
- **Prove before retire.** Compile templates **116/117/119** first as reference proofs (117/119 have zero fields → isolate the signature/letterhead compilers; 116 adds the field layer; the pack proves sequencing). Parity-diff against live before any cutover.
- **Golden harness, CI-gated.** Auto-generate a fixture signing for *every* party combination from the CDS; a version can't publish red. Built on the real `SigningView` harness — not the RecipientLoop tier that went 49-green-while-broken.
- **Compensator retirement map** — each patch (`MergedHtmlFreshnessGuard`, the marker fuzzy-matcher, orphan pruning, signature re-stamping, letterhead re-swap, role re-detection, the 6-source field divergence) is mapped to the compiler feature that obsoletes it, and retired **only after** its replacement is proven on the reference templates.

## Shaped for three lanes (DR2 pattern)
WS0 schema + data dictionary · WS1 linter engine · WS2 render-only runtime — **these three run in parallel** — then WS3 golden harness · WS4 ingest + Compile Studio · WS5 migrate 116/117/119 · WS6 retire compensators · WS7 standard pack + onboarding. Each WS has explicit gate criteria.

## 4 decisions I need from you before WS0
1. Data dictionary storage: DB (versioned, agency-overridable) — recommend yes.
2. CDS storage: JSON `structure` + hash (immutable) + thin binding index — recommend yes.
3. PDF engine that can meet web/PDF parity (constrains WS2).
4. Confirm WS0/WS1/WS2 as the three opening parallel lanes.

*Two small hygiene finds while researching: the pipeline gate lists a file that doesn't exist (`SurfaceNormalizer.php`) and omits the most compiler-adjacent one (`RoleBlockNormalizer.php`) — fix as WS0 hygiene.*
