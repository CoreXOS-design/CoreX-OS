# Release audit — everything that went to main / live-testing on 2026-10-18

Release: `989f6ffd0..0c7bfebed` (1,160 commits, 2,743 files, 225 migrations) — main, Staging, QA2 and
live-testing.corexos.co.za (`/corex` on 91.99.130.85). The real live site corexos.co.za (62.238.31.82) was NOT deployed.

Requested by Johan: "please run a full audit on everything that was added to make sure all is good".
Method: machine checks over the whole release, plus four read-only reviews of the high-risk areas (each finding
traced through the code; the serious ones re-checked by the lead). No test suite was run (Non-negotiable #13).

## 1. Machine checks — clean

- `php -l`: 2,465 changed PHP files, 0 failures. 3,481 routes load. `view:cache`, `config:cache`, `event:list` OK.
- Live-testing: 0 pending migrations, 11 reference tables populated, 0 failed jobs and no new errors since the deploy
  (apart from the items in §3).
- Single-agency scan: one hit, `2026_10_14_150100_set_hfc_website_url_for_public_links.php` — deliberate, fills only
  HFC's own row (by slug, only if blank). Clean.

## 2. Findings from the four reviews

| Area | High | Medium | Low | Report |
|---|---|---|---|---|
| Access and agency isolation | 2 | 8 | 12 | `2026-10-18-release-audit-A-access.md` |
| Database migrations | 1 (plausible) | 1 | 7 | `2026-10-18-release-audit-B-migrations.md` |
| Outbound messages and schedules | 1 | 3 | 2 | `2026-10-18-release-audit-C-outbound.md` |
| Money and AI spend | 1 | 3 | 5 | `2026-10-18-release-audit-D-money.md` |

### High — lead-verified

1. **Money H1** — archiving a VAT type soft-deletes it; `RentalJobCardVatService::lineVat()` then finds no type and
   charges 0% ("No VAT") on every open job-card line using it (`RentalVatType::archive()` = `delete()`; `rateFor(null)` = 0).
2. **Access H2** — `RentalInventoryController::printForSignature()` has no `assertVisible()` (the `show()` beside it does):
   any agent in the agency can download a colleague's inventory PDF by id, and the GET also creates a public link.
3. **Access H1** — `RentalInventoryBuyerAcceptance::storeWetInkUpload()` saves the public upload under the CLIENT's file
   extension. Content is validated (`mimes:pdf,jpg,jpeg,png,heic`), so it needs a crafted polyglot, but a file that sniffs
   as PNG and is named `.html` is served from public storage as a web page. Fix: use the server-detected extension.
4. **Outbound F1** — `marketing:publish-due` (every minute) posts to real Facebook/Instagram with no non-production
   guard. On live-testing today: 0 posts waiting, 1 connected account.
5. **Migrations H1 (plausible)** — `2026_09_29_174606` adds a unique index on deals (agency, deal_no) incl. archived rows;
   duplicates would stop the migrate at step 4 of 225 on the real live server. Live-testing: 0 duplicates (passed).

### Pre-existing, found during this audit (not caused by the release)

- **Live-testing talks to the real Property24.** Status checks, lead/stat pulls, and on 18 Oct 00:00–00:06 seven listing
  status changes (Withdrawn / Expired) for real listing ids; most were refused (HTTP 401), at least one may have gone
  through. The release adds one more write path: `leases:advance-terms` (00:35 nightly) can re-list a rental (Outbound F3).
- **Live-testing's 12 Property24 import / photo workers have been FATAL since at least 00:00 on 18 Oct.**
- **18 Laravel file-session files are committed** under `storage/framework/sessions/` (added 12 Oct in AT-452 commits
  `625b2a9b2` / `5d500fafd`). Every site on this box uses database sessions, so they cannot be used here; they should be
  removed from git and the folder ignored.

### Release side effects to decide on

- The new OUTBOUND MAIL GUARD treats live-testing as "not production pointed at a real mail host": all email is refused
  there, and it logs a CRITICAL line on every process boot (~1,200 lines in the first 10 minutes) to a log on the 88%-full
  root disk.
- Migrations M1: `2026_10_18_000100` (already run on live) was deleted and replaced by differently named FICA columns with
  no copy. On live-testing every agency is on the defaults (14 days / 11 months), so nothing was lost there; the real live
  server must be checked before its deploy.

## 3. Status

Report only — nothing fixed yet. Fix plan pending Johan's go.
