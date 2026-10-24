# ANDRE — START HERE (24 Oct 2026)

Index of everything produced on 24 Oct about the way of working, the build server, file storage and the code. All files are on this server in `/root` and copied into git on branch `proposals-2026-10-24` under `.ai/proposals/2026-10-24/` (branch only, not merged). Read the rulings at the bottom first; they override anything older in these files.

## Files (all non-empty, sizes checked 24 Oct)
| File | Size | What it is |
|---|---|---|
| `/root/workflow-and-server-proposal-2026-10-24.md` | 14 KB | The combined plain-language proposal for Johan and Andre: urgent flags, new way of working, server clean-up, file storage, code and speed, and a numbered yes/no decisions list (1–30). Start here. |
| `/root/server-cleanup-2026-10-24.md` | 30 KB | cc1's read-only inventory of this server: memory, disk, services, databases, backups, public sites, 18-step clean-up plan. |
| `/root/file-storage-audit-2026-10-24.md` | 103 KB | cc3's read-only audit of uploads: what is stored where, duplicates, scan sizes, sample savings, projections, legal points. Appendices hold the code paths. |
| `/root/conductor-design-2026-10-24.md` | 15 KB | cc2's design for a server conductor session: what was proven (lane messaging, event hook, `/clear` by tmux), files, rules, open items. |
| `/root/lane-db-design-2026-10-24.md` | 14 KB | cc6's design for one test database per lane, with measured timings and a step-by-step rollout. |

## Branches on origin
| Branch | What it is |
|---|---|
| `audit-code-db-map-2026-10-24` | The code and database map (`.ai/audits/2026-10-24-code-db-map.md`): size, duplication, speed findings, a 12-section walk plan, top-10 simplifications. Read-only audit, not merged. |
| `lean-standing-files-2026-10-24` | cc1's lean standing files and 13 skills (a fresh lane start about 297k to about 29k tokens). Migration notes in `.ai/archive/LEAN-MIGRATION-2026-10-24.md`. Not merged, pending review. |
| `proposals-2026-10-24` | This index plus the five files above, as `.ai/proposals/2026-10-24/`. Documents only. Not merged. |

## JOHAN'S RULINGS 24 OCT (already decided)
_As relayed to the conductor on 24 Oct in the conductor's own words, not a verbatim quote from Johan._

1. **Public sites move to the live server.** Live and public sites (hfcoastal.co.za, corexweb.co.za, demo1, and the small sites if kept) move to the live server. Andre does the move. This box becomes build and test only.
2. **Server clean-up is PARKED until Andre reviews it.** Only the safe steps are done: journald, npm and /tmp trimmed; cups, avahi, modem, wifi and notif-api disabled. (Checked 24 Oct: those services are disabled or removed and inactive, journal is 46 MB. Nothing else from the clean-up plan has been done.)
3. **Files: optimise every upload the moment it touches CoreX, never afterwards.**
   - Photos are reduced in size (quality kept) in the app on the device or at upload. One stored copy is reused by CoreX, the websites and the portals.
   - Scans and documents are optimised on arrival, signed scans included, as long as they stay clearly legible. Legal view: a legible copy with unchanged content is still the signed document, like a reprint or rescan. Record a fingerprint and an upload record on arrival.
   - CoreX e-sign: optimise templates and attachments before signing; never alter a signed PDF (it would break the seal).
   - _Note:_ the portals need the photo as a JPEG at a public address (Property24 takes the bytes, Private Property fetches the URL), so "one stored copy" has to stay a JPEG the portals accept (audit section 3).
4. **Way of working.**
   - A server conductor resets a lane before EVERY task.
   - Lanes read the lean standing files.
   - One test database per lane.
   - Lanes report via files or events, not polling.
   - The PM (Claude) reports to Johan only "done and ready to test" plus spec questions.
5. **Page standard.** The left menu and the top header are fixed; features design only in the content area. The Properties search screen and the property page are the reference pages. Andre is writing the layout design spec this weekend.
6. **Every spec must cover:** settings (plus the Setup Wizard), a Role Manager permission, own/branch/agency scoping, full CRUD with search, sort, filter and paging, and a menu link.
7. **Open for Andre and Johan:** the decisions list in `workflow-and-server-proposal-2026-10-24.md` (section 7, questions 1–30). Questions 19, 20 and 22 are settled (rulings 1 and 3). Everything else remains open, including the urgent flags (the live-testing copy, firewall, updates and restart, documents served without login, key backup).
