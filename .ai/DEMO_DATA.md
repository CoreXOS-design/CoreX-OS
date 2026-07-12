# CoreX OS — Demo Dataset (`DemoDataSeeder`)

> One command builds a complete, coherent KZN South Coast estate-agency
> demo: prospect → tracked property → claim → pitch → contact/wishlist →
> agency stock → buyer matches → presentation → FICA → e-sign → OTP →
> deal register → registered.

Last verified: 2026-05-18 — 15/15 verifications passed on a fresh DB.

---

## Run it

```bash
php artisan demo:seed
```

- **Local:** runs directly, no flags. `demo:cleanup` likewise.
- **Non-local demo environment (double-lock).** The demo SERVER runs `APP_ENV=production`, so the guard does **not** key on `APP_ENV` alone. To seed/clean a deliberately opted-in non-local demo environment:
  1. Set **`DEMO_SEED_ALLOWED=true`** in **that environment's `.env`** (the opt-in lock).
  2. Run **`php artisan demo:seed --force`** (the operator lock). Same for `php artisan demo:cleanup --force`.

  Both conditions are required. A real production box that has **not** set `DEMO_SEED_ALLOWED=true` can **never** be demo-seeded or demo-cleaned, even with `--force`. If `DEMO_SEED_ALLOWED` is set but the box has a cached config, run `php artisan config:clear` first (the gate fails safe — it refuses rather than wrongly proceeding). The gate logic lives in `DemoDataSeeder::environmentGateRefusal()` and is enforced in `demo:seed`, `demo:cleanup`, and the seeder's own `run()`.
- **Safe.** The seeder asserts the mail driver is `log`/`array` (or `smtp`→localhost) and **aborts** otherwise; it also `Mail::fake()` + `Queue::fake()` + `Bus::fake()` for the whole run. No real email is ever sent, no real external API is ever called.
- **Re-runnable.** Designed for `migrate:fresh` then `demo:seed`. A deterministic RNG seed means a fresh-DB re-run produces an **identical** structure every time. (It is *additive* if run twice without `migrate:fresh` — always `migrate:fresh` first for a clean dataset.)
- **Agency.** Everything targets `agency_id = 1` ("HFC Coastal"), which `migrate:fresh` already creates. Reference seeders hardcode agency 1.

Typical full reset:

```bash
php artisan migrate:fresh
php artisan demo:seed
```

---

## Demo login

| Field | Value |
|-------|-------|
| URL | `/login` then `/corex/market-intelligence` |
| Email | `admin@demo.corexos.co.za` |
| Password | `CoreXDemo!2026` |
| Role | `admin` (sees everything in agency 1; is a "manager" so BM-only chips show) |

Branch managers / agents log in with `…@hfcoastal.co.za` (company domain, so the e-sign agent-FROM path is exercised) and password `CoreXDemo!2026`. A read-only `viewer@hfcoastal.co.za` user also exists.

> **This admin was `demo@corexos.co.za` until 2026-07-12.** It had to move.
> `users.email` is `utf8mb4_unicode_ci` — **case-insensitive** — under a UNIQUE
> index, so `demo@corexos.co.za` and the System Owner's `Demo@corexos.co.za`
> are *the same row* to MySQL. The two accounts cannot both exist while they
> differ only by capitalisation. The owner keeps the address; the tenant admin
> moved to the demo user domain. `SystemOwnerSeeder` now hard-refuses to seed
> if any agency-owned user holds that address in any casing.

## System Owner login (platform, not tenant)

| Field | Value |
|-------|-------|
| URL | `/demo-owner-login` |
| Email | `Demo@corexos.co.za` |
| Password | `Demo@1024` |
| Role | `super_admin` (`is_owner`), `agency_id` NULL — a platform identity, not a member of agency 1 |

Johan's private door into the demo — used to wire the demo up to live. Seeded by
`SystemOwnerSeeder`, which `DemoDataSeeder` calls, so **`demo:seed` restores it on
every rebuild**. It is email+password only: `super_admin` is deliberately absent
from `DemoLoginController::ALLOWED_ROLES`, so the passwordless persona buttons on
the demo login screen can never sign anyone in as the owner.

---

## What it creates (per-module volume, fresh-DB)

| Module | Volume | How it's built |
|--------|--------|----------------|
| Branches | 3 (Margate, Shelly Beach, Port Shepstone) | raw insert |
| Users | 14 (1 admin, 3 BMs, 9 agents, 1 viewer) | raw insert |
| Contacts | ~200 (≈130 buyers, sellers, pitch + spine sellers) | raw insert |
| Buyer wishlists | ~57 `contact_matches` | Eloquent (observer fires) |
| Prospecting listings | ~212 | raw insert |
| Tracked properties | ~137 | **`TrackedPropertyMatchOrCreateService::matchOrCreate()`** (212 listings dedupe to ~137 via the 5-strategy matcher — by design) |
| Prospecting claims | ~44 incl. all chip recipes | **`ProspectingClaimService`** |
| Seller-outreach sends | ~39 | **`SellerOutreachComposerService` + `SellerOutreachSenderService`** |
| Agency-stock properties | ~100 | **`promoteToStock()`** + raw demo stock |
| Buyer matches | ~6 400 `prospecting_buyer_matches`, ~1 500 `property_buyer_matches` | `prospecting:recompute-matches` + `matches:recompute` + direct chip rows |
| Presentations | ~42 (draft + finalized, all compiled) | `Presentation::create` + **`PresentationCompilerService::compile()`** |
| FICA submissions | ~32 across draft→submitted→under_review→agent_approved→approved | `FicaSubmission::create` + `->update()` |
| E-sign | ~27 documents + signature requests (waiting/pending/completed) | **`SignatureService::createTemplate/createSigningRequest`**, status via `->update()` (never `sendSigningRequest()`) |
| OTP | ~24 `client_otps` (mix verified / pending) | raw insert |
| Deal Register v2 | ~52 deals, ~22 driven to **registered (completed)** | **`DealPipelineService::createDeal()`** + step progression |
| Calendar events | ~550 (110 demo + deal-auto-generated deadlines), past + next 3 weeks | raw insert + `DealV2Observer` |
| Full-lifecycle spine | 12 properties threaded prospect → registered | all of the above, chained |

Service-constructed wherever a CLI-safe service exists; raw `Model::create()`/`DB::table()` only where the create path is controller-only (Agency/Branch/User/Contact/Property/ContactMatch/ProspectingListing/FICA/OTP/e-sign status) — per the service-inventory investigation.

### Suggested-action chips

Every rule **R1–R9** has at least one deliberately-constructed live demo listing/claim so the chip fires when the demo admin views Market Intelligence:

R1 flag-to-BM · R2 claim-expires-soon · R3 log-outcome · R4 follow-up · R5 pitch-now-HIGH · R6 pitch-now · R7 re-pitch-stock · R8 resolve-colleague-claim · R9 investigate.

### The spine (headline)

12 properties thread the **complete** chain end-to-end (prospect listing → tracked property → claim → pitch → seller + buyer contact → wishlist → promote to stock → buyer match → finalized presentation → approved FICA → completed e-sign → verified OTP → registered deal). Other properties are deliberately left at every intermediate stage so the demo can show "one being prospected, one at presentation, one mid-deal, one registered".

---

## Known issues (pre-existing, NOT introduced by the seeder)

1. **`NotificationEventTypeSeeder` (FIXED in this change).** `database/seeders/DatabaseSeeder.php` referenced a class that does not exist anywhere — a bare `php artisan db:seed` threw class-not-found. The broken line was removed; the remaining 12 references resolve.

2. **`AgencyDocumentTypeConfigSeeder` is broken on a fresh DB.** It writes a column `allows_branch_override` that no migration creates on `migrate:fresh`. It is in the main `DatabaseSeeder` list, so `php artisan db:seed` also hits this. The demo seeder wraps every reference seeder so a broken one is logged and **skipped** rather than aborting the build. This is a separate compliance-migration bug (a missing migration or a seeder ahead of schema) and is out of scope for the demo-seeder task — flagged here for follow-up.

3. **`DealPipelineService` writes an invalid enum value.** The seeded "Standard Bond Sale" pipeline's *Bond Approved* step has `status_trigger = 'granted'`, but `deals_v2.status` is `enum('active','completed','cancelled','on_hold')` under `STRICT_TRANS_TABLES`. `DealPipelineService::approveStep()` does `$deal->update(['status' => 'granted'])`, which MySQL rejects (error 1265) and the transaction rolls back — so deals **cannot** be driven past Bond Approved through the normal service path. The seeder routes around this: it completes the step and replicates `approveStep()`'s safe bookkeeping (`approval_status='approved'` + `approved_by/at`) **without** the invalid status write, then calls the service's own `activateDownstreamSteps()`. *Registration* (`status_trigger='completed'`) is a valid enum value, so the deal reaches `completed` via the real service. **Recommended product fix:** map pipeline trigger tokens (`granted`) to valid `deals_v2.status` values in `changeDealStatus()`, or widen the enum. This is a latent bug in the deal pipeline, independent of the demo seeder.

---

## Verification matrix (15/15)

V1 fresh+seed clean · V2 deterministic re-run · V3 volumes · V4 spine traced end-to-end · V5 MI Work renders with chips · V6 every chip rule has live data · V7 MI Analyse renders (demand matrix + opportunity pockets + velocity) · V8 deals at varied stages incl registered · V9 calendar past + next 3 weeks · V10 signature requests exist, zero real sends · V11 demo login valid · V12 aborts on smtp→non-local · V13 `php -l` clean · V14 `view:clear` clean · V15 Mail/Queue/Bus faked.

> V5/V7 were verified by a real server-side controller+view render against the seeded DB (Work 555 KB / Analyse 209 KB of populated HTML with chip + analyse-partial markers), not a browser screenshot — screenshots are not possible in the build environment.

---

## Notes

- Verification used an **isolated `nexus_os_demo`** database (created locally) so the working `nexus_os` dev DB was never touched. This also mirrors the real architecture where the demo site (`demo.corexos.co.za`) gets its own database. Drop it any time with `DROP DATABASE nexus_os_demo;` if you don't want it.
- The seeder is environment-agnostic: no hardcoded paths, reads `config()`, targets agency 1.
- All demo contacts/properties carry a `[DEMO]` name/title prefix; prospecting listings use `DEMO-…` portal refs; tracked properties carry a `demo*` source ref — so demo rows are always distinguishable.
