# CoreX — Running the test suite (the real gate)

> AT-30. How to run `php artisan test` correctly on the Hetzner dev box so the
> result is a real signal, not a degraded one. If your run shows ~1000+
> failures all saying `Access denied for user 'root'@'localhost'`, your
> environment is misconfigured — fix it per this doc, do not report that as the
> baseline.

---

## The command

```bash
php artisan test
```

That's it. No env-var prefixes needed once the box is set up (below). The
runner reads `.env.testing` automatically because `phpunit.xml` sets
`APP_ENV=testing`.

To scope a run:

```bash
php artisan test --testsuite=Unit
php artisan test --filter=PolicyAcknowledgementTest
```

---

## One-time box setup (prerequisites)

These are per-machine, not committed (they're `.env` / `node_modules` /
`public/build`, all gitignored). Do them once on any box that runs the suite.

1. **`.env.testing`** — the test env file. Must exist with the box's real MySQL
   user (NOT `root@localhost` with an empty password — this host denies that
   over TCP). Create it from `.env`:
   ```bash
   cp .env .env.testing
   # then set, at minimum:
   #   APP_ENV=testing
   #   DB_DATABASE=hfc_dash_test     (throwaway test schema — never a real DB)
   #   DB_USERNAME=corexdev          (the working local MySQL user)
   #   DB_PASSWORD=<corexdev pw>     (same as .env)
   #   MAIL_MAILER=array  QUEUE_CONNECTION=sync  CACHE_STORE=array  SESSION_DRIVER=array
   ```
   `.env.testing` is gitignored (it holds a DB credential) — it lives on the
   box, like `.env`.

2. **The `hfc_dash_test` database** must exist and be accessible to that user:
   ```bash
   mysql -ucorexdev -p -h127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS hfc_dash_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
   `RefreshDatabase` loads `database/schema/mysql-schema.sql` then any newer
   migrations. Keep the snapshot current (`php artisan schema:dump` after adding
   a migration — non-negotiable #12a).

3. **Vite build** — `npm install && npm run build` once, so
   `public/build/manifest.json` exists. Without it, every view-rendering test
   throws `ViteManifestNotFoundException` (HTTP 500) and fails for the wrong
   reason.

4. **Puppeteer** (for receipt-PDF code paths — RMCP + Policy receipts) is a
   declared dependency; `npm install` pulls it. If Chromium isn't fetched on
   install, set `PUPPETEER_BROWSER_PATH` or run puppeteer's browser install.

---

## Why `phpunit.xml` no longer hardcodes the DB user

`phpunit.xml` pins **only** `DB_DATABASE=hfc_dash_test` (a safety guard so tests
can never touch a real database) plus the connection/host/port. It deliberately
does **not** set `DB_USERNAME`/`DB_PASSWORD`, so those come from `.env.testing`
(or `.env`) — i.e. the box's real MySQL user. Previously it forced
`root` / empty-password, which this host denies over TCP, producing ~1000
false "Access denied" failures.

---

## The dev-check wrapper (Windows)

`scripts/dev-check.ps1` is the PowerShell wrapper Johan runs on Windows/laragon
(clears + pipeline gate; `-Full` adds the suite). It is **not** runnable on this
Linux box (no `pwsh`). On the Hetzner box, `php artisan test` is the equivalent
gate; the e-sign pipeline gate in dev-check is a separate concern.

---

## Known baseline (this dev box)

A correctly-configured run is **not** zero failures here — a band of tests
depend on services/binaries not present on the dev box (imagick/AI photo
pipelines, external API keys, some Puppeteer/Chromium and rendering paths,
tenancy-backfill edge fixtures). Establish your own baseline before a change and
compare deltas; judge work by **new** failures in the touched area, not the
absolute count. The current baseline numbers are recorded in the AT-30 ticket
and CHAT_STARTER decisions log.
