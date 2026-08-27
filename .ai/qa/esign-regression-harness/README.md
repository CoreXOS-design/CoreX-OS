# E-sign regression harness

Pointer doc. Full details, the run command, and file layout live in
`scripts/esign/regression/README.md` (the harness's own README, next to its
code — kept there so it never drifts from the code it describes).

**Why this exists:** Johan, 2026-08-27, after a fix to the late-estate flow
broke a company-with-three-directors flow he had already signed off:
*"We are never going to progress with esign 1 fix break another flow... it
means I have test end to end every time something gets changed."*

**The architecture it's built to** (Johan, verbatim): *"its one document
that flows like a printed page... have the master document, and each
screen adds to that document in its set ways and rules."* The harness
snapshots the document body at every step of the wizard and diffs each step
against the one before it — every step may ADD its own material, nothing
already there may change.

**Run it:** `node scripts/esign/regression/run.js` from the QA1 repo root.

**When to run it:** before calling any e-sign change done. This is what
stops Johan being the regression suite.
