# Rollback Protocol (Local Dev)

Before changes:
- Create a git branch: fix/<topic>
- Ensure working tree clean

If something breaks:
- git status
- git diff
- revert files: git restore <file>
- or revert everything: git reset --hard HEAD

Never 'fix forward' blindly—identify root cause first.