# ⛔ BEFORE this code reaches the live server: set `OUTBOUND_MAIL_REAL_SEND=1` in live's `.env`

**Who:** Andre (or whoever deploys live). **Where:** the real live server only (62.238.31.82, `/corex`, `corexos.co.za`).
**When:** BEFORE the deploy that brings the 2026-10-08 outbound-mail-guard change to live.

## The one thing

Add this line to the live `.env`, by hand:

```
OUTBOUND_MAIL_REAL_SEND=1
```

then `php artisan config:clear` (or `config:cache` if live caches config) and reload php-fpm + restart the queue workers.

**If you do not**, the new code treats live as a copy and **intercepts ALL mail**: tenants, landlords, applicants,
agents, signing links — nothing is delivered, everything is silently held in `outbound_mail_guard_captures`.
`scripts/deploy.sh production` therefore **refuses to run** (pre-flight step 1d) with
`OUTBOUND_MAIL_REAL_SEND=1 is MISSING from …/.env` until the line is there. That refusal is the safety net, not a bug.

## Why it exists

`/corex` on the demo box (the live-testing copy, cron every minute) runs with `APP_ENV=production` and
`APP_URL=https://corexos.co.za` — identical to live in every way the code could previously check. Environment + host
cannot tell a copy from the real thing, and a copy that sends real mail to real tenants is the incident this prevents.
A flag that is set by hand on exactly one server, and that no code, database row, restore or copied `.env.example`
carries, can.

## Rules

- **Live:** flag `=1` (required; deploy refuses without it).
- **Everywhere else** (Staging, QA1, QA2, demo, live-testing, local): the flag must be **absent**. `scripts/deploy.sh
  staging` refuses if it finds it set. Never copy live's `.env` onto another box without deleting that line.
- Sending also still needs `APP_ENV=production` **and** a production host (`corexos.co.za`, `www.corexos.co.za`).
  Flag alone on any other environment does nothing (tested).
- A production-looking box without the flag logs `OUTBOUND MAIL GUARD: this environment looks like real production …
  OUTBOUND_MAIL_REAL_SEND is not set - ALL MAIL IS BEING INTERCEPTED` at CRITICAL on every boot. On a copy that is correct.
  On live it means the line is missing.

## Verify on live after setting it

```
php artisan tinker --execute="echo 'sending: ' . (App\Support\OutboundMailGuard::isSendingConfirmed() ? 'YES' : 'NO - flag missing') . PHP_EOL;"
```

Expected `sending: YES`. Then send yourself one real test mail.

Spec: `.ai/specs/outbound-mail-guard.md`. Post-restore on test boxes: `.ai/runbooks/staging-post-restore.md` §7.
