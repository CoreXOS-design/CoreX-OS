# C — Outbound contact audit (release 989f6ffd0..0c7bfebed)

Worktree: /mnt/HC_Volume_103099143/corex-worktrees/audit-release-2026-10-18 (HEAD 0c7bfebed). Read-only review; nothing was run or changed.

Severity count: High 1 · Medium 3 · Low 2

---

## Findings

### F1 — High — CONFIRMED — Scheduled Facebook/Instagram posts publish for real from any copy (Meta has no non-production guard)
- **Where:** routes/console.php:627 (`marketing:publish-due`, every minute) -> app/Console/Commands/PublishDueMarketingPosts.php -> app/Services/Marketing/ScheduledPostPublisher.php:61 `runDue()` / ~136-138 `publishToInstagram/publishToFacebook` -> app/Services/MetaPublishingService.php:37-172 (Guzzle POST straight to `https://graph.facebook.com/v19.0`). Token refresh is also called first (ScheduledPostPublisher.php:250 -> MetaOAuthService::refreshTokenIfNeeded).
- **Who gets contacted / what:** the agent's or agency's real public Facebook Page / Instagram account. Every minute, any `property_marketing_posts` row with status `scheduled` and a due time is posted, using the real page access token stored in `agent_social_accounts`.
- **Why it is a problem:** the email guard (OutboundMailGuard) only watches mail. Nothing in the Meta chain checks the environment: no `OutboundMailGuard::isSendingConfirmed()`, no `app()->environment()` test, no config switch (config/services.php `meta` has only app id/secret). The production-like copy has its own scheduler and the live database's tokens, so (a) a tester who schedules a post on the copy publishes it to the real public page, and (b) once live has scheduled posts and the copy's database is refreshed from live, both servers claim the same row in *their own* databases and the post goes out **twice**. The per-row atomic claim and `onOneServer()` only stop duplicates within one database/cache.
- **Fix (one line):** in `ScheduledPostPublisher::runDue()` (and the direct "Post now" path), refuse to call Meta unless `OutboundMailGuard::isSendingConfirmed()` (or a matching `SOCIAL_REAL_POST` flag) is true; otherwise mark the post "held: not live".

### F2 — Medium — CONFIRMED — Inspection reminder emails to tenants and landlords are ON by default for every agency, and ignore the Rentals / Inspections feature switch
- **Where:** routes/console.php:64 (`rentals:send-inspection-reminders`, daily 07:15) -> app/Console/Commands/SendRentalInspectionReminders.php:34-62 -> app/Services/Rentals/RentalInspectionNotificationService.php:61-82 (`notifyReminder` -> `dispatch`) -> `sendMail` -> SignedDocumentDistributionService::sendGenericMail -> `Mail::to()`.
- **Who / what / when:** every tenant on the lease, plus the landlord (or, if no landlord is tagged, the property's **seller/owner** contacts, RentalInspectionNotificationService.php `landlordContacts()`), plus the inspector. They get a "Reminder" email at 07:15 one day before any inspection in draft / in progress / awaiting signature.
- **Why:** the defaults in app/Models/RentalInspectionSetting.php:1713-1719 are tenant=on, landlord=on, mail=on, reminder_days=1. An agency that has never opened the inspection settings (no row) is therefore opted in. The command reads every agency's inspections with no `AgencyFeatureService->enabled('rentals' / 'rental-inspections')` check. The registry (config/corex-features.php:412-416, 599+) promises that turning Rentals off hides the module and leaves records alone, but the clients of an agency that switched Rentals off still get emailed. The first 07:15 run after deploy will email clients for inspections that were booked before reminders existed, with no warning to the agency. Duplicate protection is fine (one reminder row per inspection per day).
- **Fix (one line):** skip agencies where `rental-inspections` (and `rentals`) is off, and make the reminder opt-in (or show it in the Setup Wizard) for agencies with no settings row.

### F3 — Medium — CONFIRMED (code path) — The new nightly `leases:advance-terms` can put a rental back on Property24 (and push a Private Property status) with no agent action
- **Where:** routes/console.php:54 (`leases:advance-terms`, daily 00:35) -> app/Services/Rentals/LeaseTermAdvanceService.php:110-139 `endIfVacated()` -> app/Services/Rentals/PropertyStatusFollowsLeaseService.php `restorePreLetStatus()` (`$property->save()`, status let -> pre-let/"active") -> app/Observers/PropertyObserver.php:250-253 (marks "returning to market") and :899-903 (new in this release) -> `Property24SyndicationService::reactivateListing()` -> P24 `setListingStatus(..., 'BackOnMarket')`. The same save also queues `SyncPpListingStatusJob` (PropertyObserver.php:808-817) for Private Property.
- **Who / what / when:** the property is re-advertised to the public on Property24 (and its status changes on Private Property) at about 00:35. This happens on the morning after the move-out date for any rental listing that was syndicated before, still has its P24 reference, was approved, and has a completed out-inspection on file. The asking rent CoreX carries over is not pushed (the re-list path returns before any field sync), so the portal shows the old rent.
- **Why:** the only agency control is `auto_restore_status_on_lease_ended`, which defaults to **on** (app/Models/LeaseSetting.php:85). The command loops `Agency::all()` with no rentals feature check. Johan's 2026-10-06 ruling ("portal presence follows status") may make this intended. Even so, it is a public portal push started by a timer, not by an agent, and the setting that allows it describes a property status, not portal advertising.
- **Fix (one line):** when the status change comes from the scheduler, set `skipSyndicationAutomation` and tell the agent "ready to re-list" instead of pushing BackOnMarket, or get Johan's explicit OK and add that to the setting's wording.

### F4 — Medium — PLAUSIBLE — Portal calls (P24 / Private Property) have no non-production guard, and a copy uses the live agency credentials from the database
- **Where:** app/Services/Syndication/Property24/Property24ApiClient.php:86-100 (per-agency `p24_username/p24_password` from the agencies row; host from env `P24_EXDEV_API_URL`), app/Services/PrivateProperty/PrivatePropertyConfig.php:22-70 (per-agency `pp_wsdl`, `pp_sandbox`, credentials, all from the agencies row and taking priority over env).
- **Who / what:** live Property24 / Private Property listings, through any status-change push (PropertyObserver), DesyndicatePropertyFromPortalsJob, SyncPpListingStatusJob, and the new F3 path.
- **Why:** unlike mail, neither client checks the environment. For PP, a copy of the live database carries the live WSDL and `pp_sandbox=0`, so pushes from the copy reach the real portal whatever the copy's .env says. For P24, it depends only on whether the copy's .env points `P24_EXDEV_API_URL` at production. Most of this predates the release. It is included because the copy now runs a scheduler and queue workers, and the release adds a new automatic push path (F3). Not confirmed against the copy's actual .env (out of read-only scope).
- **Fix (one line):** add a portal guard matching OutboundMailGuard (refuse portal writes unless `isSendingConfirmed()`), or as a minimum force `pp_sandbox`/sandbox hosts when `looksLikeProduction()` is false.

### F5 — Low — CONFIRMED — Lease commands run for agencies whose Rentals module is switched off
- **Where:** app/Console/Commands/AutoMonthToMonthLeases.php:30, app/Console/Commands/ReconcileLeaseSigning.php (`Agency::all()`), app/Services/Rentals/LeaseTermAdvanceService.php (all agencies), app/Console/Commands/CheckLeaseExpiry.php (`Agency::all()`).
- **What:** leases are switched to month-to-month, terms are activated or ended, and agents get in-app/push alerts, all with no `rentals` / `rental-leases` feature check. config/corex-features.php:415 says turning it off leaves "existing leases and their dates untouched". Only staff are notified (database/push), so no client is contacted except through F3.
- **Fix (one line):** skip agencies where `AgencyFeatureService->enabled('rental-leases', $agency)` is false.

### F6 — Low — CONFIRMED — Overdue-lease alert repeats every 7 days for ever
- **Where:** app/Console/Commands/CheckLeaseExpiry.php `sendLeaseAlert()` (cache key `lease_v2_alert_{id}_expired`, TTL 7 days).
- **What:** the agent who created the lease gets a fresh in-app "overdue" alert every 7 days for as long as the lease stays active past its end date. Staff only, in-app only. It is noise, not an outside contact. With the 05:30 auto month-to-month most leases leave this state, but not those with a notice recorded.
- **Fix (one line):** store a per-lease "overdue alerted" marker instead of a 7-day cache key, or say this weekly nag is deliberate.

---

## New scheduled tasks in this release

| Task | How often | What it does | Can it contact anyone outside the agency? |
|---|---|---|---|
| `leases:auto-month-to-month` | daily 05:30 | Active leases past end date with no notice/renewal go month-to-month; in-app note to agent | No (agent in-app/push only) |
| `leases:advance-terms` | daily 00:35 | Activates signed renewals on start date; ends vacated tenancies and restores property status | **Yes, indirectly**: P24 BackOnMarket re-list + PP status push via PropertyObserver (F3) |
| `leases:reconcile-signing` | daily 00:20 | Brings leases in step with their e-sign envelope | No (agent in-app + agent email via LeaseAgreementStatusMail) |
| `rentals:send-inspection-reminders` | daily 07:15 | Reminder N days before an inspection (default 1) | **Yes**: tenants, landlord/owner, inspector by email (F2); WhatsApp is only logged "queued", never sent |
| `rentals:send-planned-inspection-reminders` | daily 07:20 | Reminds agent of interim inspection dates | No (agent only) |
| `rentals:send-due-inspection-reminders` | daily 07:25 | Reminds agent of In/Out inspections due | No (agent only) |
| `rentals:send-signing-window-reminders` | daily 07:30 | Tells agent a signing window is closing/closed | No (agent only) |
| `rentals:settle-completion-rounds` | daily | Retired: `settleSilent()` returns 0 | No (does nothing) |
| `rts:build-lists` | daily 04:30 | Builds each agent's Road to Sold list | No (internal; permission-gated, not feature-gated) |
| `ai-templates:purge-reference-images` | daily 03:15 | Deletes 7-day-old AI template reference images | No |
| `article-library:run` | daily 02:30 (runs only if owner setting is due; default off) | Fetches articles from configured sources + AI relevance check; never publishes | No people contacted (outbound HTTP to source sites + AI provider only) |
| `marketing:publish-due` | every minute | Publishes due scheduled Facebook/Instagram posts | **Yes**: public posts on real Meta pages (F1) |
| `marketing:purge-scheduled-images` | daily 03:20 | Removes old scheduled-post pictures | No |

Retired: `rentals:prepare-renewal-drafts` (scheduled entry removed) and the inline `rentals:test-inclusion` closure command.

---

## Checked and found clean
- **Mail guard coverage:** every new or changed client mail goes through `Mail::to()` / SignedDocumentDistributionService / RentalMailDispatcher (-> ComplianceMailDispatcher -> PerMailboxMailTransportBuilder). All of these are covered by OutboundMailGuardServiceProvider's MessageSending interception, or by the guard's sink redirect in PerMailboxMailTransportBuilder/ImapSentFolderAppender. The release adds a boot-time "tripped" check (real mail host on non-prod refuses everything). No new raw SMTP/transport bypass was found in the diff.
- **SMS / WhatsApp:** no SMS or WhatsApp API is called anywhere in the release. Every WhatsApp path is a `wa.me` deep link the agent opens by hand (RentalContractorJobService, RentalInspectionSigningLinkController). Inspection WhatsApp notifications are only logged as "queued" (default off).
- **HFC wording / branding:** none found in new or changed mailables, notifications or email views (resources/views/emails/rentals/*, ai-spend-alert). From-name/address go through MailFromName::rebrand / PlatformSender, which explicitly strip an install-wide "Home Finders Coastal" value. SendSalesDocumentReminders moved from `config('mail.from.address')` to PlatformSender.
- **Duplicates:** listener double-registration is off (event discovery disabled, AppServiceProvider). The landlord crew-completion mail is claimed once per card (`landlord_crew_notice_at`). The tenant completion check is deduped per round (`tenant_notify_status`, and openRound reuses an awaiting round). Inspection reminders are deduped per inspection per day. The owner final statement fires on RentalWorkOrderClosed, which can only happen once. Scheduled posts use an atomic claim and are never auto-retried after failure.
- **New queued mail jobs** (SendTenantCompletionCheckMailJob, SendLandlordDisputeMailJob, SendLandlordCrewCompletionMailJob): user-action triggered, each send wrapped in try/catch so a failure does not throw and retry. The dispute mail is gated by `notify_landlord_on_dispute`, the crew-completion mail by `notify_landlord_on_crew_completion`.
- **Agent-only reminders** (planned/due/signing-window, lease expiry, month-to-month, reconcile-signing) go through NotificationDispatcher to staff only.
- **AI spend alert mail:** goes to platform-operator recipients (setting, default the CoreX operator address), backed off on failure. Not client-facing.
- **Portal jobs changed:** DesyndicatePropertyFromPortalsJob now re-reads status and stands down if back on market (safer). GrandfatherSyndicatedStockJob only switched to `onMarket()` and does not push. `p24:reconcile-portal-presence` is unchanged and scheduled without `--withdraw`.
- **Retry storms:** no new external API job with unbounded retries. The Meta publisher fails once and is never retried. `withoutOverlapping(10)` plus the 30-minute interrupted sweep never re-publishes.
- **Backfill/one-off commands added** (BackfillLeaseAgents, BackfillLeaseNoticeTerms, BackfillLeadFirstResponse, AssignTemplateToAgency, PortalDemoSignin, etc.): not scheduled, and none send mail or notifications.
