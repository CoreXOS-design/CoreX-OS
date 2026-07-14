# AT-263 — live promotion runbook (READY, **NOT EXECUTED**)

**Status: HELD.** Live promotion requires Johan's explicit word. Nothing below has been run
against `/corex` (live). QA1 and Staging are done and verified.

| Env | State |
|-----|-------|
| qa1 (`/corex-qa1`, branch `QA1`) | ✅ deployed + seeded + rendered (ids 15 WhatsApp / 16 email) |
| Staging (`/corex-staging`, branch `Staging`) | ✅ deployed + seeded + rendered (ids 15 / 16) |
| **live (`/corex`, branch `main`)** | ⛔ **HELD — awaiting Johan** |

## What ships

Two template rows for agency 1 (Home Finders Coastal) — one email, one WhatsApp — added to the
existing seller-outreach library. **Code-only change plus an idempotent seeder: no migration, no
schema change, no reference-data change, no new merge field.**

Changed files:
- `database/seeders/HfcConsentTemplatesSeeder.php` — one new entry (seeds both channels)
- `tests/Feature/SellerOutreach/ProspectingIntroTemplateTest.php` — 6 tests, all green

## The steps (live) — run only on Johan's word

```bash
cd /corex
git pull --ff-only origin main          # after AT-263 is merged Staging → main

# The template rows. Idempotent (updateOrCreate on agency+channel+name); runs the REAL
# SellerOutreachTemplateValidator and fires the same TemplateConfigured audit event the
# admin UI fires. Safe to re-run.
php artisan db:seed --class=HfcConsentTemplatesSeeder --force

php artisan view:clear && php artisan route:clear && php artisan config:clear && php artisan cache:clear

sudo systemctl reload php8.3-fpm        # LIVE is php8.3 — NOT 8.2 (that is staging/demo)
php artisan queue:restart
sudo supervisorctl restart corex-worker-live:
```

No migration to run. No `deploy:sync-reference-data` needed (it does not carry this seeder — see below).

## Verify after promotion

Render both channels against a real live contact + property, sending nothing:

```bash
cd /corex && php artisan tinker
```
…or reuse the composer harness: compose `Prospecting Introduction — Sales & Rentals` on each
channel and assert the rendered body has **no unresolved `{tokens}`** (other than `{opt_out_link}`,
which the sender substitutes at dispatch) and `isSendable() === true`.

## Deliberate decision: this seeder stays OUT of `deploy:sync-reference-data`

`HfcConsentTemplatesSeeder` is `updateOrCreate` keyed on (agency, channel, name). Registering it in
`deploy:sync-reference-data` would re-run it on **every** deploy — and would therefore **silently
revert any template body Johan edits in Settings → Outreach Templates** back to the seeder's copy.
These are agency content rows, not global reference data. It is run **once, explicitly, per host**,
which is what the steps above do.

(The re-run is still safe for the other 14 rows: they are seeder-owned and their bodies are
identical to the seeder's, so the "Updated" lines are no-ops. That holds only for as long as nobody
hand-edits them — which is exactly why it must not run automatically.)

## Rollback

Soft-delete the two rows (the system has no hard deletes):

```sql
UPDATE seller_outreach_templates
   SET deleted_at = NOW()
 WHERE agency_id = 1
   AND name = 'Prospecting Introduction — Sales & Rentals';
```

They vanish from Settings and from the composer picker. Every send already made keeps its own
immutable `body_snapshot`, so history is unaffected. Re-running the seeder restores them
(`withoutGlobalScopes()` matches the soft-deleted row and calls `restore()` — no duplicate).
