# HF Coastal Nexus — Claude Instructions

## MANDATORY: Read before every task

Before doing anything, read and follow these files in order:

1. [.ai/CLAUDE_EXECUTION.md](.ai/CLAUDE_EXECUTION.md) — execution rules, output format, done criteria
2. [.ai/COMMAND_GATE.md](.ai/COMMAND_GATE.md) — allowed/blocked commands
3. [.ai/DIAG_CHECKLIST_UI.md](.ai/DIAG_CHECKLIST_UI.md) — UI diagnosis checklist (use when page shows 0/blank)

## MANDATORY: Before declaring any task done

Run `scripts/dev-check.ps1` (or VS Code task: **Dev Check**).

## Output format

Every task response must follow the format defined in `.ai/CLAUDE_EXECUTION.md`:

```
PLAN:
FILES TO TOUCH:
CHANGES MADE:
COMMANDS RUN (with results):
DIFF SUMMARY:
RISKS / NOTES:
DONE CRITERIA CHECK:
```

## Key rules (from CLAUDE_EXECUTION.md)

- Minimal changes only. No refactors unless explicitly requested.
- No regex patching. Edit files normally.
- LOCAL dev only — never touch production.
- After any change: `php -l` on PHP files, `php artisan view:clear` on Blade, `php artisan route:clear` on routes/controllers.
- Nexus sidebar = `resources/views/layouts/sidebar.blade.php`
- Agency Tracker sidebar = `resources/views/layouts/nexus-sidebar.blade.php` — DO NOT modify unless explicitly told.
