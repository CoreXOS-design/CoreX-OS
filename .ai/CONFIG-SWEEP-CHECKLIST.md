# Master Configuration Sweep Checklist

Built 2026-09-02, ~11 hours before the webinar. Every screen below was found by
reading `routes/web.php` and the actual settings-hub navigation array in
`resources/views/corex/settings.blade.php` (the `$item['key']` list, lines
~78–158) — not from memory. This is the list that never existed before today,
which is why Johan kept finding config holes one at a time.

**Status legend:** `OPEN` = not yet checked this sweep · `CONFIGURED` =
rendered as admin, shows real configured state · `FIXED` = was empty, now
seeded with realistic values, re-rendered and confirmed · `N/A` = not an
agency-facing config screen, out of scope for the demo.

**Lane key:** cc1 = agency/branches/users/branding · cc2 =
properties/listings/portals/marketing · cc3 = compliance/HR/payroll/suppliers ·
cc4 = e-sign/documents/filing · cc5 = communications/notifications · cc6 =
deals/calendar/dashboard/MI (this lane, also owns this checklist).

---

## Settings Hub (`/corex/settings?s=<key>`) — in-page sections

These are NOT separate URLs — they're Alpine `x-show` panels inside one page,
found in the sidebar nav array. A URL-only route sweep would have missed every
one of these.

| # | Key | Label | Lane | Status |
|---|---|---|---|---|
| 1 | `user` | Profile & Account | cc1 | OPEN |
| 2 | `my-portal` | My Portal | cc1 | OPEN |
| 3 | `agency` | Agency Settings | cc1 | OPEN |
| 4 | `remote-access` | Remote Access | cc1 | OPEN |
| 5 | `features` | Features (on/off) — master module toggle registry | **UNOWNED** | see below |
| 6 | `feature-documents` | Documents | cc4 | OPEN |
| 7 | `feature-rentals` | Rentals | cc2 | OPEN |
| 8 | `feature-contacts` | Contacts | **UNOWNED** | see below |
| 9 | `feature-properties` | Properties & Listings | cc2 | OPEN |
| 10 | `feature-presentations` | Presentations (CMA coverage/thresholds) | cc2 | OPEN |
| 11 | `feature-matches` | Matches (buyer/property matching) | cc6 | OPEN |
| 12 | `feature-dashboard` | Dashboard (cockpit widgets) | **cc6** | OPEN |
| 13 | `notifications` | Notifications | cc5 | OPEN |
| 14 | `commission` | Commission & Revenue Share (splits, caps, fees, tiers) | **cc6** | OPEN |
| 15 | `command-center` | Command Center Rules (calendar automation, event classes) | **cc6** | OPEN |
| 16 | `prospecting-setup` | Prospecting Setup (towns/suburbs/price bands/match tiers) | **cc6** | OPEN |
| 17 | `outreach-templates` | Outreach Templates (seller outreach WhatsApp/email) | cc5 | OPEN |
| 18 | `leave-visibility` | Leave Visibility (calendar matrix by role/branch) | cc3 | OPEN |
| 19 | `whistleblow-settings` | Compliance Reporting | cc3 | OPEN |
| 20 | `system` | System Info & Tools | N/A | diagnostic, not agency-facing |

## Settings Hub — external links (own URL, listed in the same nav)

| # | Key | URL | Lane | Status |
|---|---|---|---|---|
| 21 | `agency-setup` | `/corex/agency-setup` (onboarding wizard) | cc1 | OPEN |
| 22 | `company` | `/corex/admin/company-settings` | cc1 | OPEN |
| 23 | `doc-types` | `/admin/settings/document-types` (PDF splitter labels) | cc4 | OPEN |
| 24 | `docuperfect-types` | `/docuperfect/settings/types` | cc4 | OPEN |
| 25 | `docuperfect-fields` | `/docuperfect/settings/named-fields` | cc4 | OPEN |
| 26 | `esign-recipient-presets` | `/docuperfect/esign/settings/recipient-presets` | cc4 | OPEN |
| 27 | `esign-recipient-templates` | `/docuperfect/recipient-templates` | cc4 | OPEN |
| 28 | `coc-service-types` | `/deals-v2/settings/service-types` (COC/certificate service types for deal pipeline) | **cc6** | OPEN |
| 29 | `proforma-settings` | `/admin/proforma-settings` (invoicing/VAT/bank details) | cc3 | OPEN |
| 30 | `pdf-suite-labels` | `/admin/splitter/doc-types` | cc4 | OPEN |
| 31 | `p24-suburbs` | `/settings/p24-suburbs` | cc2 | OPEN |

## Deal/pipeline config — NOT in the hub nav at all (orphaned from navigation)

Found only by grepping routes — a user browsing Settings would never find
these unless they already know the URL. Worth flagging to Johan regardless of
who configures them.

| # | Screen | URL | Lane | Status |
|---|---|---|---|---|
| 32 | Deal pipeline templates + stages | `/deals-v2/pipeline-setup` (+ `/master`, `/{template}/edit`) | **cc6** | OPEN |
| 33 | Deal distribution rules | `/admin/settings/deal-distribution-rules` | **cc6** | OPEN |
| 34 | Deal↔property sync settings | `/admin/settings/deal-property-sync` | **cc6** | OPEN |
| 35 | Document distribution rules | `/admin/settings/document-distribution` | cc4 | OPEN |
| 36 | Performance settings | `/admin/performance-settings` | cc3 | OPEN |
| 37 | Minion (scraper) config + suburb/town tree | `/admin/settings/minion` (+ `/tree/suburbs`, `/tree/towns`) | **UNOWNED** | see below |

## Other config screens found via routes, not in the hub nav

| # | Screen | URL | Lane | Status |
|---|---|---|---|---|
| 38 | Compliance agency settings/provisions | `/corex/compliance/agency-settings` | cc3 | OPEN |
| 39 | Email setup | `/corex/settings/email-setup` | cc5 | OPEN |
| 40 | Header/signature preview | `/corex/settings/preview-header`, `/preview-signature` | cc1 | OPEN |
| 41 | User oversight | `/corex/settings/user/oversight` | cc1 | OPEN |
| 42 | E-sign finalization settings | `/docuperfect/esign/settings/finalization` | cc4 | OPEN |
| 43 | Rental division settings | `/rental/settings` (+ document-types, properties, reminders) | cc2 | OPEN |
| 44 | Prospecting duplicate/stale rules | `/corex/settings/prospecting/duplicate-rules`, `/stale-rules` | **cc6** | OPEN (dup of §16 area, separate sub-pages) |
| 45 | Contact governance (command-center) | `/corex/command-center/settings/contact-governance` | **cc6** | OPEN |
| 46 | Event classes (command-center) | `/corex/command-center/settings/event-classes` | **cc6** | OPEN |

## Named in the brief but NOT found as a distinct screen — flag to Johan

- **"Feedback question sets"** and **"working hours"** — no dedicated settings
  screen found under either name. Feedback requirement (`requires_feedback`)
  is a per-event-class flag inside `command-center` → Event Classes (#46), not
  a separate question-set builder. Working hours: not found anywhere in the
  settings hub or route list. Either these don't exist as configurable
  screens in this build (agent availability may be hardcoded/unconfigured by
  design), or they live somewhere I haven't found yet — will keep looking
  while working my slice, but flagging now rather than silently assuming.

## Explicitly out of scope (not agency-facing demo config)

- `system` (#20) — diagnostic tools.
- `/corex/admin/dev-settings/*` (demo-access, demo-connection, demo-sidebar,
  webinars) — platform/dev tooling, not something a prospective agency would
  ever see or configure.
- `/corex/settings/agencies` (+ create/edit) — System-Owner's list of ALL
  agencies on the platform, not agency-1-scoped config.

---

## ⚠️ Unowned — nobody's slice, needs a call

1. **`features` (#5) — the master module on/off toggle registry.** This is
   the single highest-leverage config screen in the whole system (it can
   silently hide Rentals, Payroll, Compliance, etc. from the entire agency)
   and doesn't fit any of the 6 given slices. **Recommend: whoever finds it
   first should verify every module Johan wants to demo is switched ON here**
   — an empty/misconfigured toggle here would explain several of today's
   "the screen isn't there" reports better than any individual feature's own
   config would. I'll check this myself first since it blocks visibility of
   my own slice's screens too, but the fix (if any module is off) may belong
   to whichever lane owns that module.
2. **`feature-contacts` (#8) — contact types/sources/tags config.** Doesn't
   map to any of the 6 slices as given. Closest fit is cc1 (owns "users," and
   contacts are agency-wide CRM config) but that's a guess, not an assignment
   from the brief. Needs Johan/conductor's call, or default to cc1 by
   elimination.
3. **`admin/settings/minion` (#37) — scraper/prospecting-source config +
   suburb/town tree.** "Minion" strongly suggests it feeds prospecting data
   (my slice), but it's also plausibly infrastructure/platform config nobody
   should touch for a demo. I'll open it and make a judgment call rather than
   leave it fully unowned, and report back what it actually is.

---

## Running status

_(updates as lanes report — cc6 will edit this section as results come in)_

- **Total screens enumerated: 46** (+ 2 named-but-not-found items flagged above).
- cc6 (mine): 12 screens — feature-dashboard, feature-matches, commission,
  command-center, prospecting-setup, coc-service-types, deal pipeline-setup,
  deal-distribution-rules, deal-property-sync, prospecting duplicate/stale
  rules, contact-governance, event-classes.
- cc1: 9 screens. cc2: 6 screens. cc3: 6 screens. cc4: 8 screens. cc5: 3 screens.
- Unowned, needs a decision: **3** (features toggle, feature-contacts, minion config).
- Named in brief, not found as a screen: 2 (feedback question sets, working hours).
- Out of scope: 3 (system info, dev-settings, platform agency list).

Starting on my own slice now. Will update this table as I go and report back.
