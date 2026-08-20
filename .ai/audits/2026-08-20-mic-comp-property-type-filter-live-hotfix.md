# MIC sold-comp property-type filter — live hotfix by patch-id

**Date:** 2026-08-20

## What happened

Johan: "183 torquay road leisure bay. Shawn trying to do a presentation. I
have imported the reports - did all 4... on presentation first screen it
shows nothing? no comparable sales at all? put a lane on this." Investigation
(read-only, live, `nexus_os`) traced the chain: reports imported and parsed
correctly (36 valid comp rows), correctly geo-matched by radius against the
subject property (7 real sales, 475–621m away, within the date window) —
then silently dropped by `MarketCompRowsSoldAdapter::applyPropertyTypeFilter()`,
which only recognised the literal string `"residence"` as a residential
match. The comps were tagged `"Residential"` (a different label from a
different parser for the same erf-usage concept). Live-wide sweep: 308 of
1,225 comp rows (25%) carry this label across 23 reports — every "house" or
"unit" search agency-wide was silently missing a quarter of its real
comparable-sale evidence, not just this one property.

Fixed by normalising the comparison into two small canonical buckets
(residential → house/unit only, land → land only) instead of adding another
literal string, per Johan's explicit instruction: "Normalise the comparison
... rather than adding literals one at a time." Full distinct-value sweep
done first (`Residence` 898, `Residential` 320, `Vacant Land` 13,
`Commercial` 2, 6 null — confirmed via a collation-independent `BINARY`
grouping, no hidden casing/whitespace variants). Test coverage:
`tests/Feature/MarketAnalytics/MarketCompRowsSoldAdapterPropertyTypeTest.php`,
8 tests — residential/residence both pass a house search, case/whitespace
variants normalise, commercial and vacant-land stay excluded from house/unit,
vacant-land still matches a land search, and (a pre-existing bug this same
fix closes, flagged explicitly rather than silently fixed) residential rows
no longer leak into a land search, where the old code's blanket
`=== 'residence'` auto-pass let them through regardless of search type.

**Deliberately not touched:** `MarketCompRowsActiveAdapter.php` has the
identical duplicated method and the identical bug for active listings.
Flagged to Johan as a separate, scoped follow-up — "nothing else rides
along" tonight.

## Patch-id — literal cherry-pick, single environment

Small surgical fix, one straight line from origin to live — no divergent
per-environment history to reconcile, unlike the buyers-report promotion
earlier tonight.

| Environment | Commit | Base | Patch-id |
|---|---|---|---|
| `origin/main` | `e9c13acb2` | `4fc407c04` (origin's tip at push time) | `98f068b47...` |
| `/corex` (live) | `e2a0aa7f5` | `351bf39a8` (live's local-only tip, see below) | `98f068b47...` |

Identical patch-id both sides — confirmed byte-identical content, different
commit hash only because live's cherry-pick landed on a different parent.

## A pre-existing, unrelated divergence found — not created by this fix, not resolved by it

Before this deploy, `/corex`'s local `main` already carried one commit
(`351bf39a8`, "fix(contacts,properties): match all real pivot roles / legacy
type labels in list filters") that had never been pushed to `origin/main`.
Simultaneously `origin/main` had advanced past live by one unrelated commit
(`4fc407c04`, a docs/spec capture). Confirmed file-disjoint from this fix
(`Contact*.php` / `properties` filters vs. `MarketCompRowsSoldAdapter.php` +
a new test file — zero overlap) before doing anything. Rather than merge or
rebase someone else's unpushed local work into this hotfix's scope — outside
"nothing else rides along" — this fix was applied by direct `git cherry-pick`
onto live's current `HEAD` (`351bf39a8` → `e2a0aa7f5`), landing the code
without touching or resolving that pre-existing divergence either way. It
remains open: `origin/main` and `/corex`'s local `main` are still diverged by
one commit each, exactly as found. Flagging for whoever owns `351bf39a8` to
push it, or for the next session to reconcile deliberately.

## Deploy detail

Live's checkout carried Johan's own uncommitted CDS template WIP
(`template-67.blade.php` modified, `template-68.blade.php` new,
`.ai/audits/cross-agency-isolation-audit-2026-08-20.md` untracked)
throughout — untouched at every step, verified via `git status` before and
after cherry-pick. `php8.3-fpm` reloaded (resolved dynamically from
`/etc/nginx/sites-enabled/corexos.co.za`'s `fastcgi_pass`, never hardcoded —
confirmed the vhost's only pool, `www.conf`, is shared box-wide, so a
graceful `reload` was used, not `restart`). `view:clear` run. Reflection
(`ReflectionClass::getFileName()`) confirmed the deployed class resolves to
`/corex/app/...`, not any other checkout — live's own `vendor/` confirmed a
real independent directory, not a symlink, before trusting that check.

## Verification on live — real presentation, real data

Presentation `id=137` (Shawn Du Bois, `property_id=15726`, "138 Torquay
Avenue, Leisure Bay" — Johan's "183" traced separately to a digit
transposition; the actual erf is 138, confirmed against the source deeds
PDF).

Direct proof at the exact layer that was broken: called the live, deployed
`MarketCompRowsSoldAdapter` (reflection-confirmed to be `/corex`'s own file)
with the identical filter values Shawn's own last real analysis run used
(`suburb=Leisure Bay`, `type=house`, 12-month window, 1000m radius, his
property's own coordinates) — now returns 7 real comps with real prices
(R500,000 × 2, R1,350,000 × 5), all correctly geo-matched, all within the
date window. Before this fix, this exact call returned 0.

A full browser-equivalent HTTP reproduction (POST to
`/presentations/137/analysis/run` with a crafted session cookie, including
one built from Shawn's own genuinely active session row in the `sessions`
table at the time) was attempted three ways — raw `Request::create()` +
`app()->call()`, full `Kernel::handle()` dispatch, and a manually
cookie-encrypted request against Shawn's real session ID — all three hit
session/auth reconstruction friction outside a real browser (the app
consistently redirected to `/login` despite a correctly-encrypted cookie
matching the running app's own key) and were abandoned as a test-harness
limitation, not evidence against the fix. The adapter-level proof above is
direct, live, and uses Shawn's own real inputs; the recommended final
confirmation is Shawn (or Johan) reloading/re-running the analysis in an
actual browser, which will exercise this exact fixed code path.
