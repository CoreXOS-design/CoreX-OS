# Company Ads — seasonal / brand ads in Template Manager, with their own AI creator

> Status: **Spec APPROVED by Johan 2026-10-18 ("sounds all good"). Steps E-1 and E-2 BUILT on QA2 2026-10-18 — awaiting Johan's test.** E-3 (post / schedule) not started.
> Drafted 2026-10-18 on QA2. Extends `at456-ai-tools-scheduler-and-spend-safety.md` (§B AI Template Builder,
> §D Post Scheduler) and `ad-manager.md` (§12 render kernel, §13 Ad Builder, §19 Template Manager).
> Pillars: **Agency** (brand, scope, allowance), **Agent** (creator, poster). Property: none (by design — see §2).

---

## 0. Decisions (Johan, 2026-10-18 — do not re-ask)

| # | Decision |
|---|----------|
| C1 | Johan: "at someting new to ad manager, a section where company adds can be created like for exmaple a merry chirtmass or happy easter add". A **Company Ads** section lives **in the Template Manager**, alongside property templates. |
| C2 | "this should form apart of the AI template cerator agency budget per month" — company-ad AI requests use the **same monthly allowance** as the AI Template Builder (`agencies.ai_template_monthly_limit`, default 20). One shared pool, one count per request. |
| C3 | "a new AI pop up dedicated to this where a user can uplaod a image and give a propmt like chirtmass add etc or give it like a image from another facebook post and have it customized for the agency" — a **dedicated "Create company ad with AI" pop-up**. |
| C4 | Pictures come from **the agency's own uploads + a built-in library of licensed seasonal pictures**. No AI picture-drawing service (CoreX's AI can lay out designs but cannot draw pictures; a paid image service was declined). |
| C5 | An uploaded picture: **the user chooses each time** — "Put this picture in my ad" (their own photo, placed in the ad) or "Copy the look of this post" (inspiration only: layout, colour mood, style — never the other company's logo, words, brand names or photos). |
| C6 | A finished company ad can be **downloaded, posted now, or scheduled** to Facebook / Instagram through the existing Scheduled Posts. |
| C7 | A **new permission "Create company ads"**, on by default for admin, office admin and branch manager. Agents only if ticked in Role Manager. |

---

## 1. What it does and why

Agencies post brand messages that are not about a listing — season's greetings, Easter, New Year, Heritage Day,
"we're closed for the holidays", "welcome to our new agent". Today every ad in CoreX is a property template:
it needs a listing photo and listing facts, so these posts are made outside CoreX. Company Ads makes them inside
CoreX, in the agency's own brand, with the AI doing the design work, and lets the agency post or schedule them
like any other ad.

## 2. Pillars

- **Agency** — READ brand (colours, logo, name, website, phone — always the requesting agency's, never HFC's);
  WRITE company ads; scope + monthly allowance.
- **Agent** — WRITE creator / poster; READ the signed-in user's own photo and contact if they put themselves on the ad.
- **Property** — none, deliberately: a company ad is about the agency, not a listing. This is the one thing that
  separates it from a property template and is why it is its own section rather than a flag on templates.

## 3. Where it lives (navigation)

Tools → Ad Manager → **Template Manager** pill. The page gains a two-tab switch at the top:
**Property templates** (today's page, unchanged) · **Company ads** (new). Same URL family:
`tools.ad-manager.templates` (property, default) and `tools.ad-manager.templates.company` (new). The tab is shown
to anyone with `access_ad_manager`; the create/edit/archive/AI buttons only with the new permission (§9).
No sidebar change (the single *Ad Manager* entry stays highlighted, per ad-manager.md §19).

## 4. Data model

**New table `company_ads`** (`BelongsToAgency`, `SoftDeletes`):
`id, agency_id, user_id (creator), name varchar(100), occasion varchar(40) nullable (a key from the library
config, or null = "Other"), canvas varchar(20) (facebook|instagram|story|whatsapp), layout_json json,
caption text nullable (suggested post text), ai_generated bool default false, ai_idea text nullable,
created_at, updated_at, deleted_at`. Index `(agency_id, deleted_at, updated_at)`.

**New table `company_ad_images`** (`BelongsToAgency`, `SoftDeletes`) — the agency's uploaded pictures that are
*placed in* an ad: `id, agency_id, user_id, path, width, height, created_at, updated_at, deleted_at`. Stored on
the public disk under `company-ads/{agency_id}/{uuid}.jpg` (same-origin, so the export path renders them —
ad-manager.md §10e). Kept while any active or archived ad uses them (an ad is never left with a dead picture).
JPG/PNG/WebP ≤ 8 MB, ≤ 8000 px a side, re-encoded to JPEG ≤ 2400 px on upload.

**"Copy the look" uploads are NOT stored here** — they follow AT-456 §B exactly: private disk, purged after 7
days by the existing `ai-templates:purge-reference-images`, never placed on an ad.

**Built-in library** — `config/company-ad-library.php`: occasions (key, label, rough date hint) each with a list
of pictures in `public/images/company-ad-library/{occasion}/`. Starting occasions (neutral, South African
calendar): New Year, Valentine's Day, Easter, Freedom Day, Mother's Day, Youth Day, Father's Day, Women's Day,
Heritage Day, Diwali, Eid, Christmas / Festive season, Back to school, Closed for the holidays, Welcome / new team
member, General celebration / thank you. 4–8 pictures each. **Licensing:** commercial-use, no-attribution stock
only (Unsplash licence, same posture as `.ai/DEMO_DATA.md` "Listing photos"); each file's source URL recorded in
`public/images/company-ad-library/SOURCES.md`. No agency branding, no recognisable private people, no religious
imagery beyond the occasion's common symbols. The library is global and read-only; it is not a setting.

**`ai_template_generations`** gains `kind` varchar(20) default `property_template` (existing rows unchanged) —
`company_ad` for this feature — plus `occasion` nullable, `image_mode` nullable (`place`|`look`|`library`|`none`),
`company_ad_image_id` nullable. The monthly count stays "all success rows for the agency this month" whatever the
kind (decision C2), so the existing counter needs no change other than the AI usage page showing the split.

**`property_marketing_posts`** (for posting, §7): `property_id` becomes nullable; new nullable `company_ad_id`
(FK `company_ads`). Exactly one of the two is set — enforced in the model's save path and by the controllers.
Existing rows all have `property_id`; nothing changes for them.

## 5. Company Ads list (the new tab)

- **Columns:** preview thumbnail · Name · Occasion · Size · Created by (with AI badge when AI-made) · Last updated · Actions.
- **Search:** name, occasion label, creator's name.
- **Sort:** Name, Occasion, Created, Last updated. **Default: Last updated, newest first.** Tie-breaker id.
- **Filters:** Status (Active — default · Archived · All), Occasion (any of the library's + Other), Created
  (Everyone · Only mine · AI-made), Updated-from / Updated-to date range.
- **Pagination:** 15, filters/sort survive paging.
- **Empty states:** none yet (with **New company ad** and **Create with AI** buttons); none match (with **Clear
  filters**); none archived.
- **Scoping:** company ads are **agency-wide brand assets** — everyone in the agency with `access_ad_manager` sees
  and can download the agency's company ads (that is the point of them); nobody sees another agency's
  (`AgencyScope`, at the query layer). There is no own/branch narrowing of the list because the ads belong to the
  agency, not to a person or branch — stated here so it is a decision, not an omission. **Edit / Archive / Restore /
  Post / Schedule** need the new permission (§9); direct-URL access to another agency's ad id is a 404.

**Actions (full CRUD, soft delete only):** **New company ad** (Ad Builder, company mode §6) · **Create with AI** (§8)
· **Edit** · **Duplicate** (the usual way to reuse last year's Christmas ad) · **Download** (PNG/JPEG at the ad's
size, rendered by the kernel) · **Post now / Schedule** (§7) · **Archive** (confirm: "Archive this company ad? It
disappears from the list, but you can restore it from the Archived filter at any time.") · **Restore**.
No hard delete anywhere.

## 6. Ad Builder — company mode

The same Ad Builder and the same render kernel (`public/js/corex-ad-render.js`, ad-manager.md §12) — no second
editor or renderer. Opened as `?mode=company` (with `from=templates`), it:
- hides every listing element from the palette (price, address, title, beds/baths/garages, sizes, image_1–5,
  collage, QR to listing, show day, feature pills, listing facts);
- offers: custom text, badge, ribbon, shapes, gradients, icons, lines, watermark, **Custom Image** (picker =
  "Upload a picture" or "Choose from the library"), agency logo, agency name, agency website/phone, and
  agent photo/name/phone/email (the signed-in user — for "welcome our new agent" style ads);
- previews against the agency's real brand, not a sample listing (nothing listing-shaped exists to fake);
- saves to `company_ads`, not `property_ad_templates`; Back returns to the Company ads tab.
A company ad never appears in the property-template pickers, and a property template never appears here.

## 7. Posting and scheduling (extends AT-456 §D)

From the list (or after saving from the AI pop-up): **Post now** or **Schedule**, choosing Facebook and/or
Instagram, with the ad's caption pre-filled and editable (required, as for property posts).
- The picture is rendered by the kernel and uploaded through the existing `api.v1.ad-manager.scheduled.image`;
  the post is created through the existing store/direct-publish paths with `company_ad_id` instead of
  `property_id`. One row per platform, same time rules (≥ 5 min ahead, ≤ 60 days), same atomic claim, same
  failure notification (`MarketingPostScheduleFailed`, already in the events catalogue — no new event).
- It publishes from the **scheduling user's own connected Page**, exactly as property posts do today.
- **Publish-time checks for a company ad** replace the listing checks: the ad still exists and is not archived,
  the user still has `access_ad_manager` + `ad_manager.schedule_posts` + the new permission, the agency still has
  `ad-manager`; then the existing connection/token/picture checks.
- **Scheduled Posts screen:** the "Property" column shows "Company ad: <name>" for these rows; search also matches
  the company ad's name; a new filter **Type: All · Listings · Company ads**. Scoping: all-scope users see every
  company-ad post in the agency; branch/own-scope users see the company-ad posts they scheduled themselves.
- Posting needs `ad_manager.schedule_posts` as well (unchanged rule for any post).

## 8. "Create company ad with AI" pop-up

Opened from the Company ads tab. Same dialog shell, behaviour and accessibility as the AT-456 §B.10 pop-up
(centred, teleported, pinned header/footer, only the body scrolls, Esc/backdrop/focus-trap, phone width, full-size
viewer with Previous/Next) — reused, not copied.

**Inputs**
1. **Occasion** — dropdown of the library's occasions + "Other" (optional; helps the AI and filters the library).
2. **Idea** — required text, e.g. "Merry Christmas from all of us, closed 24 Dec – 2 Jan".
3. **Picture** — one of:
   - *None* — the AI designs with text, colours, shapes and icons only;
   - *Choose from the library* — thumbnails for the chosen occasion (all occasions if none chosen);
   - *Upload a picture* — then choose **"Put this picture in my ad"** or **"Copy the look of this post"** (C5).
     The "copy the look" choice shows: "We'll copy the layout and style only — never another company's logo,
     words or photos."
4. **Ad size** — Facebook / Instagram / Story / WhatsApp (same four canvases).
5. **Style** — "Let the AI choose" or one of the eight styles (`config/ad-template-styles.php`).
6. **Must include** (optional ticks): Our contact details (website/phone) · Me (my photo and contact).
   The agency logo (or the agency name as text when there is no logo file) is **always** on a company ad — it is
   the agency speaking; not a tick.

**What the AI receives:** the agency's brand block (name, colours, logo yes/no — same source as §B), the idea,
the occasion, the style, the canvas, the ticks, and the picture: a placed/library picture is described by its
aspect ratio and given as an image so the design can work around it; a "copy the look" picture is given as an
image marked *inspiration only*.

**What comes back:** up to **three** designs (same rule as decisions 14/19: only complete designs are shown; zero
= friendly failure, not counted) plus a **suggested caption** per design (≤ 300 characters, plain text, editable).

**Server-side safety (the validator, extended — one validator, a company mode):**
- Company mode refuses every listing element (§6 list) — repaired by removal, logged.
- The AI never supplies a picture address. It may only place one `custom_image` element; the server fills its
  source with the user's placed upload or chosen library picture, and removes the element when the mode is
  *None* or *Copy the look*. A "copy the look" upload therefore can never appear on the ad.
- An `agency_name` or `logo` element is required (added by repair if missing).
- Literal text: existing limits, markup stripped; the agency's name is drawn by the `agency_name` element, never
  typed by the AI as literal text — so the ad is right for every agency. The prompt forbids reproducing any
  other company's name, slogan or words from a "copy the look" picture; the user reviews every design before saving.
- All existing colour-injection, contrast, bounds and font rules apply unchanged.
- Caption: plain text, markup and control characters stripped, capped.

**Allowance and cost:** one `ai_template_generations` row (`kind = company_ad`) per request, counted in the same
monthly pool (C2); the pop-up shows the same "14 of 20 left this month" line. Spend guard (`AiSpendGuard`) first,
ledger under `AiUsageEvent::SOURCE_AD_TEMPLATE` (same feature family, so the R30/R50 safety and the AI usage page
keep working). One model call for the three designs plus at most one targeted retry, same as §B.8. Estimated
R1.0–2.5 per request. No cost shown to agents.

**Save:** the browser sends only the option number + a name (server keeps the options, as §B.8). Saving creates a
`company_ads` row (`ai_generated = true`, `ai_idea`, `occasion`, `caption`). Then the user can Download, Post now
or Schedule straight from the card.

## 9. Permissions

New key in `config/corex-permissions.php`: **`ad_manager.company_ads`** — label "Create company ads", module
`ad_manager`. Covers create, edit, duplicate, archive, restore and AI-create of the agency's company ads, and
posting them (posting also needs `ad_manager.schedule_posts`). Default roles: admin (all), office admin, branch
manager. Not agent, not viewer. Migration grants it to existing office admin / branch manager roles that hold
`access_ad_manager` (idempotent, same pattern as `2026_11_09_100100`). Route middleware + controller checks +
buttons hidden without it. Viewing/downloading the list needs only `access_ad_manager`.

## 10. AI usage page

`/admin/ai-usage` agency page: the "templates made this month" figure shows the split
"Property templates N · Company ads M" (same total, same limit). No other change.

## 11. Settings audit (rule 10a) and multi-agency

- No new agency setting: the allowance is the existing one; the library is global; the permission lives in Role
  Manager. Nothing for the Setup Wizard.
- Second agency: sees only its own company ads, its own colours/logo/name on every design and preview, the same
  neutral library, and nothing of HFC's. A test asserts agency B's prompt and saved ads carry B's brand only.

## 12. Domain events

None new. Nothing cross-pillar reacts to a company ad being made or archived. Posting reuses
`MarketingPostPublished` / `MarketingPostScheduleFailed` (payload gains nullable `company_ad_id`; `property_id`
becomes nullable — listeners checked for null-safety in the same build).

## 13. API endpoints (non-negotiable #7)

Under `/api/v1/ad-manager/company-ads/...`, all named and in the Admin → API catalogue:
`ai.generate`, `ai.{generation}.save`, `images` (upload a placed picture). Posting reuses the existing
`scheduled.image` / `scheduled.store` (gaining `company_ad_id`). The list/archive/restore/duplicate are normal web
routes, like the property template manager.

## 14. Build order (one lane at a time)

- **E-1 — Company ads without AI:** tables, permission, Company ads tab (list, CRUD, filters), Ad Builder company
  mode, uploaded pictures, the seasonal library (pictures sourced and licensed), Download.
- **E-2 — AI pop-up:** validator company mode, prompt, pop-up, shared allowance, AI usage split.
- **E-3 — Post now / Schedule:** posts table change, publisher checks, Scheduled Posts type column/filter.

## 15. Acceptance

- [ ] Template Manager shows Property templates · Company ads; property templates are unchanged.
- [ ] A branch manager makes a Christmas ad in the Ad Builder with a library picture, saves, downloads it as a picture.
- [ ] Create with AI: "Merry Christmas, closed 24 Dec–2 Jan" + a library picture → up to three designs in the
      agency's brand with the picture in each, a caption each; saving one adds it to the list with the AI badge.
- [ ] "Put this picture in my ad" places the user's photo; "Copy the look" never shows the uploaded picture on any design.
- [ ] No company ad design ever contains a listing element; every one shows the agency logo or name.
- [ ] A company-ad request uses one of the same 20 a month; the 21st request across both kinds is refused with the reset date and no AI call.
- [ ] Post now / Schedule publishes a company ad to Facebook/Instagram; it shows on Scheduled Posts as "Company ad: …"; a failure notifies as today.
- [ ] An agent without the permission sees and downloads company ads but has no create/edit/archive/post buttons; a forged request is 403; another agency's ad id is 404.
- [ ] Archive and Restore work; nothing is hard-deleted.
- [ ] Second agency sees only its own ads and brand.
- [ ] Tests (recorded AI answers, `scripts/lane-test.sh`): `tests/Feature/Tools/CompanyAdsTest.php` (E-1),
      `CompanyAdAiBuilderTest.php` (E-2), `CompanyAdPostingTest.php` (E-3); existing AT-456 / Template Manager /
      Scheduled Posts test files stay green.
- [ ] Johan tests on QA2.

## 16. Files (indicative)

New: `app/Models/CompanyAd.php`, `app/Models/CompanyAdImage.php`, `app/Http/Controllers/Tools/CompanyAdsController.php`,
`app/Services/AdTemplates/CompanyAdBuilderService.php`, `config/company-ad-library.php`,
`public/images/company-ad-library/**`, `resources/views/tools/ad-manager/company-ads.blade.php`,
`resources/views/tools/ad-manager/_company-ad-ai-modal.blade.php`, migrations, tests above.
Modified: `AdLayoutValidator` (company mode), `AdTemplateManagerController`/`templates.blade.php` (tab switch),
Ad Builder view + `corex-ad-render.js` palette gating (company mode), `config/corex-permissions.php`,
`ScheduledPostsController`, `ScheduledPostPublisher`, `PropertyMarketingPost`, `scheduled.blade.php`,
`AiUsageController` + `admin/ai-usage/agency.blade.php`, `routes/web.php` (both the page routes and the
`/api/v1` group, where the existing AT-456 endpoints live).

## 14a. E-1 as built (2026-10-18, branch `company-ads-e1-2026-10-18`)

**Deploy:** run `php artisan migrate` (two migrations: `2026_11_13_100000_company_ads_e1_tables` creates `company_ads`
and `company_ad_images`; `2026_11_13_100100_company_ads_grant_permission` grants the new key to existing branch
manager / admin / office admin roles that hold `access_ad_manager`). Then view/route/config clear + fpm reload.
**No `npm run build`** (Blade with inline script/style, PHP, config, and static pictures under `public/images`).
Schema snapshot not re-dumped (same batch-step posture as AT-456 §B.10).

**What exists**
- **Tabs.** Template Manager shows *Property templates · Company ads* (`_template-tabs.blade.php`). The property tab
  is unchanged apart from the tab bar.
- **Company ads list** (`tools.ad-manager.templates.company`, `CompanyAdsController@index`): preview thumbnail drawn
  by the render kernel, name (+ AI badge), occasion, size, creator, created, last updated. Search, sort, filters,
  pagination and empty states exactly as §5. Occasion sort follows the library's calendar order.
- **Actions:** New company ad, Edit, Duplicate (opens the copy in the builder; the copy belongs to whoever made it),
  Download (PNG at real size, drawn off-screen by the kernel + html2canvas — same capture path as the builder),
  Archive / Restore (soft only). Everyone with `access_ad_manager` sees the list and Download; the change actions
  need `ad_manager.company_ads` (route middleware + controller check).
- **Ad Builder company mode** (`tools.ad-manager.company-ads.builder[.edit]`): the same `ad-builder.blade.php`, switched
  by `$companyMode`. Palette = `CompanyAdLayout::ALLOWED_FIELDS` only (custom image, agent name/email/phone/
  designation/image, logo, agency logo, agency name, website, watermark, custom text, badge, line, shape, colour
  block, gradient, ribbon). Sizes = the four ad sizes (no custom size). No property-type bar, no "Use on property" /
  "Use for marketing". An **Occasion** picker in the toolbar; **Download** button; the Custom Image panel offers
  "Upload a picture" and "Or choose from the library" (thumbnails, filtered by occasion). Previews use the
  requesting agency's own brand and the signed-in user as the agent (`CompanyAdLayout::brandData`).
- **Server rules on every save** (`CompanyAdLayout::sanitize`): anything not in the allowed list is dropped (listing
  elements, QR, video); no `variants`; canvas forced to one of the four sizes; a Custom Image may only show a library
  picture or a picture THIS agency uploaded (`/storage/company-ads/{agency}/…` that exists on the public disk) —
  anything else (another agency's upload, an outside address, a path with `..`) is blanked.
- **Uploads** (`api.v1.ad-manager.company-ads.images`, throttle 30/min): JPG/PNG/WebP ≤ 8 MB, ≤ 8000 px a side and
  ≤ 40 MP; decoded and re-encoded server-side to JPEG ≤ 2400 px (transparency flattened onto white);
  `company_ad_images` row per upload; never purged.
- **Library:** 16 occasions, 85 pictures (4–11 each) in `public/images/company-ad-library/`, listed in
  `config/company-ad-library.php`. All from Wikimedia Commons, marked public domain or CC0 there; each file's source
  page and licence in `SOURCES.md` beside them. Picked by hand from contact sheets: no recognisable people, no
  brand names, no house numbers. Re-encoded to JPEG ≤ 1600 px (about 20 MB in total).

**Decisions made while building (beyond the text above)**
- **Agency phone:** §6 said "agency website/phone". The render kernel has no agency-phone element (only `website`),
  and adding one would change every property template's palette too — outside E-1's scope. A company ad shows a
  number through the agent phone element or as custom text. Raise with Johan if an agency phone element is wanted.
- **Custom video is not offered** on company ads (the spec lists pictures only; a scheduled post is a still picture).
- **Caption** column exists but is not edited in E-1 (it is filled by the AI in E-2 and used for posting in E-3).

**Proof:** `tests/Feature/Tools/CompanyAdsTest.php` (via `scripts/lane-test.sh`). `AdTemplateManagerTest` re-run for
the property tab.
- [ ] Johan tests on QA2.

## 14b. E-2 as built (2026-10-18, branch `company-ads-e2-2026-10-18`)

**Deploy:** `php artisan migrate` (one migration: `2026_11_14_100000_company_ads_e2_generation_columns` adds `kind`
(default `property_template`, so every existing row is unchanged), `occasion`, `image_mode`, `company_ad_image_id`,
`library_picture` and `saved_company_ad_ids` to `ai_template_generations`). Then view/route/config clear + fpm reload.
**No `npm run build`.** Schema snapshot not re-dumped (same posture as E-1).

**What exists**
- **Create with AI** button on the Company ads tab header (and in the empty state) for users with `ad_manager.company_ads`
  → the pop-up `_company-ad-ai-modal.blade.php`. Inputs exactly as §8: occasion (+ Other), idea, picture (none / library
  thumbnails filtered by occasion / upload → "Put this picture in my ad" or "Copy the look of this post" with the
  "we'll copy the layout and style only…" line), ad size, style, ticks *Our contact details (website)* and *Me*. The
  contact tick is disabled with a note when the agency has no website on file. Each design card: preview, View full
  size, style, name, editable suggested post text (≤ 300), Save → Download + Open in Ad Builder.
- **Reused, not copied:** the dialog styles, the full-size viewer and the open/close/focus/viewer behaviour moved out of
  the property pop-up into `_ai-dialog-shell.blade.php` (`corexAiDialogShell()`, included `@once`) and
  `_ai-dialog-viewer.blade.php`; both pop-ups use them. The property pop-up's rendered markup is unchanged.
- **Service** `CompanyAdBuilderService`: input checks first (an uploaded picture must be THIS agency's
  `company_ad_images` row with its file present; a library picture must be a real library file), then `AiSpendGuard`,
  then the SAME allowance lock and pending-row reservation as property templates (`AiTemplateBuilderService::
  reserveAllowance`, now shared). One model call + at most one targeted retry; only complete designs are shown; zero =
  friendly failure, not counted. Ledger source `ad_template`. "Copy the look" files go through the same private storage
  and 7-day purge as property reference images.
- **Validator company mode** (`AdLayoutValidator::validateAndRepair($o, $size, MODE_COMPANY)`): allowed types =
  `CompanyAdLayout::ALLOWED_FIELDS`; a listing element is removed and logged; a second picture box is removed; no
  property photo needed; when no agency name / logo is on the design an `agency_name` on its own dark pill is added
  bottom-left (strict mode reports it). A picture address from the model is never kept.
- **After the validator, per design:** the server fills the one `custom_image` with the chosen picture; for *None* and
  *Copy the look* every picture box is removed first, so the uploaded post can never appear on an ad. A design without
  the chosen picture, or without what was ticked, is retried once and then dropped. The model's own words that spell
  the agency's name (custom text / badge / ribbon) are removed and a watermark's typed words cleared (it then shows the
  agency's own name). Agent elements only when *Me* is ticked. The caption is plain text (markup and control characters
  stripped, line breaks kept), capped at 300.
- **Save** (`api.v1.ad-manager.company-ads.ai.save`): the browser sends option number, name and the edited caption; the
  stored layout goes through `CompanyAdLayout::sanitize` again and becomes a `company_ads` row (`ai_generated`, `ai_idea`,
  `occasion`, `caption`). Idempotent per design. Only the user who asked may save; a property-template request can never
  be saved as a company ad (404) and a company-ad request can never be saved as a property template.
- **Endpoints** (named, in the Admin → API catalogue): `api.v1.ad-manager.company-ads.ai.generate` (throttle 8/min) and
  `api.v1.ad-manager.company-ads.ai.save`, both behind `access_ad_manager` + `ad_manager.company_ads` + agency + feature flag.
- **AI usage page** (`/admin/ai-usage/{agency}`): "N of 20 used this month (Property templates X · Company ads Y; resets …)".

**Decisions made while building**
- "Our contact details (website/phone)" checks for the **website** element only — the ad kernel has no agency-phone
  element (same gap as E-1 §14a). The person's own phone comes with the *Me* tick.
- Unticked *Me* removes agent elements (a company ad is the agency speaking, not whoever pressed the button) — the same
  "extras only when ticked" rule as the property creator (decision 20).
- `library_picture` and `saved_company_ad_ids` columns were added beyond §4's list: the first records which library
  picture a request used, the second makes saving idempotent without mixing company-ad ids into `saved_template_ids`.
- Download from a saved design card uses the list page's own capture path (one download implementation).

**Proof:** `tests/Feature/Tools/CompanyAdAiBuilderTest.php` (15 tests, recorded AI answers). Re-run green: all seven
AT-456 AI template test files, `AdBuildingBlocksKernelTest`, `CompanyAdsTest`, `AdTemplateManagerTest`,
`AiTemplateUsageAdminTest` (217 tests). Two of those (`AiTemplateExtrasOnlyWhenTicked`, `AiTemplateMultiTypeAndMustInclude`)
now read the shared shell file next to the pop-up file — their assertions are unchanged.
- [ ] Johan tests on QA2.

## 17. Open items for Johan

None blocking. Approve the spec (or say what to change) and E-1 starts.
