# CDS v2 — the Compiled Document Structure (AT-177 / WS0)

This directory is the **canonical, typed contract** for the E-Sign Document Compiler
(spec: `.ai/specs/esign-document-compiler-spec.md` §2). It is the *sole runtime truth* —
the linter (WS1) and the render-only runtime (WS2) both code against these value objects.

## What this is

Plain, immutable PHP value objects (`final class`, `declare(strict_types=1)`, promoted
`public readonly` properties). **No DTO library** — matches the codebase convention
(domain events use the same shape). Every VO exposes `fromArray()` / `toArray()` for
loss-free hydration to/from the `compiled_templates.structure` JSON column.

## The tree (root → leaves)

```
Cds                      ← root; stored in compiled_templates.structure
 ├ family, dataDictionaryVersion, legalClass (enum), deliveryModes[] (enum)
 ├ parties:  Party[]      ← DECLARED signing topology (roles are data, not detected)
 ├ blocks:   Block[]      ← ordered, typed, addressed by stable block_id (never DOM position)
 │   ├ type: BlockType (enum)
 │   ├ visibility / editability: PartyExpr   ← DECLARED "who sees / who may edit"
 │   ├ condition: Condition                  ← DECLARED instance-presence predicate (L4)
 │   ├ fields:  Field[]   ← every fill-point; binding is MANDATORY (L1)
 │   ├ anchors: Anchor[]  ← signing surfaces bound to a declared party (L3)
 │   └ slot:    SlotContract? ← typed insertable_slot contract (replaces tilde markers)
 ├ assets:   Asset[]      ← pinned by hash, re-resolvable by ref (letterhead etc.)
 └ renderParity: RenderParity? ← web/pdf hashes, written after L6 passes
```

## The two evaluable predicates (what the linter enumerates)

- **`PartyExpr`** — per-signer projection. `appliesTo("seller_2")` answers *does this
  signer see/edit this block*. Keys match by exact instance (`seller_2`) **or** role base
  (`seller`). Modes: `all` / `none` / `only` / `except`.
- **`Condition`** — document-instance predicate. `evaluate($context)` answers *does this
  block exist at all in this party-combination/data scenario*. Kinds: `always`,
  `party_present`, `party_absent`, `party_count_gte`, `field_truthy`, `field_equals`.

Both are the axes L4 walks: enumerate party cardinality × conditionals, evaluate each
block, prove no combination strands a block or an unreachable required field.

## The immutability anchor

`Cds::contentHash()` is a deterministic SHA-256 over the **structural** content (excludes
`render_parity` proof metadata and the assigned row `version`; keys recursively sorted so
the hash is input-key-order independent). A signing request pins
`(template_id, version, content_hash)` — this is why the freshness class is unrepresentable
(spec §5). **Never** mutate a published structure; produce a new version.

## For WS1 (linter) and WS2 (renderer)

- Hydrate with `Cds::fromArray($structureJson)`; never re-parse HTML.
- Do not add setters or mutation — these objects are frozen by design.
- Extending the model (new BlockType, new Condition kind) is a deliberate contract change:
  update the enum/VO here first, then the linter/renderer switch statements.
