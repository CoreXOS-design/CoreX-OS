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