# Lean standing files — migration notes (DRAFT for Johan + Andre review, 2026-10-24)
Branch `lean-standing-files-2026-10-24` (from origin/QA1 `cebc9f960`). Not merged, nothing deployed. Docs/skills only — no app code touched.

## 1. Tokens (chars/4) a fresh lane reads at start
| File | Before | After |
|---|---|---|
| ~/.claude/CLAUDE.md (global, untouched) | 3,102 | 3,102 |
| CLAUDE.md (root) | 10,939 | 4,027 |
| .ai/CLAUDE.md | 3,043 | 99 (pointer) |
| .ai/STANDARDS.md | 29,600 | 2,461 |
| .ai/BUILD_STANDARD.md | 7,620 | 2,383 |
| .ai/SYSTEM.md (untouched) | 2,346 | 2,346 |
| .ai/CODEBASE_MAP.md (untouched, now on demand) | 6,477 | 6,477 |
| .ai/CHAT_STARTER.md | 230,844 | ~4,850 |
| MEMORY.md index (untouched) | 3,079 | 3,079 |
| **Total** | **~297,050** | **~28,800** |
Lean core (CLAUDE + STANDARDS + BUILD_STANDARD) = ~8,900 tokens (budget 15k). Mandatory start (core + global + MEMORY) = ~15,000; CODEBASE_MAP / SYSTEM / CHAT_STARTER are now on demand. 13 skill descriptions are always loaded (~950 tokens); bodies (0.4k-1.5k each) load only when triggered. The 69 memory notes (~48.6k tokens if all read) are untouched; their index lines for `qa1-deploy-and-deploy-dir`, `lane-worktree-test-bootstrap`, `promotion-runbook-2026-10-20`, `qa1-proof-traps-mailpit-sync-queue` are now covered by skills and could be pruned (your call).

## 2. Where each old thing went
- NON-NEGOTIABLE block (was copied into CLAUDE.md, .ai/CLAUDE.md, STANDARDS, BUILD_STANDARD, CHAT_STARTER): now ONLY in root CLAUDE.md section 1; usage-discipline block likewise (section 2).
- STANDARDS "Standard -1 ... -1z" (about 85 KB, mostly incident history): procedures to skills; mapping table at STANDARDS section 8 (labels kept because code comments cite them). Rule 17 / Rule 18 kept in STANDARDS. BUILD_STANDARD keeps its section numbers (code cites section 4, 5, 5a, 6).
- Promotion flow / deploy sequence / scheduler / env-parity: BUILD_STANDARD section 8 tail -> skill `promotion-runbook`.
- E-sign moat gate, portal refresh-cost contract (were in root CLAUDE.md) -> skills `esign-and-documents`, `portal-sync-refresh-cost`.
- CHAT_STARTER (930 KB: a 490 KB stack of "Last updated" lines, 160 KB state, 250 KB decisions) -> verbatim in `.ai/archive/CHAT_STARTER-pre-lean-1..5` (byte-identical, checked); new file = 15 latest one-line landings, titles of in-flight/specced/parked, newest 8 decisions clipped, OPEN fixes by title. **The in-flight list is 76 old titles, many stale (Aug-Sep, QA2) - it needs a human prune.**
- Originals of CLAUDE.md x2, STANDARDS, BUILD_STANDARD: `.ai/archive/pre-lean-*.md`.

## 3. Rules I changed, merged or am UNSURE about (decide, don't assume)
1. **Dropped: "merge origin/Staging into your branch at session start" (old #11).** On QA1-based branches that drags Staging-only work (incl. the AT-475 hold-back reverts) in; the Staging-to-QA1 sync is done by a sync lane. Lean text says fetch + pull --rebase your own branch only. Confirm.
2. Old `.ai/CLAUDE.md`, BUILD_STANDARD, STANDARDS, CHAT_STARTER copies said "5. QA1 ONLY - never promote to Staging"; root says two QA lanes. I used root (amended 2026-09-16).
3. Operating rules had two items numbered 8; the CRUD floor is now 10 (multi-agency stays 9). Multi-tenancy (old .ai/CLAUDE.md #7, clashing with root #7 API rule) is now non-negotiable 14.
4. Standard -1l (only cc1 writes origin/QA1) vs a 7 Oct memory note (lane may push HEAD:QA1 if its brief pre-authorises). Kept -1l; flagged inside `landing-checks`.
5. `dev-check.ps1` was a required step in "How to build" yet has never run on this box: removed as a gate (kept the warning).
6. Standard -1a's lane to `hfc_dash_test_N` table dropped (superseded by -1z automatic per-worktree names). -1h "don't fix contention" ruling dropped (superseded by -1x/-1y).
7. Non-negotiable 12 (demo always migrated/seeded) kept but looks heavy/stale against today's QA flow - Johan's call.
8. This branch name has no lane prefix (-1m) because Johan named it.
9. Global `~/.claude/CLAUDE.md` "Minion usage rules" duplicate section 2 and its authority section is outside this repo - not touched; suggest trimming the duplicate.
10. Risk: rules now living only in skills (e.g. the signing-state P0 invariant, @json trap) load only if a description matches. Mitigation: CLAUDE.md section 8 routing table + one-line pointers in STANDARDS. Name any rule you want promoted back into the always-read core.
Dropped as history only: incident narratives, the retracted -1j/-1k texts, measured timings, QA-hold stories, "Andre is implementing SoftDeletes" status, test counts (894).

## 4. Design checklist
STANDARDS section 1 (layout placeholder for Andre's weekend design spec, agency settings + wizard, Role Manager permission + view/act, OWN/BRANCH/AGENCY, CRUD + list floor, menu link, Jira). CLAUDE.md section 6 makes it a condition for "spec ready to build".

## 5. Spec split
`leases.md` (332 KB) done as the example: `leases.md` is now a 7.5 KB index (38 rows, section number, size, file) + `leases/NN-*.md`, verbatim and verified by script (preface + sections rejoin to the original). Existing `leases.md §N` citations still resolve via the index. Note section 15 is still 164 KB - second-level split by its `###` headings is the next step.
Plan for the rest (same script, `.ai/archive/split_spec.py`: one file per `##`, index keeps preface; then second-level split of any section over ~40 KB):
| Spec | Size | `##` / `###` | Biggest | Plan |
|---|---|---|---|---|
| rental-applications | 1,063 KB | 138 / 423 | 36 KB | Group the 138 sections into ~10 area folders (capture, review split-screen, authorisation, applicant link, documents/markup, contacts, PDF splitter, qualifying, fixes) - index by area then section |
| rental-inspections | 822 KB | 88 / 280 | s45 = 117 KB | One file per section; split s45 and s24 by `###`; index grouped by phase (templates, scan, photos/pairing, signing, comparison, audits) |
| rental-work-orders | 576 KB | 27 / 110 | s17 = 231 KB, s14 = 155 KB | Only 27 sections - split s17 (maintenance flow, 17.x) and s14 (job cards) by `###` first |
| rentals-faults-work-orders | 321 KB | 33 / 132 | s22 = 76 KB | One file per section; split s22 ("nothing is automatic") by `###`; note overlap with rental-work-orders |
Rule for all: amendments become new files + one index row; never re-grow a single file past ~40 KB.
