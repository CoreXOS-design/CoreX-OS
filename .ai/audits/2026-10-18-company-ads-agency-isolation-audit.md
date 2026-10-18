# Company Ads — agency isolation audit (2026-10-18)

Requested by Johan: "run a full audit check on all that was built, especially looking at the agencies and making sure no
agency can see other agencies' templates or stuff or anything." Scope: everything Company Ads added — E-1 (list, Ad
Builder company mode, uploads, library, download), E-2 (AI pop-up), E-3 (post now / schedule), the Agency Phone block.
Spec: `.ai/specs/company-ads.md` (§14e records the fix).

## Method
1. Read every read and write path of the new code against the tenancy model (`BelongsToAgency` / `AgencyScope`,
   `.ai/specs/multi-tenancy.md`).
2. New attack test `tests/Feature/Tools/CompanyAdsAgencyIsolationTest.php`: agency B — and a CoreX owner account
   working in agency A — goes through the REAL routes after agency A's records (Meta faked with an empty queue, so any
   call reaching Meta would throw). 6 tests, 56 assertions.
3. Read-only data check on QA2: does any stored record point across agencies?

## Finding (fixed)
**F1 — An owner account that has not used the agency switcher saw every agency's company ads.** `AgencyScope` deliberately
does not limit an owner-role user until they switch into an agency (platform design, see AgencyScope). Owners WITH a home
agency pass `agency.required` without switching, so on the Company ads list they would have seen all agencies' ads
(drawn with their own agency's brand), and could open / edit / copy / archive / post another agency's ad by id; a copy
made that way could land in the wrong agency. Proven: the owner test fails on the code before the fix
("ALPHA ... BRAVO-PRIVATE-AD" visible) and passes after.
On QA2 the two owner accounts have no home agency, so `agency.required` makes them pick one (which scopes them) — no
exposure there; live has an owner with a home agency, so the gap was real for live once promoted.
**Fix:** every company-ads query is now pinned to the user's current agency (`effectiveAgencyId`) and every route-bound
company ad / AI request is re-checked against it (404 otherwise): `CompanyAdsController` (list, "has any", builder,
update, duplicate, archive, restore, AI save; store / duplicate / upload write the agency explicitly),
`CompanyAdBuilderService::saveOption` (idempotent lookup), `ScheduledPostService::companyAdFor` and the reschedule check.

## Verified clean
- **List / search / thumbnails / downloads:** only the current agency's ads; the page carries only its own rows' layouts
  and its own brand (name, logo, website, phone). Search cannot reach another agency's ads.
- **By-id access:** builder, update, duplicate, archive, restore of another agency's ad → 404; nothing changes.
- **Pictures:** an ad can only show a library picture or a picture in its OWN agency's folder (another agency's upload is
  blanked on save); uploads are filed under `company-ads/{agency}/` with that agency on the row; uuids, no directory
  listing (nginx autoindex off). Pictures are reachable by their exact address (needed for Facebook/Instagram to fetch
  them) but cannot be found or listed.
- **AI:** another agency's uploaded picture is refused before anything is reserved or sent; "copy the look" files are
  private and per agency; another agency's AI request cannot be saved (404); the prompt carries only the requesting
  agency's colours/logo and never any agency's name; the monthly allowance is counted per agency.
- **Posting:** another agency's ad cannot be posted now or scheduled; a picture from another agency's folder is refused;
  posts publish only from the poster's own connected Page; the publisher re-checks that the ad and the account belong to
  the post's agency; failure notifications are never sent across agencies (existing listener check).
- **Scheduled Posts:** another agency's company-ad posts never appear for an admin of a different agency; cancel / edit /
  reschedule by id → 404.
- **Agency Phone:** each agency's ads show only its own number (listing ads: the listing's agency; company ads and
  previews: the agency being worked in).
- **QA2 data:** 0 cross-agency records in every check (company ads vs creators, pictures vs folders, ads vs pictures,
  AI requests vs pictures and saved ads, posts vs ads, posts with both/neither subject). QA2 currently holds 0 company
  ads, 1 uploaded picture, 1 company-ad AI request, 0 company-ad posts.

## Reported — FIXED 2026-10-18 on Johan's go ("yes do that"), test `OwnerAgencyLockTest`
R1 and R2 below are fixed: the Template Manager list (+ its "has any" check) and Scheduled Posts (`visibleTo`, so the list
and Edit / Cancel / Reschedule) are pinned to the agency being worked in; Archive / Restore of another agency's template
is a 404. Ordinary users unchanged. The Ad Builder itself (opening a template by direct link) was not part of this.

## Originally reported (outside what Company Ads built)
- **R1 — the property Template Manager list** (`AdTemplateManagerController::index`, `PropertyAdTemplate::query()` not
  pinned) has the same owner-account behaviour as F1: an owner with a home agency who has not switched sees every
  agency's property templates there.
- **R2 — Scheduled Posts** (`PropertyMarketingPost::scopeVisibleTo`, 'all' scope returns the query unpinned): the same
  owner account sees every agency's scheduled posts (listing posts and, since E-3, company-ad posts) until it switches.
  For ordinary users both screens are correctly limited by AgencyScope. Both are a one-line pin each, like F1.

## Proof
`CompanyAdsAgencyIsolationTest` (6) plus `CompanyAdsTest`, `CompanyAdAiBuilderTest`, `CompanyAdPostingTest`,
`AgencyPhoneAdBlockTest`, `ScheduledPostsTest`, `ScheduledPostsAuditFixesTest`, `AdTemplateManagerTest`,
`AiTemplateBuilderTest`, `AdManagerScopeTest` — 159 passed. Owner test shown failing without the fix and passing with it.
