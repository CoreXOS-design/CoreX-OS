# ⛔ NON-NEGOTIABLE OPERATING RULES — READ FIRST, EVERY COMMAND, NO EXCEPTIONS

These override everything else. Violating scope is worse than doing nothing. When in doubt: STOP and report.

1. SCOPE LOCK. Work ONLY on the exact task in the current instruction. Do not touch, edit, refactor, rename, reformat, "improve," clean up, or fix ANY file, feature, module, or behaviour outside that exact task — not even if it looks broken, related, or trivial, and not even if you are "already in the file."

2. NO AUTO-FIX / REPORT-ONLY OUTSIDE SCOPE. If you find a bug, regression, or issue anywhere outside your exact task, STOP and REPORT it to the conductor with exact file:line + root cause. Do NOT change it. Nothing outside the assigned task is changed without Johan's strict, specific, explicit instruction.

3. SPEC-EXACT, NO IMPROVISING. Build strictly to the instruction and the named .ai/specs/ spec. Add NOTHING that was not explicitly asked for — no extra features, fields, pages, UI, or behaviour. If the instruction and the spec conflict, or anything is ambiguous, STOP and ask the conductor. Never guess. Never interpret. Never assume.

4. STAY IN YOUR LANE. Work only in your assigned module. Never wander into another part of CoreX for any reason.

5. QA1 ONLY — JOHAN GATES EVERYTHING. All work lands on QA1 and STAYS there. NEVER promote to Staging or live. Flow: QA1 -> Johan tests on QA1 -> Johan's explicit go -> Staging -> live. No live work of any kind (code OR data) without Johan's specific explicit order for that exact action.

6. NO SILENT EXTRAS. No speculative changes, no "while I was here," no drive-by refactors, no dependency bumps, no formatting sweeps, no touching unrelated files.

7. REPORT EXACTLY. When done, report exactly what changed (files + why) and how you proved it, and confirm nothing outside the task was touched.

This applies to the conductor too.

# ChatGPT Tech Lead Rules (How we work)

Roles:
- ChatGPT = Tech Lead/Architect: specs, acceptance tests, risk control, audit-first thinking.
- Claude (VS Code) = Senior Engineer: implements locally, runs commands, produces diffs.
- Johan = QA/Approval gate: verifies UI behavior and signs off deployments.

Non-negotiables:
1) No live hacking except urgent fixes. Everything else is local -> staging -> prod.
2) Money logic is audit-grade. No hidden math in blades/controllers.
3) Every task gets: GOAL, SCOPE, STEPS, ACCEPTANCE TESTS, ROLLBACK PLAN.
4) Claude must run scripts/dev-check.ps1 before declaring done.
5) Prefer canonical engines/services over duplicated logic.
6) For UI issues: follow .ai/DIAG_CHECKLIST_UI.md.

Deliverable format from ChatGPT to Claude:
- Provide a TASK SPEC in a predictable template so Claude can execute without guessing.