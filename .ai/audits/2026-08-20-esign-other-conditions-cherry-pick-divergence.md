# eSign "Other Conditions" template-editor fix — QA1/Staging/live divergence by design, recorded

**Date:** 2026-08-20

## What happened

Johan approved the eSign "Other Conditions" marker/block fix ("esign approved -
cherry pick to live") after it was built and fully tested on QA1, then pushed to
Staging for his own testability check. Same situation as CX-107's lead-ticks fix
(`.ai/audits/2026-08-20-cx107-lead-ticks-cherry-pick-divergence.md`) and the
buyers:autoland-pipeline schedule line (`.ai/audits/2026-08-20-buyers-autoland-schedule-live-staging-divergence.md`):
`Staging` carries other lanes' unauthorised work (the buyers report rebuild, DR2
comms, MIC scope, outcomes fixes), so a full `Staging` → `main` merge was not an
option. The single feature commit was cherry-picked in isolation onto QA1 →
Staging → live, in that order, each a separate commit.

Live's checkout also has Johan's own uncommitted work-in-progress sitting in it
(a modified `template-67.blade.php` and a new `template-68.blade.php` under
`resources/views/docuperfect/web-templates/cds/`) — confirmed via `git status`
before touching anything and again after the cherry-pick and after all
verification, byte-for-byte unchanged throughout. The feature's 8 files never
touch either of those paths, so there was never a real conflict risk — checked,
not assumed.

## The three commits — same change, different hash, by design

| | commit | patch-id |
|---|---|---|
| QA1 (`qa1-esign-other-conditions`) | `59764fcc646f6c6f7f6f4f88f5dd4ac66f411141` | `a696155d84868d4218dd4a760656f24d8044eaf2` |
| Staging | `8369ca63d89a8ba11feeccbb328815632e7d2e5d` | `a696155d84868d4218dd4a760656f24d8044eaf2` |
| Live (`main`) | `69f70eca01ce3102eac78b9ccad2b6ba2fd1b5f2` | `a696155d84868d4218dd4a760656f24d8044eaf2` |

Confirmed identical patch-ids (`git show <sha> | git patch-id --stable`) — same
tree change (8 files: `TemplateController.php`, `InsertableBlockRenderer.php`,
new `MarkerBlockLevelNormalizer.php`, new `_insertable-blocks-mixin.blade.php`,
new `_insertable-blocks-panel.blade.php`, `cds-builder.blade.php`,
`edit-web.blade.php`, `routes/web.php`), different commit hash at each stop
because each is a cherry-pick onto a different parent history.

## Live verification performed (real requests, not unit tests)

- Reflection: `TemplateController`, `InsertableBlockRenderer`,
  `MarkerBlockLevelNormalizer` all resolve under `/corex` only, post
  `composer dump-autoload -o`.
- Equivalence (non-negotiable): real `cdsGenerate()` (importer) vs real
  `saveContent()` (editor) given identical input, on live's real database —
  `insertable_blocks` byte-identical, `editor_state['tagged_html']`
  byte-identical, both compiled files carry the wrapped marker. PASS.
- A fresh real CDS template generated end-to-end through `cdsGenerate()`
  rendered successfully via a real `view()->render()` call (7,459 bytes, no
  errors), with the marker resolved into its wrapped block form. PASS.
- No pre-existing, non-WIP `template_type='cds'` template exists on live to
  test against without touching Johan's in-progress `template-67`/`template-68`
  — those two were deliberately left untouched. The render proof above
  substitutes a freshly created real template through the same pipeline.
- Throwaway verification rows (Template ids 69, 70, 71; CdsDraft ids 8, 9) and
  their generated `.blade.php` files were deleted immediately after use.
  Confirmed via `git status --porcelain` on the compiled-templates directory
  both before and after cleanup, and via `Template::withTrashed()->find()` /
  `CdsDraft::withTrashed()->find()` for each id, that nothing but Johan's own
  `template-67`/`template-68` remained.

## The one thing this note exists to prevent

**When `Staging` is eventually merged into live/`main`** — whenever the other
authorised lanes' work on Staging is merged — `8369ca63d`'s content will already
be present on live under hash `69f70eca0`. Git recognises this by tree/patch
content, not by hash. A plain merge should handle this fine (no conflict on any
of the 8 files — both sides already have equivalent content) — but if anyone
manually resolves a conflict on that future merge and sees `8369ca63d` "missing"
from live's history by hash, **do not re-apply it and do not panic at what looks
like a conflict.** Check patch-id first:

```
git show 8369ca63d | git patch-id --stable
git log --all --oneline | while read h _; do git show "$h" | git patch-id --stable; done | grep a696155d84868d4218dd4a760656f24d8044eaf2
```

If it matches `69f70eca0` (or whatever live's hash is by then), the content is
already live. The divergence is expected and accounted for here — it is not a
lost commit and not a merge conflict to fight.
