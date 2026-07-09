# AT-199 — Private Property buyer-lead pull (P24-parity intake)

> Jira: https://corexos.atlassian.net/browse/AT-199 (project AT · Task · To Do).
> Status: **BUILT · staging-verified (our-side) · DORMANT · not on live.** Date: 2026-07-07. Branch: `AT-pp-leads`.
> (Was tentatively AT-192; that number is taken by the Elize branch-attribution work — renumbered to the real Jira issue AT-199.)

## What
Pull Private Property buyer enquiries into `portal_leads`, exactly parallel to the P24 lead pull — the clean API path from `.ai/audits/pp-lead-capture-parity-2026-07-06.md`. Uses the already-wired PP SOAP client's new `ListingLeadDetailsFeed` operation. Dormant behind a per-agency toggle (default OFF); activation is a one-click admin setting with an inherent kill-switch.

## Pillars
Property (listing match-or-create, rule #10) · Contact (Buyer resolve/create) · Agent (lead ownership + notifications). Same downstream loop as P24/website/webhook — PP is not a new island.

## Files
- `app/Services/PrivateProperty/PpLeadService.php` (new) — mirror of `P24LeadService`.
- `app/Services/PrivateProperty/PrivatePropertySoapClient.php` — `+listingLeadDetailsFeed()`.
- `app/Jobs/PrivateProperty/PullPpLeadsJob.php` (new) — scheduled every 5 min.
- `routes/console.php` — `pp-leads-pull` schedule (dormant; gated in service).
- `database/migrations/2026_07_07_000001_add_pp_lead_pull_enabled_to_agencies.php` — `agencies.pp_lead_pull_enabled` bool default **false**.
- `app/Models/Agency.php` · `app/Http/Controllers/Admin/AgencyController.php` · `resources/views/admin/agencies/create-edit.blade.php` — toggle + settings surface.
- `tests/Feature/Leads/PpLeadServiceTest.php` (new) — 5 tests.

## Design decisions
- **No portal-enum migration** — `'pp'` is already valid (`PortalLead::PORTAL_PP`); used the canonical value, not a new `'privateproperty'`.
- **STRICT dedup by PP `LeadId`** (persisted as `lead_source_raw.__corex_lead_id`); composite fallback (ref+email/phone+time) when PP omits it. Re-pulls create zero duplicates.
- **Cursor** per agency in cache (`pp.leads.cursor.agency.{id}`); `StartDate` advances past the newest ingested lead (+1s). First run = 7 days back.
- **Failure-contained**: SOAP fault/timeout → logged, `{error}` returned, run continues; never throws, never breaks the P24 pull or the scheduler.
- Downstream is portal-generic — `PortalLead::portalLabel()`/`agentIds()`, listeners, `BuyerLeadCascadeService::SOURCE_PORTAL_PP` all already handle PP identically to P24.

## Verification (staging, 2026-07-07)
- 5/5 feature tests green (mapping, strict dedup, dormancy, cursor advance, fault containment).
- Real SOAP `ListingLeadDetailsFeed` to PP **sandbox** → connection timed out → **contained cleanly** (no throw; `{fetched:0,error}`). Sandbox endpoint unreachable from host; **staging holds SANDBOX creds, not production** — a real live-lead pull needs production token + network + the feed enabled.
- Synthetic lead end-to-end: `portal_leads` row correctly shaped, property resolved via `UniqueListingId`, Buyer contact created (source=Private Property, owner=listing agent), re-process deduped to zero, cleanup soft-deleted (no-hard-delete).
- Dormancy: toggle OFF → `pullForAllAgencies()` = `{dormant:true}`, job ticks clean, `pp-leads-pull` scheduled.

## To activate (one click)
Admin → Agencies → edit HFC → tick **"Pull buyer-enquiry leads from Private Property"** → Save. Untick to stop. On activation confirm PP prod creds + that PP enabled the lead feed for the branch account.

## Open / next
1. **Real live-lead pull unproven** — needs production PP token + reachable endpoint + lead feed enabled (PP-side). Ask PP to confirm `ListingLeadDetailsFeed` is enabled for HFC's branch.
2. **Live deploy** — not done. Requires main-merge (main is behind Staging); dormant + additive, but a production main-merge is Johan/Andre's call given divergence.
3. Optional follow-up: `ListingPerformanceStats` → `property_portal_metrics` for P24-parity engagement stats (separate ticket).
