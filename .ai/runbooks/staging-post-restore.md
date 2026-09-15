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

## 7. Outbound-mail reminder (do not silently flip this — read it, then decide)

A restore brings in real client/agent/landlord email addresses. Staging is
configured to send real mail **by default** (`app/Support/OutboundMailGuard.php`
lists `staging.corexos.co.za` as a `SENDING_ENVIRONMENTS` entry — Johan's own
earlier instruction, so real people's real addresses can end up in real outbound
mail the moment anyone triggers a send-shaped action (declines, work orders,
Core Matches composer "I sent this" flows that also fire a system notification,
etc.) Check the live state before testing starts:

```
php artisan tinker --execute="
echo 'override: ' . (App\Models\DevSetting::get('mail_intercept_forced', null) ?? 'none (environment default applies)') . PHP_EOL;
echo 'currently sending for real: ' . (App\Support\OutboundMailGuard::isActive() ? 'NO (intercepted)' : 'YES') . PHP_EOL;
"
```

If it says "YES", that is Staging's normal, deliberate default — not a bug.
Whether to force-intercept for a given test session is Johan's call (only a
super_admin can flip `DevSetting::TOGGLE_KEY` via the Settings → Mail
Interception screen) — this runbook does not make that call for him, it just
makes sure he's looking at the real current state before testing starts.

## 8. Verify with a real fetch

Don't take the above as proof the site works — confirm with an actual
authenticated HTTP fetch through php-fpm, same as any other deploy:

```
php scripts/fetch-authenticated-page.php --app-root=/corex-staging --user-id=<a real or dedicated test user id> --url=https://staging.corexos.co.za/corex/core-matches/all?scope=agency --out=/tmp/verify.html
```

Must return `HTTP_STATUS=200`.
