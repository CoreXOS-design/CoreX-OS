# CoreX OS — Command Gate

Authorised commands and sequences. Do not deviate without a documented reason.

---

## Deploy Sequence (Production Server)

Run in this exact order every time:

```bash
git stash
git pull
git stash pop
npm run build
php -r "opcache_reset();"
php artisan view:clear
php artisan cache:clear
```

**Before deploying:**
1. Check `git diff main..HFC2402 --stat` — know what you're pushing
2. Check for Andre's recent commits on `main` — never overwrite his work
3. Run the test suite locally: `scripts/dev-check.ps1`

---

## Branch Rules

| Action | Rule |
|--------|------|
| New feature (Johan) | Branch off `HFC2402` or work directly on `HFC2402` |
| New feature (Andre) | Branch off `andre` |
| Merge to `main` | Both parties check + agree — no silent pushes to production |
| Hotfix | Branch off `main` → fix → merge back to `main` + backport to dev branches |

---

## Migration Rules

- Every schema change gets a migration — no manual `ALTER TABLE` on server
- Run `php artisan migrate` after pulling migrations from another branch
- Check `php artisan migrate:status` before running to see what's pending
- Never roll back a production migration without a recovery plan

---

## .ai Folder Sync Rule

The `/.ai/` folder is the source of truth for all specs and architecture. It lives on `main`.

**Before starting any dev session:**
```bash
git pull origin main -- .ai/
```

**After updating any spec:**
```bash
# Commit .ai/ changes to main directly (not via feature branch)
git add .ai/
git commit -m "docs: update [filename] — [what changed]"
git push origin main
```

---

## Server SSH

```bash
ssh root@91.99.130.85
cd /corex
```

---

## Python AI Service

Not tracked in git. Managed separately on server.

```bash
# Check if running
ps aux | grep app.py

# Restart (adjust command to match actual service setup)
sudo systemctl restart hf-ai
# or
pm2 restart hf-ai
```

---

## Useful Artisan Commands

```bash
php artisan route:list                    # See all registered routes
php artisan make:migration create_x_table # New migration
php artisan migrate                       # Run pending migrations
php artisan migrate:status               # Check migration state
php artisan queue:work                   # Start queue worker
php artisan tinker                        # REPL for testing models/queries
```
