# Outbound mail guard — what can send real mail, and what cannot

Code: `app/Support/OutboundMailGuard.php`, `app/Providers/OutboundMailGuardServiceProvider.php`,
`PerMailboxMailTransportBuilder`, `ImapSentFolderAppender`, `MailInterceptToggleService`.
History: `at395-outgoing-mail-per-mailbox-smtp.md` (per-mailbox SMTP), AT-URGENT-2026-09-08/09 (the guard and
its kill switch), 2026-09-28 §42 (raw per-mailbox sockets gated on environment). This file is the current rule.

## 1. The rule (2026-10-08, Johan via conductor)

**Only real production sends real mail.** "Real production" = `APP_ENV=production` **and** the app URL host is
`corexos.co.za` or `www.corexos.co.za` **and** the explicit server-side flag `OUTBOUND_MAIL_REAL_SEND=1`
(`config('mail.guard.real_send')`), set by hand on the real live server and on no other. The flag exists because
environment + host cannot tell a copy from the original: `/corex` on the demo box (the live-testing copy) runs
`APP_ENV=production`, `APP_URL=https://corexos.co.za`. **Without the flag nothing sends, even with APP_ENV=production:**
the guard intercepts and logs `OUTBOUND MAIL GUARD: this environment looks like real production … OUTBOUND_MAIL_REAL_SEND
is not set - ALL MAIL IS BEING INTERCEPTED` at CRITICAL on every boot. `scripts/deploy.sh production` refuses to run
if the flag is missing from the target `.env` (and `deploy.sh staging` refuses if it is present). **Andre must set it
on live before this code lands there:** `.ai/runbooks/outbound-mail-real-send-flag.md`. Every other environment — Staging, QA1, QA2, demo, live-testing
(`APP_ENV=production` but another host), local, testing — **intercepts**, decided by environment configuration
inside `OutboundMailGuard::isActive()`. No database value is read for such an environment: a `dev_settings` table
restored from live (`mail_intercept_forced` = `0`, anything else, or absent) cannot switch the guard off.

Why: Staging used to be a "sends by default" environment. A restore of live data onto Staging brings real tenants'
and landlords' addresses and live's `dev_settings`; 18 of Staging's 20 agent mailboxes hold real SMTP credentials.
On 2026-10-08 the only thing holding Staging back was the database row `mail_intercept_forced=1`.

## 2. What each path does

| Path | Real production | Every other environment |
|---|---|---|
| Default mailer (`Mail::to`, notifications, queued mail) | sends; super-admin kill switch can force-intercept | vetoed at `MessageSending`, captured (`outbound_mail_guard_captures`), copy to the local Mailpit when one exists |
| Per-mailbox SMTP (`PerMailboxMailTransportBuilder`) | connects to the mailbox's own host | connects to the local sink instead (never the real host) |
| IMAP Sent-folder append | appends | simulated, no connection |
| Signed-document distribution | sends | `MAIL_NON_PRODUCTION_REDIRECT` rewrite or suppress (unchanged) |
| Settings → Email Setup "force send" | allowed (audited, reason required) | **refused** ("held by environment configuration"); "force intercept" still allowed |

## 3. Boot-time check

`OutboundMailGuard::auditBootConfiguration()` runs when the guard's provider boots, from config only (no
database). On any non-production environment it checks the default mailer's transport and the `smtp`, `corex`
and `otp` mailer hosts plus the guard's sink host (`config('mail.guard.*')`, which replaces direct `env()` reads
that returned null under `config:cache`). A host that is not a local catcher (loopback, private ranges,
`localhost`/`mailpit`/`mailhog`, `*.test`, `*.localhost`, blank) logs
`OUTBOUND MAIL GUARD BOOT CHECK FAILED` at CRITICAL and **trips** the guard (`isTripped()`, derived from config on
every call, so it cannot go stale): everything is vetoed and captured, **nothing** is forwarded even to the sink,
and per-mailbox sends throw `blocked_by_guard`. Production is never audited (it is meant to use a real host).

## 4. Tests

`tests/Unit/Support/OutboundMailGuardTest.php` (environment table; restored-from-live values `0/false/off/garbage/''/absent`
on Staging still intercept; production unchanged incl. kill switch; boot check trips on a real host, quiet on a local
catcher and never on production; host classification), `tests/Feature/Communications/OutboundMailGuardCaptureTest.php`
(restored-from-live DB on Staging config: a real send is held; production config: not intercepted; tripped guard
forwards nothing), `MailInterceptToggleTest` (force-send refused outside production).

## 5. Operating notes

- After any restore onto a non-production box run the check in `.ai/runbooks/staging-post-restore.md` §7.
- Testing the poller / sending path against a real mailbox is no longer possible on Staging. Use the local Mailpit
  (what the redirected sends reach) or a deliberate, separate decision by Johan — not a database switch.
- Test-connection buttons on non-sending environments report the Sent-folder step as "skipped" (reason `simulated`
  is treated like `intercepted`), not as a failure.
