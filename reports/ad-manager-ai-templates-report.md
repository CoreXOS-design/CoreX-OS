# CoreX Ad Manager & AI Template Creation — Feature Report

**Purpose:** source material for agency marketing. Every feature below was checked against the actual code (screens, routes, controllers, models, jobs, services), not just the specs.
**Audit date:** 1 November 2026. Read-only — no code was changed.

## How to read the release status

| Status | Meaning | Branch tip |
|---|---|---|
| **LIVE** | On the production system (corexos.co.za) today | `Prod` cbce98cc9 (30 Oct 2026) |
| **STAGING** | Finished and on Staging, not yet promoted to live | `Staging` b4539b3b8 (30 Oct 2026) |
| **QA1 ONLY** | Built, waiting for Johan's testing on QA1 | `QA1` e7a0b5919 (1 Nov 2026) |

- There is **no `main` branch** in the repository. The live server tracks `Prod`, so **LIVE = Prod**.
- The branches stack cleanly: everything on Prod is on Staging, and everything on Staging is on QA1.
- For everything in this report, **Prod and Staging have identical code** (only spec documents differ). So there are **no STAGING-only items** — every feature is either LIVE or QA1 ONLY.
- Status was decided by **whether the code is actually present** on each branch, not by commit messages or spec labels.
- **Marketing may only advertise items marked LIVE.**
- Ad Manager can be switched on or off per agency (feature switch), and every action has its own permission in Role Manager. "LIVE" means the feature exists on the live system; whether a particular user sees it depends on those switches.

**The headline for marketing:** Ad Manager is a mature, live image-ad and social-scheduling tool with genuine AI design. There is **no listing video of any kind**, **Facebook is the only social platform** that can be connected, and the Video Ad Studio on QA1 is CoreX's own tool for advertising CoreX — **not** an agency feature.

---

## 1. Ad Manager — menu and screens

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Ad Manager menu | One place for listing ads, the template library, the Article Library and Scheduled Posts, shown as four buttons across the top. Template Manager has two tabs: Property templates and Company ads. A guided tour is included. | Agent, principal, admin | Tools > Ad Manager | LIVE | `layouts/corex-sidebar.blade.php:2170-2183`, `tools/ad-manager/_switcher.blade.php` |
| Ad Manager feature switch | The agency can switch the whole Ad Manager on or off. It depends on the agency's Marketing switch. | Principal/admin | Settings > Features, Setup Wizard | LIVE | `config/corex-features.php:549-557` |

What an agent or principal can create: listing ads (images and a printable brochure), their own reusable ad templates (by hand or with AI), company ads for occasions (by hand or with AI), Facebook posts (now or scheduled), and profile articles from the Article Library.

---

## 2. Listing ads

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Ad Manager — bulk listing ads | A three-step page: **Properties → Template → Ads**. Tick up to **50 listings**, pick one design, and get a finished ad picture plus an AI-written description for every listing in one run. Listings are grouped by agent, with "select all" per agent. Sold, let, off-market and other agencies' stock are left out automatically. | Agent, principal | Tools > Ad Manager | LIVE | `Tools/AdManagerController.php:81-111, 150-173, 297-428`; `tools/ad-manager.blade.php` |
| Ad sizes | Facebook 1200×628, Instagram 1080×1080, Story 1080×1920, WhatsApp 900×900. The single-listing page also offers a **custom size** (200–4000 px). | Agent | Both ad pages | LIVE | `AdManagerController.php:81-89`; `corex/properties/ad.blade.php:423-460` |
| 14 ready-made designs | Printable Brochure, Power, Luxe, Split, Just Listed, Open House, Editorial, Feature Grid, Price Spotlight, Coming Soon, Sold/Under Offer, For Rent, Agent Spotlight, Showcase — plus the agency's own templates. | Agent | Step 2 "Choose a design" | LIVE | `AdManagerController.php:92-111`; `corex/properties/_ad-templates.blade.php` |
| Download and copy | Each ad downloads as a PNG picture. The AI description has a "Copy" button (it can be copied but not edited on this page). An "Include emojis" tick is available. | Agent | Step 3 "Download and copy" | LIVE | `tools/ad-manager.blade.php:321, 349, 730-752` |
| Printable Brochure | A true A4 PDF data sheet per listing (no AI text). | Agent | First design in the list; also the listing's brochure link | LIVE | `AdManagerController.php:450-482`; `PropertyController.php:3551` |
| "Ads made" badge | Each listing card shows how many ads have been made for it and when. | Agent | Step 1 | LIVE | `AdManagerController.php:418-428` |
| Single-listing "Ad" button | Builds an ad for one listing. Lets the agent **choose which photo goes in each picture slot**, and on co-listed properties choose **listing agent, co-agent or both**. Downloads PNG or brochure PDF, or sends the image to the listing's Marketing page. No AI text on this page. | Agent | Properties list, listing page or Marketing page > "Ad" | LIVE | `PropertyController.php:3491-3545`; `ad.blade.php:411-418, 540-560, 1032-1040` |
| Per-property-type templates | A custom template can have a different version for each property type (house, land, etc.); each listing automatically gets the right version. | Agent | Ad Builder / bulk run | LIVE | `AdManagerController.php:354-368` |
| Post or schedule from the results | Each finished ad can be posted to Facebook now or scheduled (see section 6). | Agent with posting permission | Step 3 | LIVE | `tools/ad-manager.blade.php` |

Pictures come from the listing's own photos. Text comes from the listing's facts: title, price (or POA), suburb, features, agent card, and the agency's logo, name and website.

---

## 3. AI-generated ad copy and AI-designed templates

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| "Generate with Ellie AI" ad copy | Writes a Facebook ad (120–220 words) or an Instagram caption (80–120 words plus 10–15 hashtags) and a headline. Uses **only the listing's own captured facts** and is told never to invent amenities, views or schools. Asks about emojis first. Adds auction urgency for auction listings. Strips any reference numbers or links the AI writes and adds the listing's own link. **The agent edits the copy before publishing.** | Agent | Properties > listing > Marketing > "Generate with Ellie AI" | LIVE | `Services/MarketingCopyService.php:46-189`; `PropertyMarketingController.php:61-80`; `marketing/hub.blade.php:247-270` |
| AI descriptions in bulk | The same AI writes a description for every listing in a bulk Ad Manager run. It is always written in Facebook style, and is read-only on that page. | Agent | Tools > Ad Manager, step 3 | LIVE | `AdManagerController.php:381` |
| "Create with AI" — AI-designed property templates | The agent describes the look they want and the AI **designs a reusable ad template**. Inputs: the idea (up to 1,000 characters), the ad size, up to 5 of the agency's property types (each gets its own version), must-include ticks (big price, show-day date, agent photo and contact, agency logo, key features, QR code to the listing, photo collage, corner ribbon with the agent's own words), one of **8 styles** or "let the AI choose", "match our existing templates", and an optional reference picture. The AI automatically uses the **agency's own brand colours and logo**. One design per click; it opens in the Ad Builder for full editing and is saved as an "AI-made" template. | Agent, principal | Tools > Ad Manager > Template Manager > Create with AI | LIVE | `Services/.../AiTemplateBuilderService.php:56-57, 202, 793, 849, 1073-1102`; `Tools/AdTemplateManagerController.php:150-266`; `config/ad-template-styles.php` |
| PPRA line added automatically | Every AI design gets a "Registered with the PPRA" strip at the bottom. | Automatic | — | LIVE | `AdPpraFooter` |
| AI ad checker | Before anyone sees an AI design, CoreX checks it and **repairs** it: pulls overlapping boxes apart, moves text fully onto shapes, shrinks or grows the corner ribbon to fit the words, and accepts ads built around the agent's photo. Text is tested against the longest realistic listing details. Anything it cannot fix is retried once. | Automatic | — | LIVE | `Services/AdTemplates/AdLayoutValidator.php` (commit fa48bbded, 30 Oct 2026) |
| "Create company ad with AI" | Give an occasion, an idea, a picture choice, a size, a style and must-include ticks; get **one design plus a suggested caption** (editable, max 300 characters). Picture choices: none, the picture library, "put this picture in my ad", or "copy the look of this post" (that picture is used for inspiration only and never appears in the ad). The agency logo or name is always on the ad. | Principal, admin | Tools > Ad Manager > Template Manager > Company ads > Create with AI | LIVE | `CompanyAdBuilderService.php:49, 141, 530`; `Tools/CompanyAdsController.php:144-238` |
| Monthly AI design allowance | Each agency gets **20 AI designs a month** by default, shared between templates and company ads. The panel shows "X of Y left, resets on …". | Automatic | Create with AI panel | LIVE | `AiTemplateBuilderService::DEFAULT_LIMIT` |

The AI is Anthropic's Claude: the faster model for ad copy, and the higher-quality model for designs.

---

## 4. Template library and branding

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Template Manager — Property templates | The agency's own library of ad templates: search by name or creator; filter by status (active/archived), by who made it (everyone / mine / AI-made) and date range; sort by name, creator, created or updated; 15 per page. Every row shows a live preview in the **agency's own branding**. Archive and Restore (nothing is permanently deleted). | Agent, principal | Tools > Ad Manager > Template Manager | LIVE | `Tools/AdTemplateManagerController.php:47-145, 327-357` |
| Ad Builder | Drag-and-drop editor for templates: photos, text, price, agent block, logo, shapes, icons, QR code, uploaded image (or a video clip, which appears as a still frame in the downloaded ad), grouping, agent-photo shapes and per-property-type versions. | Agent, principal | Template Manager > New/Edit, or "Edit" from the Ad page | LIVE | `CoreX/PropertyAdTemplateController.php`; `/corex/ad-templates/builder` |
| "Remove background" on agent photos | The agent's photo is cut out automatically so it sits cleanly on the design. The agency can switch this on or off. | Agent; principal switches | Ad Builder; Settings > Properties tab | LIVE (whether the cut-out service is running on the live server was not checked) | `config/services.php:208-228`; `corex/settings.blade.php:2670-2690` |
| Template Manager — Company ads | A library of non-listing brand ads: Christmas, Easter, Heritage Day, "closed for the holidays", a new team member, thank-yous and so on. Build in the Ad Builder or with AI; **Duplicate** (e.g. reuse last year's Christmas ad), Archive, Restore, Download, Post/Schedule. Search, filters (status, occasion, who made it, date range), sort (including calendar order of occasions). | View/download: anyone with Ad Manager. Create/edit: principal, admin | Template Manager > Company ads | LIVE | `Tools/CompanyAdsController.php:45-138, 278-433` |
| Licensed occasion picture library | Ready pictures for **16 occasions** to use in company ads, plus the agency's own uploads. | Principal, admin | Company ad builder | LIVE | `config/company-ad-library.php`; `public/images/company-ad-library/` |
| Branding | Ads use the agency's **logo, its four brand colours, name, website and phone** from Company Settings. Fonts come from the chosen design style and are not an agency setting. | Automatic | Company Settings | LIVE | `AiTemplateBuilderService.php:1073-1102` |
| Reuse | Every template the agency makes is available to everyone in the agency, in both ad pages. Company ads can be duplicated; **property templates cannot be duplicated**. | Everyone in the agency | — | LIVE | `AdTemplateManagerController.php`; routes `web.php:1765-1767` |

The 14 built-in designs are the same for every agency and do not appear in the Template Manager list.

---

## 5. Article Library and AI briefs

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Article Library (CoreX side) | CoreX keeps one shared list of South African property news feeds (pre-loaded: BusinessTech Property, Daily Investor Property, IOL, Property24 Advice, MyBroadband Property). On a schedule (off, nightly or every 3 days) or "Run now", one AI call keeps the headlines useful to SA estate agents, and a second AI call writes a **150–250 word brief in its own words** for each, with credit and a link to the original. The cost is carried by CoreX, not the agency. | CoreX platform owner | System Developer > Article Library | LIVE | `Admin/ArticleLibraryController.php`; `Services/Articles/ArticleLibraryService.php`; `routes/console.php:633-636` |
| Article Library (agency side) | A shared shelf of ready briefs: search (title, source, tags), sort, filters (source, tag, date range, "not yet used by me"), 15 per page. Each card has **Read brief**, **Read original** and **Use this**. | Agent, principal | Tools > Ad Manager > Article Library | LIVE | `Tools/ArticleLibraryController.php:43-115`; `tools/ad-manager/articles.blade.php` |
| "Use this" → my profile article | Copies the brief into the agent's **own unpublished article** in My Portal (title, summary, brief, tags, plus a credit line and a link to the original). The agent edits it and clicks Publish themselves. Published articles appear on the agent's **public profile page** and on the **agency website** through its articles feed. | Agent | Article Library > Use this; My Portal > Profile > Articles | LIVE | `Tools/ArticleLibraryController.php:122-158`; `Agent/AgentArticleController.php`; `routes/api.php:360-362` |

**The rule that briefs never carry the source wording — what the code really enforces:**

- **Enforced by code, for the brief text itself:** every brief is compared word by word with the feed text by CoreX's own check (not by AI). It fails if more than **15%** of its six-word phrases also appear in the source, or if it repeats **12 or more words in a row**. A failing brief is sent back to the AI once, with the copied words quoted. If it fails again it is **never stored**. Briefs that contain web code, or are outside 90–320 words, are also rejected. A clean-up command hides older briefs that fail the check. (`ArticleLibraryService.php:474-508, 604-613, 634-711`; `RescoreArticleLibrary.php:31-54`)
- **Only asked of the AI, not checked:** the instruction "original wording, do not copy sentences or distinctive phrases" (`ArticleLibraryService.php:551`).
- **Not enforced:** the **headline and one-line summary are never checked**, and if the AI returns no headline the **publisher's own headline is used word for word** (`ArticleLibraryService.php:513-516, 525`). An owner can un-hide a brief without it being re-checked. After "Use this", agents can edit their copy freely. Hiding a brief does not withdraw copies agents already made.
- The article web page itself is never downloaded — only what the feed provides.

Safe marketing claim: **"Every brief is written in original words, and CoreX checks the brief text against the source before it is published to the library."** Do not claim that headlines are checked.

---

## 6. Social posting

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Connect your Facebook Page | **Each agent connects their own Facebook Page** (not one account per agency). If they manage several Pages, they pick one. Disconnect is kept on record. | Agent | My Portal > Tools > Social Media, or a listing's Marketing page | LIVE | `PropertyMarketingController.php:191-338`; `Services/MetaOAuthService.php` |
| Listing Marketing page | On a listing: AI ad copy (section 3), choose a picture, **Publish now** to the agent's Page, see post history with status, failure reason and stats (impressions, reach, likes, comments, shares, clicks), and refresh the stats. Publishing is blocked if the listing is not on the market, is a draft, is another agency's stock, or is not marketing-ready (mandate/FICA). | Agent | Properties > listing > Marketing | LIVE | `PropertyMarketingController.php:32-186, 363-374`; `marketing/hub.blade.php` |
| Post now / Schedule from Ad Manager | Post an ad now or schedule it: at a set date and time, **one a day** from a date, or **"By agent"** (choose weekdays, first time, gap between agents, A–Z or custom order, remembered per user). Up to 60 days ahead. | Agent with posting permission | Ad Manager step 3; Company ads | LIVE | `Tools/ScheduledPostsController.php`; `Services/Marketing/ScheduledPostService.php` |
| Scheduled Posts list | Every scheduled and past post: search (text, listing, company ad, agent), sort (soonest first by default), filters (status, platform, listing or company ad, agent, date range), 15 per page. Edit text or time while waiting, Cancel (kept, never deleted), Reschedule. | Agent, principal | Tools > Ad Manager > Scheduled Posts | LIVE | `ScheduledPostsController.php`; `routes/web.php:1787-1794` |
| Safe automatic publishing | Checked every minute; each post goes out **only once**. Just before posting CoreX re-checks that the listing is still on the market and marketing-ready, the account is still connected and the person still has permission. A failure is recorded with a plain reason and an in-app alert. Pictures are cleaned up 30 days after posting. | Automatic | — | LIVE | `Services/Marketing/ScheduledPostPublisher.php:118-345`; `Console/Commands/PublishDueMarketingPosts.php` |
| Post stats | Facebook stats are fetched nightly and on request. They are shown on the listing's Marketing page (not on Scheduled Posts). | Agent | Listing > Marketing | LIVE | `Jobs/SyncMarketingInsightsJob.php`; `MetaPublishingService.php:221-286` |
| CoreX's own Facebook Page and video posting | The CoreX owner can connect CoreX's own Page (belonging to no agency) and post videos to it. | CoreX owner only | Marketing > Facebook page | QA1 ONLY | `MetaOAuthService.php`, `MetaPublishingService::publishVideoToFacebook` (QA1) |

**Platforms — the facts for marketing:**
- **Facebook Pages: yes** (connect, post now, schedule, stats).
- **Instagram: no.** The posting code exists behind the scenes, but there is **no Instagram connect button anywhere**, so no agent can use it. Do not advertise Instagram posting. Agents can still *download* Instagram-sized ads and post them by hand.
- **LinkedIn, X/Twitter, TikTok: nothing in the code.**
- **Download instead of posting:** every ad can be downloaded as a picture (or brochure PDF) and posted anywhere by hand.
- **Before advertising Facebook posting:** Facebook posting needs CoreX's Meta app, which must be approved by Meta for agents to connect. The spec says Meta's app review is still pending, which means only test users can connect. This cannot be seen in the code and **must be checked on live** before it is advertised.

---

## 7. Video and image creators

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Image ads, brochures and company ads | All image creators (sections 2–4). No creator called a poster, flyer or window card exists. | Agent | Tools > Ad Manager | LIVE | see above |
| "Custom Image / Custom Video" element | An agent can drop a short clip (up to 40 MB) into a template; it plays in the preview, but the **downloaded ad is a still picture**. | Agent | Ad Builder | LIVE | `PropertyAdTemplateController.php:59-80` |
| Training Videos | CoreX's how-to video library on a public page (YouTube links or uploads). **Training, not marketing.** | CoreX owner manages; anyone watches | `/training-videos` | LIVE | `routes/web.php:294-297, 5537-5557` |
| Training library and "Watch how" | How-to videos CoreX films by itself on an invented demo agency, with an AI voice-over and captions; "Guide me" spoken tours. **Training, not marketing.** | Agents | Sidebar > Training library | QA1 ONLY | `Services/TrainingVideos/Auto/*`, `Tts/*` (QA1) |
| Video ads (Ad Studio) | Short promo videos that **sell CoreX itself to estate agencies**, built from captioned shots of real CoreX screens (on demo data), with a voice-over, captions, branded end card, and 9:16, 1:1 and 16:9 formats, rendered on CoreX's own server. Posts to CoreX's Facebook Page. **Not an agency feature, not linked to Ad Manager, and takes no listing or property.** Not usable yet: the ad scripts are unapproved and generation is on hold until Johan chooses the CoreX voice. | CoreX owner only | Marketing > Video ads | QA1 ONLY | `Admin/VideoAd*Controller.php`, `Services/AdStudio/*`, `Services/TrainingVideos/Ads/*` (QA1) |

**There is no listing video, Ken Burns slideshow or reel creator on any branch.** Do not advertise video ads for listings.

---

## 8. AI document and template creation elsewhere in CoreX

| Feature (as on screen) | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| Word import into the template builder | The agency imports its own Word document (lease, mandate, offer, etc.); CoreX's **rules** (no AI, no cost) recognise most blanks and link them to CoreX data, including lease wording such as "shall commence on ___". | Principal/admin | Documents > Templates > Import Document | LIVE | `CdsParserService`, `LeasePhraseMatcher`, `LeaseFieldSource` |
| "Suggest links (AI)" | For blanks the rules couldn't place, the AI suggests which CoreX field each one is (tenant name, rent, deposit, etc.). It only sees the words around each blank, **not the whole document**, and can only choose from CoreX's own field list. Suggestions show as orange dots for the user to check before saving. | Template author | Template builder > "Suggest links (AI)" | LIVE | `Services/Docuperfect/CdsAiFieldSuggester.php:39-104`; `TemplateController.php:754-786` |
| Import Document — AI field detection (older import route) | Upload a Word or PDF document and the AI labels every blank (names, rent, deposit, dates, bank details) and finds sections such as "Other Conditions". The user reviews the result. Falls back to rules if the AI fails. **This route sends the whole document text to the AI.** | Principal/admin with template rights | Documents > Templates > Import Document > Parse | LIVE | `DocumentImporterController.php:186-260`; `DocxParserService.php:225-262, 354-385`; `ImporterAiService.php` |
| Presentation AI summary | Writes the executive summary of a seller presentation in several tones. Every number comes from CoreX's own data, not the AI. The agent edits it and accepts it into the presentation. | Agent | Presentations > a presentation > AI summary | LIVE | `Services/Presentations/AiSummaryService.php`; `Presentation/AiSummaryController.php:28-74` |
| Ellie AI ad copy | See section 3. | Agent | Listing > Marketing | LIVE | `MarketingCopyService.php` |

Other AI in CoreX that does **not** create templates or marketing text (listed so marketing can position it): Ellie chat and voice, AI photo suggestions on listings, Market Intelligence write-ups, and handwritten-note reading on rental inspections (off by default). Rental notices, lease renewals and seller-outreach letters use the agency's own templates with **no AI drafting**.

---

## 9. AI spend limits and guards

| Feature | What it does | Who | Where | Status | Evidence |
|---|---|---|---|---|---|
| AI spend safety | Every AI call is costed and recorded. If an agency's AI spend reaches **R30 in 24 hours** CoreX is alerted by email. At **R50 in 24 hours** AI is **paused automatically** for that agency, and users see "AI is paused for your agency — please contact CoreX support." | Automatic; CoreX owner resumes | — | LIVE | `Services/AI/AiSpendGuard.php:63-66`; `Exceptions/AI/AiPausedException.php:21` |
| Coverage | Every paid AI call in the app checks the guard first: Ad Manager designs and copy, company ads, Article Library, Suggest links, document import, presentation summary, Ellie, photo AI and Market Intelligence. No call that skips it was found. | — | — | LIVE | `AnthropicGateway.php:278-279`; per-service checks |
| AI usage page | CoreX's owner sees spend per agency, turns AI back on, and sets an agency's monthly AI-design allowance. **No agency user can see AI spend.** | CoreX platform owner | `/admin/ai-usage` | LIVE | `routes/web.php:533-553`; `AiUsageController.php:255-275` |
| Monthly design allowance | 20 AI designs per agency per month (section 3), plus a limit of 5 failed AI designs per day. | Automatic | — | LIVE | `AiTemplateBuilderService.php:57`; `AiSpendGuard.php:67` |
| Rate limits | AI template design: 8 per minute. Presentation summary: 30 per minute. Ad copy, bulk copy, Suggest links and document import have no per-minute limit — the R50 cut-out is their only cap. | Automatic | — | LIVE | `routes/web.php:637, 1761, 6454, 7312, 7561` |

Safe marketing claim: **"Built-in AI cost protection — no runaway AI bills."** There is **no agency-set AI budget**: the limits are the same for every agency and are set by CoreX.

---

## 10. Settings (everything an agency can configure)

| Setting | Where | In Setup Wizard? | Status |
|---|---|---|---|
| Marketing on/off (shows or hides the listing Marketing button) | Settings | Yes | LIVE |
| Ad Manager on/off (also turns off Article Library and Scheduled Posts) | Settings > Features | Yes (features step) | LIVE |
| DocuPerfect, Presentations, Ellie on/off (turns their AI off with them) | Settings > Features | Yes | LIVE |
| AI background removal for agent photos | Settings > Properties tab | **No** — and not recorded as a deliberate omission (see Issues) | LIVE |
| Branding used on ads: logo, 4 brand colours, name, website, phone | Company Settings | Yes (existing branding steps) | LIVE |
| Per-user "show social accounts" on My Portal | My Portal | n/a (personal) | LIVE |

Set by CoreX, not by the agency: the AI spend limits, the monthly AI-design allowance, AI model choice, the Article Library feeds and schedule, the originality limits, and the Facebook app connection. **Agencies cannot**:
- add their own news feeds
- choose AI tone or voice
- switch ad-copy AI or document AI on or off separately
- set their own AI budget
- change the scheduling time zone

---

## 11. Role Manager permissions and Own / Branch / Agency scoping

| Permission (Role Manager) | Allows | Default roles |
|---|---|---|
| Access Ad Manager | Use the Ad Manager pages, Article Library, bulk ads, AI designs | Admin, Branch manager, Office admin, Agent, Viewer |
| Ad Manager view (scope) | Which listings appear: Own / Branch / Agency | Agent = own, Branch manager = branch, Admin = agency |
| Schedule social posts | Post now, Schedule, see Scheduled Posts | Admin, Branch manager, Office admin, Agent (not Viewer) |
| Company ads | Create/edit/duplicate/archive company ads and their AI | Admin, Branch manager, Office admin (not Agent) |
| Manage others' ad templates | Edit or archive templates made by someone else | Admin, Branch manager, Office admin, **Agent and Viewer** (see Issues) |
| Edit own profile | "Use this" from the Article Library | Most roles |
| Template management (DocuPerfect) | Document import with AI | Admin, Branch manager, Office admin (not Agent) |

How visibility is enforced:
- **Bulk Ad Manager listings** are limited to own, branch or agency in the database query, and each listing is checked again on every preview and generate.
- **Scheduled Posts** are limited to own, branch or agency in the database query. Opening someone else's post by its link shows "not found".
- **Social accounts** belong to the person who connected them.
- **Templates and company ads** are shared agency-wide by design.

Nothing is ever permanently deleted: templates, company ads, articles, scheduled posts and social connections are archived, not deleted.

---

## 12. Mobile app (server side)

- **There is no mobile-app support** for Ad Manager, templates, company ads, Article Library, social posting or scheduling. The server connections these pages use work only from a logged-in web browser.
- The only related app connection is the **agency website's published-articles feed** (agents' published profile articles).
- The only AI app connections are Ellie voice and read-only AI photo suggestions.

---

## 13. Other related items

| Item | What it is | Status |
|---|---|---|
| Marketing permission gate | Publishing a listing ad is blocked until the listing is "marketing-ready" (mandate and FICA in place). This is a compliance safeguard, not a social feature. | LIVE |
| Marketing Suppressions | The POPIA opt-out list of contacts who must not receive marketing messages. Admins can view it and lift an entry. **Not related to social posting.** | LIVE |
| Article auto-tagging for Presentations | A separate older job tags news articles for seller presentations. | LIVE (see Issues — HFC regions hardcoded) |

---

# A. Top 10 selling points (all LIVE)

1. **A week of listing ads in one click.** Pick up to 50 listings and one design and get a finished ad for every one, in Facebook, Instagram, Story or WhatsApp size, each with an AI-written description.
2. **AI that designs your own ad templates.** Describe the look and AI builds a reusable template in your agency's colours and logo, with a version for each property type, ready to edit in a drag-and-drop builder.
3. **AI designs are checked and fixed before you see them.** Overlapping text, cramped ribbons and text spilling off shapes are repaired automatically, and the PPRA line is always added.
4. **14 ready-made designs including a print-ready A4 brochure.** From Just Listed and Open House to Luxe and Price Spotlight, plus a professional PDF brochure per listing.
5. **Brand and occasion ads in minutes.** Christmas, Heritage Day, a new team member: build with a licensed picture library or let AI design it and write the caption, then reuse it next year.
6. **Schedule a month of Facebook posts in one go.** One a day, or rotate through every agent's listings, and CoreX checks each listing is still on the market and compliant before it posts.
7. **Ad copy that never invents facts.** Ellie writes Facebook and Instagram copy only from what is captured on the listing, and you edit before it goes out.
8. **Fresh property news, written for you.** A shared library of SA property news briefs in original words, checked against the source by CoreX, ready to publish on your profile and website in one click.
9. **Turn your own Word documents into smart templates.** Import your agency's lease or mandate; CoreX links the blanks to your data and AI suggests the rest for you to confirm.
10. **No runaway AI bills.** Every AI action is costed and capped automatically, so AI never quietly runs up a bill.

Before advertising points 6 and 7 as "post to Facebook", confirm on live that a normal agent (not a CoreX test user) can connect their Facebook Page. Meta's app approval cannot be seen in the code.

---

# B. In spec, not built (do not market)

| Item | Detail |
|---|---|
| Listing video, Ken Burns slideshow, reels | Not built on any branch. Video clips in templates download as still pictures. |
| Instagram posting | Code exists, but there is no way to connect Instagram from any screen. |
| LinkedIn, X/Twitter, TikTok posting | Nothing in code. |
| Video ads for agencies | The QA1 Video Ad Studio is CoreX-only, advertises CoreX, and is on hold until the CoreX voice is chosen. |
| "Make a social post" from a library article | Specified (phase 2), no code. |
| Choosing which agent appears per listing in bulk ads; typing a different agent name | Specified as a follow-up, not built (bulk always shows the listing agent). |
| Duplicate for property templates | Only company ads can be duplicated. |
| Multiple AI design options per click | The specs say three; code makes one (Johan's 23 Oct 2026 decision to save cost). |
| Agency-set AI budget, AI tone/voice, per-feature AI on/off | Not built (the per-agency budget was deliberately removed). |
| Agencies adding their own news feeds | Not built; one shared CoreX list. |
| Per-agency time zone for scheduling | Not built; one system time zone. |
| Automatic retry of failed posts | Deliberately not built. |
| Screen to fine-tune background removal | The settings exist in the database but there is no screen. |
| Marketing cuts of training videos; phone/laptop mockup look; music; South African voice for video ads | QA1 spec only. |
| Mobile app access to Ad Manager | No server support. |

---

# C. Issues found (not fixed)

| # | Issue | Where | Why it matters |
|---|---|---|---|
| 1 | **Switching Ad Manager off doesn't switch off the listing "Ad" button or the Ad Builder.** They only check property access, not the Ad Manager switch or permission. | `routes/web.php:6336` and `:6637-6643` | An agency or role without Ad Manager can still make ads, upload media (40 MB) and archive templates. |
| 2 | **CoreX owner accounts that haven't picked an agency see every agency's listings and templates** in the Ad Manager and Ad Builder. | `Tools/AdManagerController.php:154, 204, 248, 338`; `CoreX/PropertyController.php:3513` | Multi-agency isolation gap (Template Manager and Company ads were already fixed). Only platform owners are affected. |
| 3 | **Every agency's downloaded ads are named "hfc-ad-…png".** | `resources/views/corex/properties/ad.blade.php:1037` | Breaks the multi-agency rule; the Cape Town agency's files would carry HFC's name. |
| 4 | **The AI checker tests text fit using HFC's name and KZN place names**, not the requesting agency's real name. | `app/Services/AdTemplates/AdLayoutValidator.php:1580-1600` | An agency with a longer name could get a clipped name box; HFC wording in shipped code. |
| 5 | **Agents and Viewers can edit and archive anyone's templates by default.** | `config/corex-permissions.php:1574` (agent), `:1685` (viewer) | Undoes the "only the creator" rule; a read-only Viewer should not archive templates. Business decision needed. |
| 6 | **A 50-listing bulk run makes 50 AI calls in one go, with no per-minute limit.** | `AdManagerController.php:297, 344-391`; `routes/web.php:1761` | A big batch can time out and return nothing; only the R50 cut-out caps the cost. |
| 7 | **Bulk AI descriptions are always Facebook-style and can't be edited**, even for Story or WhatsApp sizes. | `AdManagerController.php:381`; `tools/ad-manager.blade.php:349` | The agent can't adjust text before posting. |
| 8 | **A failed Facebook connect shows the raw error on screen**, which can include CoreX's Meta app secret or access tokens. | `PropertyMarketingController.php:254-256, 299-301` | Secret could be shown to a user and written to logs. |
| 9 | **The agent's Facebook access token travels in a web address**, so it lands in server logs and browser history. | `resources/views/agent/portal.blade.php:2059-2063` | Security. |
| 10 | **The Facebook connect flow has no anti-forgery check.** | `Services/MetaOAuthService.php:23`; `PropertyMarketingController.php:229` | A crafted link could attach someone else's Page to a user's account. |
| 11 | **Instagram can't be connected from any screen**, though the rest of the system offers Instagram as a choice. | `agent/portal.blade.php:1266`; `marketing/hub.blade.php:195` | Feature looks present but can't be used. |
| 12 | **Scheduled posts probably stop after 60 days** with "Your Facebook connection has expired". CoreX gives each connection a made-up 60-day expiry, while Publish now carries on working. | `MetaOAuthService.php:296-297, 354-373`; `ScheduledPostPublisher.php:264-268` | Needs checking on live; agents would have to reconnect every 2 months. |
| 13 | **Nightly stats can get stuck** re-trying the same failing posts, and the Instagram stats request asks for a figure Meta has likely retired. | `Jobs/SyncMarketingInsightsJob.php:25-42`; `MetaPublishingService.php:262-266` | Newer posts' stats may never update. |
| 14 | **The agency "Marketing" switch only hides a button.** The listing Marketing page, AI copy and Publish still work by direct link. | `corex/properties/show.blade.php:475`; `config/corex-features.php:180-187` | Switching Marketing off doesn't really switch it off. |
| 15 | **Two different permission rules for posting.** Publish on a listing needs only property access, not "Schedule social posts". | `routes/web.php:6453-6455` | Removing the posting permission doesn't stop posting from the listing. |
| 16 | **Uploads are saved publicly without checks.** One marketing upload path accepts any file as a public picture with no size limit or clean-up. The Ad Builder upload saves to a shared "agency 0" folder for a user with no agency. | `PropertyMarketingController.php:344-359`; `PropertyAdTemplateController.php:59-80, 72`; `routes/web.php:6640, 6984` | Storage abuse risk; files not tied to an agency. |
| 17 | **Brief headlines and summaries are not originality-checked.** The publisher's headline is used word for word when the AI returns none, and un-hiding a brief skips the check. | `ArticleLibraryService.php:513-516, 525`; `Admin/ArticleLibraryController.php:245-252` | The "never the source wording" rule is enforced for the brief body only. |
| 18 | **The server's "AI off" switch is ignored by several AI features** (Suggest links, Word import, Ellie, voice, photo AI). | `Docuperfect/CdsAiFieldSuggester.php:41-45`; `Docuperfect/ImporterAiService.php:74-82` | Turning AI off at server level doesn't stop all AI spend. |
| 19 | **Some ad-copy spend is not recorded** when the AI's reply can't be read, so it doesn't count toward the R50 cut-out. | `Services/MarketingCopyService.php:144-151`; `ImporterAiService.php:117-120` | Spend guard under-counts. |
| 20 | **The older Word import sends the whole document to the AI twice.** | `Docuperfect/DocxParserService.php:367-383`; `Docuperfect/DocumentImporterController.php:246-248` | Double cost; a filled-in document's personal details go to the AI provider (POPIA), and users aren't told. |
| 21 | **The document importer uses a fixed AI model**, so a change to CoreX's model setting doesn't reach it. | `ImporterAiService.php:91-92` | Maintenance and cost control. |
| 22 | **"Suggest links (AI)" doesn't check the template-management permission.** | `Docuperfect/TemplateController.php:754-756` | Low risk; inconsistent with the sibling action at `:794`. |
| 23 | **Setup Wizard gaps (rule 10a).** "AI background removal for agent photos" is not in the wizard and not on the deliberate-omission list. The AI spend limits and monthly design allowance are also missing from that list. | `corex/settings.blade.php:2670-2690`; `.ai/specs/agency-onboarding-setup.md:266` | Rule 10a requires the omission to be recorded. |
| 24 | **HFC wording in shared code.** The old Ellie service prompt says it works for "Home Finders Coastal … KZN South Coast". The news tagger for Presentations has South Coast suburbs built in. | `services/hf-ai/app.py:75`; `Services/Articles/ArticleScraperService.php:29-39` | A second agency gets HFC framing and no regional matching. |
| 25 | **Raw error text shown to users** on the presentation AI summary and post-stats refresh. | `Presentation/AiSummaryController.php:55-57`; `PropertyMarketingController.php:184` | Confusing errors; may expose internals. |
| 26 | **The feature-switch description oversells.** It promises "tracking listing adverts across channels"; only an ads-made count exists. | `config/corex-features.php:551` | Marketing accuracy. |
| 27 | **QA1: connecting CoreX's own Facebook Page fails** for an owner who has (or ever had) their own Facebook connection: duplicate record error. | QA1 `database/schema/mysql-schema.sql:1790`; QA1 `MetaOAuthService.php:306, 321` | Blocks the QA1 Video Ad Studio's Facebook connect. |
| 28 | **QA1: an agency listing post could publish to CoreX's Facebook Page** once issue 27 is fixed, because the publisher doesn't exclude CoreX's Page. | `Services/Marketing/ScheduledPostPublisher.php:253-259` (same on Staging) | Fix together with 27. |
| 29 | **QA1: the Video Ad Studio advertises corexweb.co.za / info@corexweb.co.za**, not corexos.co.za. A route comment wrongly says nothing is posted. | QA1 `config/ad-studio.php:57-58`; QA1 `routes/web.php:5625` | Business should confirm which website and email the ads should show. |
| 30 | **Stale specs and comments** understate or misstate what is built. ad-manager.md still says "build in flight", and its §15.3 says "not on live" although it is. The company-ads spec and code comments say three AI options. ai-cost-ledger.md says "DRAFT". | `.ai/specs/ad-manager.md`, `.ai/specs/company-ads.md`, `.ai/specs/ai-cost-ledger.md`; `AdTemplateManagerController.php:36, 148` | Anyone writing marketing from the specs would get it wrong. |

**Not verified end to end** (code confirmed only):
- whether the background-removal service runs on the live server;
- whether Meta has approved CoreX's Facebook app for all agents;
- live Facebook token behaviour after 60 days (issue 12).
