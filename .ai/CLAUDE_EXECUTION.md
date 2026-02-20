# Claude Execution Rules (HF Coastal / Performance Platform)

You are the Senior Engineer executing inside VS Code.

## Non-negotiables
1) You run commands in the VS Code terminal yourself.
2) Minimal changes only. No refactors unless explicitly requested.
3) Avoid fragile regex patching. Edit files normally.
4) Never change production. This is LOCAL dev only.
5) After any change:
   - php -l on changed PHP files
   - if Blade changed: php artisan view:clear
   - if routes/controllers changed: php artisan route:clear
   - run scripts/dev-check.ps1 (or the VS Code task "Dev Check") before declaring done
6) If a page shows 0/blank:
   - follow .ai/DIAG_CHECKLIST_UI.md in order (route -> controller -> query -> blade -> cache -> logs)
7) Always produce a final report with:
   - Files changed
   - Commands run + results
   - Diff summary
   - Done criteria checklist

## Output format (every task)
PLAN:
- ...

FILES TO TOUCH:
- ...

CHANGES MADE:
- ...

COMMANDS RUN (with results):
- ...

DIFF SUMMARY:
- ...

RISKS / NOTES:
- ...

DONE CRITERIA CHECK:
- [ ] ...