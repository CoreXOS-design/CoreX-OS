# QA2 audit of everything landed 2026-10-18 (Johan: "run a audit check on everything new and make sure all is good")

Range: `a8aeb928d..60d1ce569` — Company Ads E-1 / E-2 / E-3, Agency Phone block, Company Ads isolation audit, owner agency
lock, Template Manager previews, AI "no icons" rule, alert bell move. Fixes on branch `audit-fixes-2026-10-18`.

## Checks run

- `php -l` on every changed PHP file: 0 failures.
- QA2 database: the 4 new migrations (2026_11_13_100000, _100100, 2026_11_14_100000, 2026_11_15_100000) have run; 0 pending.
- Every new route resolves; `view:cache` compiles every Blade view.
- Seasonal picture library: 85 pictures referenced, 0 missing on disk.
- Multi-agency scan of the diff: no hardcoded agency, no HFC wording (one code comment only).
- Tests: the 13 files covering the day's work: 181 passed after the fixes below.
- Code review (high) over the range: 10 findings, each re-checked against the code and the server before acting.

## Findings — fixed

| # | What the user would see | Fix |
|---|---|---|
| 1 | Esc in the "Post or schedule" window (opened after saving an AI design) also closed the AI pop-up and reloaded the page — two steps lost. | The Post window's "open" flag is cleared after the key press has reached every listener. |
| 2 | A portrait phone photo uploaded to a company ad was stored sideways (GD drops the EXIF tag). | Upload runs `ImageOrientationNormalizer::normalizeInPlace()` first, like property photos. Test: `CompanyAdsTest::test_a_phone_photo_taken_upright_is_saved_upright`. |
| 3 | A Story-size (9:16) company ad could be posted / scheduled to the Instagram feed, which Meta refuses (a scheduled one would fail later, silently at set-up time). | Instagram tick disabled for Story-size ads with "not for Story-size ads"; the server refuses it too (`ScheduledPostService::fitsInstagramFeed`, 0.8–1.92 so the standard 1200x628 passes). Test: `CompanyAdPostingTest::test_a_story_size_company_ad_cannot_go_to_instagram_but_can_go_to_facebook`. |
| 4 | "don’t use icons" typed with a curly apostrophe (phones / Macs) was not treated as "no icons". | Curly apostrophes read as plain ones. Test cases added to `AiTemplateNoIconsTest`. |
| 5 | In the Ad Builder (company mode) the library dropdown showed "New Year" while the pictures shown were the ad's own occasion, and picking New Year did nothing. A new company ad was called "My Template". | The dropdown shows the occasion actually displayed; a new company ad is called "My company ad". |
| 6 | The Ad Builder's company-ad save (store / update) was a JSON endpoint outside `/api/v1`, not in Admin → API (Non-negotiable #7). | Moved to `api.v1.ad-manager.company-ads.store` / `.update`; old names gone. Pinned in `AdManagerApiV1RoutesTest`. |

## Findings — not changed, with reason

- **Large uploads run out of memory** — not on this box: the PHP-FPM pool's `memory_limit` is 1024M; a 40 MP picture needs ~160 MB.
- **"Post now" killed by the 30 s time limit** — on Linux PHP's `max_execution_time` does not count time waiting on the network
  (the Meta calls), and `request_terminate_timeout` is off. No change.
- **Canvas sizes defined in more than one place** (`CompanyAdLayout::CANVAS_SIZES`, `AdLayoutValidator::SIZES`, unused
  `CompanyAd::CANVASES`) — a tidy-up, not a fault; left for a separate change (scope lock).

## OPEN — needs doing properly, not done here

- **Test schema snapshot not re-dumped** for the 4 new migrations (CLAUDE.md §12a). Tests are still correct (the migrations
  replay on top of the snapshot), only slower. A dump from the lane test database is NOT usable: it carried a test-only
  table and had lost reference rows the committed snapshot holds (tried and discarded). It needs a dump from a database
  built by a clean `migrate` — a separate, careful step.
