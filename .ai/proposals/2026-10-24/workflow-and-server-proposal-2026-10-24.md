# CoreX: way of working, server and storage — proposal for Johan and Andre
24 October 2026. Compiled from six investigations; nothing was changed anywhere to produce it. Where a number is an estimate, it says so.

## 1. Summary
We found five things that need attention before anything else (section 2): a test copy that can still talk to real WhatsApp and Property24, a server with no firewall and two months of unapplied security updates, client documents that can be opened without logging in, and an encryption key whose only backup sits next to the files it protects. Alongside that, we propose a calmer, cheaper way of building: one server-side "conductor" that resets each build lane before every task and hands it a complete task card, a private test database for each lane so nobody waits in a queue, and much shorter standing instructions. The build server is also full of old copies: clearing them would free roughly 80–100 GB of disk and 14 GB of memory, and the public websites that still run here should move to the live server. File storage will stay affordable only if we stop keeping every page image the PDF splitter makes; the code review found that the cheapest speed-up (a setting that lets every page skip re-reading 3,400 route definitions) is simply not switched on. The decisions you are asked for are numbered in section 7.

## 2. Urgent flags (in order)
1. **The "live-testing" copy still points at the real world.** On the build server, `live-testing.corexos.co.za` is set to use the real WhatsApp gateway on the live server (with its key), the real Property24 production service (test mode off), and the real corexos.co.za address in its links. Its database and its outgoing email are local and safe. Not checked: whether it actually sends anything today. It is still used (about 1,150 page views yesterday). Re-confirmed today.
2. **The firewall is off.** About a dozen internal tools (browser code editor, printing, several test sites) listen on every network address. We could not see whether Hetzner's own firewall sits in front.
3. **165 security updates are waiting and the server has needed a restart since 20 August (65 days).** The restart takes every lane and every test site down for about 3 minutes.
4. **Client documents are served without a login.** Filed mandate and disclosure PDFs, documents filed by the PDF splitter against contacts, and staff take-on documents (ID and compliance papers) are saved on the web-visible disk. Anyone who has the address can open them; only a random part of the file name protects them. Seen on **Staging and QA1** (1.8 GB of mandates and disclosures on Staging). The same code is on **live**: the live branch (`Prod`) is the same version as Staging and contains the same lines. We have not looked at live's files or its web-server settings (that needs Johan's go), so it is very likely, not confirmed, that live is affected.
5. **The encryption key's backup is on the same volume as the encrypted files** (one small file on this server's data disk). Losing the key means losing every encrypted FICA document and message attachment. The nightly off-server backup lists the storage folders but not the key folder. Also not yet checked: an old unencrypted FICA backup from July may still sit on that volume.

## 3. New way of working
| Piece | What it does | Measured / estimated saving |
|---|---|---|
| **Server conductor** (a mid-priced model, not Opus) | Resets a lane before each task, hands it a self-contained task card, is told the moment the lane finishes, answers routine questions from the written rules, runs the test and deploy queue, and keeps one short status file. Questions it cannot answer go to a "Questions for PM" list. | Lane-to-lane messaging and the "lane finished" signal were **proven** on this server today. Saving is an **estimate**: roughly 60–80% less lane usage for the same work (clean lanes cost about a tenth of long-running ones; current lane histories are 19–49 MB). To be replaced by real numbers after week one. |
| **A test database per lane** | Each lane keeps its own permanent test database, with a quick reset in place of a rebuild. | **Measured:** a new work folder no longer waits 130 seconds to build a database; clearing leftovers drops from 130 s to about 5 s; waiting behind a database refresh (15–20 min) goes to zero. Test databases shrink from 5.4 GB to about 0.9 GB. It also fixes a hidden fault: today a lane's tests can run against a database built from a different branch (one was 195 changes ahead) without any warning. |
| **Lean standing files and skills** | Shorter instruction files, long history archived, task-specific rules loaded only when needed. | **Measured:** what a fresh lane reads at start drops from about 297k tokens to about 29k (the always-read core is about 9k). See below. |
| **Status file** | One line per lane: task, state, report. Lives on the server. | Replaces asking each lane. How the remote project manager reads it is not yet decided. |
| **Page-design standard and spec checklist** | Andre is writing the design standard. The spec checklist (search, sort, filters, own/branch/agency visibility, second-agency test) already exists as house rules and would be turned into a one-page tick-list. | Not in the material we compiled from; to be added when Andre's draft lands. |

**Lean standing files:** built by cc1, pushed on the branch `lean-standing-files-2026-10-24`. It is **not merged**, and is waiting for review by Johan and Andre. A fresh lane start drops from about 297k tokens to about 29k, and the always-read core is about 9k. The story file the lanes were made to read (CHAT_STARTER) shrinks from 930 KB to 19 KB, with the full original kept in an archive folder. Task-specific rules now live in 13 on-demand skills that load only when needed. The biggest spec (leases) is split into a short index plus one file per section, checked word for word against the original; the other four large specs have a split plan. Only documents and skills changed, no product code. cc1 flagged that its list of work in flight still holds 76 old titles and needs a human clean-up, and that a few rules now live only in skills, so a rule you want always read must be named and moved back. Its rule decisions are in section 7 (questions 26–30).

One thing needs Johan's written word: the old box rule says only the audited bridge may type into another lane's window. A conductor doing so is a new component. We have not assumed it is covered.

## 4. Server clean-up
**Done so far (updated after the inventory; state re-checked 24 Oct):** only the safe steps. Logs trimmed (system journal now 46 MB), and the printing, network-discovery, modem, wifi and crash-looping notification services are disabled. The rest is parked until Andre reviews it. The inventory itself was read-only.

**Safe, no yes needed (reversible tuning):** trim old logs and caches (about 1 GB on the small system disk); cut the idle Property24 import workers from 24 to 9 (about 2 GB of memory); switch off dead desktop services and a crashed service that has restarted 1.8 million times; later set MySQL so it stops re-growing.

**Needs Johan (and Andre where it is his):**
| Step | Gain |
|---|---|
| Update and restart one night, after a Hetzner snapshot | frees about 9 GB of memory; fixes flag 3 |
| Shorten database change-log retention from 7 days to 1 | about 18 GB of disk |
| Make the live-testing copy safe (flag 1) | removes the risk; later retiring it frees about 32 GB and 2.7 GB of memory |
| Delete old backups (about 5 GB) and stale test databases (about 2 GB) | about 7 GB; the off-server backup stays |
| Remove old work folders and old checkouts, at least 13 unregistered | about 10 GB |
| Shrink the test copies' uploaded files to a small sample set | 35–45 GB |
| Retire QA2 if Andre is finished with it (quiet since 20 Oct) | about 15 GB |
| Stop 3 of the 6 idle lanes | about 1.3 GB of memory |
| Firewall on (open only website and remote login) | flag 2 |

If steps are all approved: memory use drops from 23.5 GB to 8–10 GB, the data disk goes from 29 GB free to 110–130 GB free, the system disk from 6 GB to 10–12 GB.

**Public websites should move to the live server.** hfcoastal.co.za (about 5,500 page views a day, 6.4 GB on the nearly-full system disk, sends real email), corexweb.co.za, themandatecompany.co.za, the performance site with its AI helper, and docs.hfcoastal.co.za all run on this build server. Two certificates expire in about 30 days (24 and 26 November) and renew from this server, so certificates must move first. This is high risk and needs its own plan. Also open: Staging's scheduled tasks have been off since 21 August.

## 5. File storage
| One agency-year | Today | Optimised |
|---|---|---|
| Sales-type agency (like HFC) | about 45 GB | about 16 GB |
| Rentals add-on, per 300 properties (assumption) | about 21 GB | about 10 GB |
| 5 / 20 / 100 agencies | 225 GB / 900 GB / 4.5 TB | 80 GB / 320 GB / 1.6 TB |

The biggest line (the PDF splitter keeps about 2.7 GB a month of page images nobody reads) is also the least certain. Optimised means: delete splitter page images after 14 days, remove exact duplicates and orphans, shrink oversized photos, shrink scans and documents (signed scans included) on arrival, as long as they stay clearly legible; **only CoreX e-signed PDFs are never altered after signing**. Money is small (about €200 a month falling to about €70 for 100 agencies, from remembered Hetzner prices that need checking); the real limits are 29 GB of room now and a 10 TB cap per volume. The test copies hold 77 GB of live-like files, and hfcoastal.co.za keeps its own 6.35 GB copy of listing photos.

**Rulings needed:** (a) **settled by Johan:** scanned signed documents are optimised on arrival if they stay legible, because a legible copy with unchanged content is still the signed document; (b) **settled:** CoreX e-signed PDFs are never altered after signing, and a fingerprint is stored when they are made; (c) retention period for each document class, what happens at the end, and an agreed exception to "no hard deletes" for end-of-retention purges; (d) whether old closed files may move to cheaper, slower storage, and where it is hosted; (e) move web-visible documents behind login (flag 4).

## 6. Code and speed
Counts below are from QA1, a test copy, not live. Nothing was timed.

**Top safe fixes, no business decision needed:**
1. Turn on route and settings caching on every box and find out why live has none (1–2 days; biggest cheap speed-up; about 90 shortcut routes must be converted first).
2. Stop reading the same agency and setting values six or seven times per page (1 day; also removes about 40 extra database trips on the deals list).
3. Fix the contacts list reading phones and emails row by row (half a day; up to 400 extra trips).
4. Delete provably dead code (1 day).

**Need a yes first:** cached menu and badge counts (may be up to a minute old); slower background refreshes (portal-lead pop-up today appears within 10 s); how long to keep the big logs (1.7 GB of the 3.1 GB database).

**Walk-through plan:** 12 sections, about 42 lane-days in all. Five hold nearly all the speed gain and the second-agency risk, about 14 lane-days, in this order: platform speed pass (3), sign-in and agency separation (4), properties and portal sync (3), contacts (2), logs and retention (2). Agency separation comes first because the Cape Town agency starts now.

## 7. Decisions (yes / no)
1. Make the live-testing copy stop talking to real WhatsApp and Property24 (and use its own address)?
2. Check live's file storage and web-server settings, read-only, to see whether flag 4 is real there?
3. Move mandate, disclosure, splitter-filing and staff take-on documents behind login?
4. Keep a second copy of the encryption key off this server, held by Johan?
5. Turn the firewall on tonight, after a check that nothing in use breaks?
6. Take a snapshot and update and restart one night this week?
7. Authorise the server conductor to reset lanes and hand them task cards?
8. Roll out a test database per lane, one lane at a time?
9. Allow a nightly 03:30 job that refreshes the master test database?
10. Merge the lean standing files once reviewed?
11. Cut database change-log retention to 1 day?
12. Delete the old backups and stale test databases listed?
13. Remove the old work folders after the lanes confirm none are in use?
14. Shrink the test copies' uploaded files to a small sample set?
15. Retire the live-testing copy (after question 1)?
16. Retire QA2? (Andre)
17. Stop 3 of the 6 lanes?
18. Turn Staging's scheduled tasks back on?
19. Plan the move of the public websites to the live server, hfcoastal.co.za first?
20. SETTLED by Johan: scanned signed documents ARE optimised on arrival if they stay legible. Only CoreX e-signed PDFs are never altered after signing.
21. Delete splitter page images after 14 days?
22. SETTLED by Johan: a legible copy with unchanged content is still the signed document, so no attorney question is needed.
23. Approve the four safe speed fixes, on QA1 first?
24. Is a menu badge up to a minute old acceptable?
25. Start the walk-through with the five priority sections?
26. Stop telling lanes to merge Staging into their own branch at the start of every session (they only update their own branch; the Staging-to-QA1 sync stays with the sync lane)?
27. Keep "only cc1 pushes to QA1" as the rule, even though an earlier note allowed a lane to push when its task card said so?
28. Drop "run the dev-check script" from the finish-a-task checklist, since it has never run on this server?
29. Keep the rule "the demo must always be migrated and seeded", which looks out of date against today's QA flow?
30. Trim the duplicated usage rules out of the box-wide instruction file?

_Questions 26–30 come from cc1's migration note (`.ai/archive/LEAN-MIGRATION-2026-10-24.md`, section 3), which lists ten open points, not five. These are the five that are real rule decisions. The other five (QA lane wording, renumbered rules, two superseded test-database rules, branch name, rules that now only load from skills) are in that note for review._
