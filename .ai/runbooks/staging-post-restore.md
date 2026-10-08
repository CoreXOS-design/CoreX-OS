# Staging post-restore runbook

**When to run this:** any time Andre (or anyone) restores Staging's `hfc_staging`
database from a live/production copy. A restore replaces the whole database with
a snapshot that predates tonight's 2026-09-15 promotion (rental applications,
DR2 multi-property, Core Matches) — the new tables/columns/permission grants/
reference rows that promotion needs simply won't be there until this sequence
runs. **Code is untouched by a DB restore** — Staging's checkout stays on
whatever commit it's on; only the database needs bringing forward.

Safe to run against a DB that already has some or all of this schema —
every step here is idempotent (`migrate --force` only runs pending migrations,
`corex:sync-permissions --merge-defaults` only adds what's missing, `schema:dump`
just overwrites the snapshot file). Running this twice in a row does nothing
harmful the second time.

Run every command from `/corex-staging`.

## 1. Migrate

```
php artisan migrate --force
```

Confirm 0 `FAIL` lines in the output. If anything fails, STOP — do not proceed
to step 2 on a half-migrated schema. (2026-09-15 note: migration
`2026_09_09_060100_seed_and_backfill_rental_application_highlighters` was fixed
that day precisely so a from-scratch bulk migrate like this one succeeds — if
you see it fail again, that fix regressed; check
`database/migrations/2026_09_09_060100_seed_and_backfill_rental_application_highlighters.php`.)

## 2. Reference-data backfill (seeder-owned GLOBAL rows migrate --force does not carry)

```
php artisan deploy:sync-reference-data
```

This is the standing rule (CLAUDE.md non-negotiable #8h) for every deploy that
touches schema, not something special to a restore — a restore is just another
case of "the DB doesn't have the seeder-owned rows yet."

## 3. Permission sync

```
php artisan corex:sync-permissions --merge-defaults
```

Adds any permission definitions/role grants introduced by migrations that
predate this restore's data but postdate its schema. Preserves any custom
role_permissions grants that already exist — never overwrites a customisation.
Safe to run even if there's nothing pending (reports "0 created" / "up to
date" and does nothing).

**Verify it actually took** — this is not optional. On 2026-09-15 a grant this
exact command made was found missing on a later re-check with no identified
cause (no scheduler is configured against `/corex-staging` in crontab — checked
`crontab -l` and `php artisan schedule:list`, neither lists it — so the cause
is still open). Confirm for a real staff account, not just report the command's
own summary line:

```
php artisan tinker --execute="
\$u = App\Models\User::find(22); // Johan, agency 1 — adjust if testing as someone else
foreach (['rental_applications.view','create_deals','view_deals','core_matches.view','core_matches.all_view'] as \$k) {
  echo \$k.': '.(\$u->hasPermission(\$k) ? 'YES' : 'NO').PHP_EOL;
}
"
```

If any of these come back NO, re-run step 3 and check again before telling
anyone testing is safe.

## 4. Schema/table check

```
php artisan tinker --execute="
foreach (['rental_applications','rental_application_highlighters','deal_properties','contact_matches','contact_match_shares','contact_match_reassignments','contact_match_share_properties','contact_match_link_opens','rental_application_decline_reason_templates'] as \$t) {
  echo \$t . ': ' . (Illuminate\Support\Facades\Schema::hasTable(\$t) ? 'EXISTS' : 'MISSING') . PHP_EOL;
}
"
```

Every line must say EXISTS. Anything MISSING means step 1 didn't actually run
against this database (wrong `.env`, wrong host) — stop and check `DB_DATABASE`/
`DB_HOST` in `.env` before doing anything else.

## 5. Cache clears

```
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

## 6. Reload php-fpm

Staging is served by the **php8.2-fpm** pool specifically (confirmed via
`/etc/nginx/sites-enabled/staging.corexos.co.za` — `fastcgi_pass
unix:/run/php/php8.2-fpm.sock`), not whichever PHP version happens to be
newest on the box. Reloading the wrong pool does nothing and leaves stale
opcache/views being served.

```
sudo systemctl reload php8.2-fpm
```

## 7. Outbound mail after a restore — Staging cannot send real mail (2026-10-08)

A restore brings in real client/agent/landlord email addresses **and live's `dev_settings`
table** (including `mail_intercept_forced`). Since 2026-10-08 that cannot make Staging send:
`app/Support/OutboundMailGuard.php` decides by **environment configuration** — only
`production` on `corexos.co.za` / `www.corexos.co.za` **with `OUTBOUND_MAIL_REAL_SEND=1` in its `.env`** sends — that
flag lives on the real live server only; never copy it (or live's whole `.env`) to a test box. Every other environment (Staging,
QA1/QA2, demo, live-testing, local) intercepts, whatever the database says, and agent
mailboxes' own SMTP/IMAP are redirected to the local Mailpit or simulated. Nothing to flip.

Still check, after every restore, that the box's own mail config points at the local catcher
(the guard refuses to send at all if it does not, and logs `OUTBOUND MAIL GUARD BOOT CHECK
FAILED` at CRITICAL):

```
php artisan tinker --execute="
echo 'intercepting: ' . (App\Support\OutboundMailGuard::isActive() ? 'YES' : 'NO - STOP') . PHP_EOL;
echo 'tripped (real mail host configured): ' . (App\Support\OutboundMailGuard::isTripped() ? 'YES - fix .env MAIL_HOST / MAIL_COREX_HOST / MAIL_OTP_HOST' : 'no') . PHP_EOL;
echo 'stored override (ignored here): ' . (App\Models\DevSetting::get('mail_intercept_forced', null) ?? 'none') . PHP_EOL;
"
```

Expected: `intercepting: YES`, `tripped: no`. `MAIL_HOST`, `MAIL_COREX_HOST` and `MAIL_OTP_HOST`
must all be 127.0.0.1 (or another local catcher). Held messages are listed under Settings →
Email Setup and in the `outbound_mail_guard_captures` table.

## 8. Verify with a real fetch

Don't take the above as proof the site works — confirm with an actual
authenticated HTTP fetch through php-fpm, same as any other deploy:

```
php scripts/fetch-authenticated-page.php --app-root=/corex-staging --user-id=<a real or dedicated test user id> --url=https://staging.corexos.co.za/corex/core-matches/all?scope=agency --out=/tmp/verify.html
```

Must return `HTTP_STATUS=200`.
