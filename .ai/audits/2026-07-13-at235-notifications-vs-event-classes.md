# AT-235 — Notifications vs event classes: are they fighting over the same settings?

> **Status:** investigation. **Read-only. No code changed.**
> **Question (Johan/Elize walkthrough, 13 Jul 2026):** are the notifications system and the event
> classes competing over the same settings — double-fire, contradictory toggles, two config
> surfaces controlling one behaviour?
> **Short answer: YES — and the framing is too kind.** They are not two systems fighting over one
> settings surface. There are **five independent comms senders and six settings surfaces**, and the
> preference system governs **8 of ~36 send sites**. The rest bypass it entirely.
> **The receipt:** one notification fired **1,903,039 times in 24 days** — *inside* the pipeline
> that has idempotency. It only stopped because someone deleted the scanner.
> **Author:** m6. Sources: live `nexus_os` (read-only), two full code inventories, and the specs.

---

## 1. The answer in one picture

```
                        WHO CAN SEND A USER-FACING COMM TODAY

  1. NotificationDispatcher::fire()      8 sites    prefs ✓  open-hours ✓  cooldown ✓  log ✓
  2. Raw ->notify() / Notification::send()  31 sites   prefs ✗ (mostly)     no log
  3. CalendarNotificationDispatcher      1 site     agency class config — then THROWS IT AWAY
  4. CalendarReminderService (AT-178)    1 site     prefs ✓  own log (calendar_reminders_log)
  5. SendCalendarDigests + OversightDigestJob        own settings, own tables, no prefs
```

**There is no chokepoint.** `NotificationDispatcher::fire()` — the only path that respects user
preferences, open hours, cooldown, and writes the dispatch log — is **opt-in**, and almost nothing
opts in.

### The six settings surfaces

| # | Surface | Scope | Governs |
|---|---|---|---|
| 1 | `notification_event_types` + `user_notification_preferences` | **per-user**, global catalogue | the 8 dispatcher sites |
| 2 | `user_dashboard_settings` / `agency_dashboard_settings` | user, or agency if locked | master switches + "adapter" columns |
| 3 | **`calendar_event_class_settings`** | **per-agency, per-class** | calendar colour alerts, digest, reminder offsets/channels |
| 4 | `UserOversightPreference` | per-user | the hourly oversight digest |
| 5 | per-event reminder overrides (`calendar_events`) | per-event | AT-178 offsets/channels |
| 6 | `DealStageDocumentRule.auto_on_stage_tick` | per-rule | deal document emails to clients |

Elize's instinct was right. She was just looking at the two she could see.

---

## 2. THE RECEIPT — a 1.9-million-notification storm, inside the "safe" pipeline

Queried live (`nexus_os`):

| event key | channel | dispatches | window |
|---|---|---|---|
| **`contact.fica_missing`** | in_app | **956,813** | 26 May → 19 Jun |
| **`contact.fica_missing`** | push | **946,226** | 26 May → 19 Jun |
| `property.documents_missing` | in_app + push | 8,324 | 26 May → **today** |
| everything else | | ~300 | |

**1,903,039 dispatches — 99.5% of the entire dispatch log. 286,070 in a single day (28 May).**

### Why the idempotency did not save us

```
contact.fica_missing:  1,903,039 rows
                             368 distinct threshold_hit_at values
                          15,178 distinct (user, subject) pairs
                       →  ~63 re-fires per user-subject pair
```

The dispatcher dedups on `(user, event, subject, channel, threshold_hit_at)`. The spec
(`notification-system-overview.md` §8) says `threshold_hit_at` *"is usually rounded … so the check
is stable across cron runs"*. **For this event it moved.** A moving `threshold_hit_at` produces a
fresh key on every scan, so the dedup check never matched and the scanner re-fired the same fact,
for the same user, about the same contact, every 30 minutes for 24 days.

**This is the most important finding in the ticket, and it is the opposite of the expected one.**
The storm did not come from the un-governed 80%. It came from **the one pipeline that has
preferences, cooldown and an idempotency ledger.** The ledger is only as good as the stability of
the key it dedups on — and nothing tests that.

**It stopped because the scanner was deleted** (`7e8349a5`, 1 Jul, commit message: *"fix"*), not
because anything detected it.

---

## 3. Collisions — the full list

### C1 · The dispatcher governs 8 of ~36 send sites
`NotificationDispatcher::fire()` (`app/Services/CommandCenter/NotificationDispatcher.php:28-131`)
does the whole guard chain. Its callers: `ScanPropertyNotifications.php:60,86`,
`ScanDealNotifications.php:66`, `SendLeaveRemindersCommand.php:29,52`, `CalendarController.php:1274`,
`LeaveApplicationController.php:208`, `MyPortalLeaveController.php:208`.
**31 other sites call `->notify()` / `Mail::` directly.** ~80% of user-facing comms never see a
preference.

### C2 · Three tiers of preference honouring
- **Tier A — full** (8 dispatcher sites).
- **Tier B — partial**: the master email switch is checked *inside* `via()`
  (`EventDueReminderNotification.php:22`, `NewPortalLeadAgentNotification.php:27`,
  `DealStepAlertNotification.php:43`). No per-event toggle, no open hours, no cooldown, no log.
- **Tier C — zero**: all 7 Presentations notifications, all 8 `SignatureActivityNotification` sites,
  `SignatureTeamAlert`, `LeaseExpirationAlert`, `NewPropertyMatchNotification`,
  `RcrDeadlineApproaching`, `CommsAccessRequested`, `MailboxPollFailure`, `AgentInvite`.
  **A user cannot turn any of these off. There is no switch.**

### C3 · Calendar event classes are a SECOND notification settings system — the agency-scoped one
`calendar_event_class_settings` carries `green/amber/red_notifications` (role→channel maps),
`daily_digest_enabled` + `daily_digest_roles`, and `default_reminder_offsets` +
`default_reminder_channels`. It is **the only agency-scoped gate that actually gates a send** — and
it **bypasses `user_notification_preferences` entirely**.

**This is the collision Johan suspected**: agency-level comms config lives in the *calendar* module;
user-level comms config lives in the *notifications* module; neither knows the other exists.

### C4 · 🔴 The agency's per-class channel config is INERT — computed, then thrown away
`app/Services/CommandCenter/Calendar/CalendarNotificationDispatcher.php:53` computes
`$viaChannels` from the class settings, checks it is non-empty… and then calls
`$user->notify($notification)` at **`:59` without passing it**. Delivery falls through to
`EventDueReminderNotification::via()`.

**Consequence:** a class configured **"in-app only" still sends email**; a class configured
**"email only" still writes an in-app row.** The agency admin sets the channel, and the code
ignores it. *(One-line fix.)*

### C5 · One calendar event → up to THREE emails, from three engines, with no shared dedup key
| Engine | File | Guarded by |
|---|---|---|
| AT-178 reminders | `CalendarReminderService.php:249` (`EventReminderMail`) | `calendar_reminders_log` |
| Colour transition (03:00) | `CalendarNotificationDispatcher.php:59` → mail via `via()` | **nothing** |
| Daily digest (06:30) | `SendCalendarDigests.php:165` (`CalendarDailyDigest`) | **nothing** |

They share **no dedup key**. `calendar_reminders_log` guards only the first;
`notification_dispatch_log` guards none of them.
**Plus an in-app double:** `CalendarReminderService.php:226` (`sendNow(...,['database'])`) *and*
`CalendarNotificationDispatcher.php:59` both write an `EventDueReminderNotification` row for the
same event/user.

### C6 · Four idempotency mechanisms, none shared
`notification_dispatch_log` (8 sites) · `calendar_reminders_log` (AT-178 only) ·
`task.metadata->reminder_sent` JSON flag (`ProcessReminders.php:90`) · a Cache key
(`CheckLeaseExpiry.php:110`). Nothing can answer *"have we already told this user this fact?"*
across paths.

### C7 · 🔴 Dead toggles — the settings page is lying to users right now
`ScanContactNotifications.php` was **deleted** on 1 Jul (`7e8349a5`). Its catalogue rows were not.

| toggle | producers in code | still shown in UI? | users who saved a preference |
|---|---|---|---|
| `contact.fica_missing` | **0** | ✅ yes | 2 (all enabled) |
| `contact.fica_expiring` | **0** | ✅ yes | 4 (all enabled) |
| `contact.no_followup` | **0** | ✅ yes | 4 (all enabled) |
| `contact.birthday` | 4 | ✅ yes | 4 — **now delivered by the CALENDAR digest** (`SendCalendarDigests.php:233`) |

**Three switches that can never fire anything, and users have deliberately turned them on.** The
fourth still works, but it is now produced by the *calendar* system while being configured in the
*notifications* system — a cross-system dependency nobody would guess from either UI.

### C8 · An agency cannot disable a notification type at all
`notification_event_types` has **no `agency_id` and no `is_active`** (verified on live). Under
agency lock (`dashboard_settings_mode = 'agency'`), per-event preferences fall back to catalogue
defaults — `NotificationPreferenceService.php:56-57` says so in a comment. So an agency admin can
flip the master switches and nothing else.
**Meanwhile the calendar classes ARE fully agency-configurable.** Two governance models, opposite
answers to "who owns this setting".

### C9 · An event key that isn't in the catalogue
`NotifyAgentOfClientTestimonial.php:60` fires `contact.testimonial_submitted`. That key **does not
exist in `notification_event_types`**. It is un-configurable and un-suppressable — it cannot appear
in the settings UI because the UI renders the catalogue.

### C10 · Push ignores the push master switch (one listener)
`PushNewPortalLeadToMobile.php:64` → `PushNotificationService::sendToUserIds()`, which **never reads
`notify_push`** (callers are expected to gate it; `CalendarReminderService.php:229` does, this one
does not). **A user who turned push off still gets portal-lead pushes.**

### C11 · Catalogue columns that nothing reads
`supports_in_app` / `supports_email` / `supports_push` are **never consulted at send time** — only
rendered in the UI. A type marked "does not support email" will still email.

---

## 4. The single-owner model (proposal)

**One rule: nothing sends a user-facing comm except through one gateway.**

```
   ANY producer (scanner · listener · service · controller · console)
                              │
                              ▼
                 ┌──────────────────────────┐
                 │   CommsGateway::fire()    │   ← the ONLY send path
                 │   (NotificationDispatcher │
                 │    promoted + widened)    │
                 └──────────────────────────┘
                              │
   catalogue ─────────────────┤   is this a registered comm type?      (one registry)
   preferences ───────────────┤   does THIS user want it?              (one resolver)
   agency policy ─────────────┤   does the AGENCY allow it?            (one gate)
   channels ──────────────────┤   which channels? (resolved HERE, not inside via())
   idempotency ───────────────┤   have we already said this?           (one ledger)
                              ▼
                     database · mail · push
```

**The five moves:**

1. **One registry.** Every comm type is a row in `notification_event_types` — including the ones
   that bypass it today (Presentations, Signature, Lease, Match, RCR, testimonial…). *If it can
   reach a user, it is in the catalogue.* A comm with no catalogue row cannot be configured, and
   therefore cannot be turned off (C9).
2. **One resolver.** `NotificationPreferenceService::effective()` is the only answer to *"should
   this user get this"*. Channel selection moves **out of `via()`** (C2, C4) and into the gateway.
3. **One agency gate.** Add `agency_id` + `is_active` to the catalogue (C8) so agency-level
   governance matches what the calendar classes already offer. **Per CLAUDE.md #10a, this setting
   must also land in the Agency Onboarding Setup Wizard in the same prompt.**
4. **One ledger.** `notification_dispatch_log` for every path. `calendar_reminders_log` and the
   ad-hoc flags either feed it or retire (C6). **And the dedup key must be tested** — the storm
   (§2) was a *stable-key* failure, not a missing-ledger failure.
5. **One guard, enforced by the build.** A test that fails if `->notify(` or `Mail::to(...)->send(`
   appears outside the gateway — the same shape as the AT-203 `agency_id` guard test. Without it,
   the 32nd bypass gets added next month.

---

## 5. Remediation options — sized

| # | Fix | Why now | Size |
|---|---|---|---|
| **R1** 🔴 | **Stop the settings page lying.** Remove/retire the 3 dead `contact.*` toggles (or restore a producer); make `contact.birthday`'s cross-system dependency explicit. | Users have *deliberately enabled* switches that do nothing. This is live, today. | **S** |
| **R2** 🔴 | **Pass `$viaChannels` to `notify()`** (`CalendarNotificationDispatcher.php:59`). | One line. Agency channel config is currently inert — the admin sets it and we ignore it. | **S** |
| **R3** 🔴 | **Stabilise + test the dedup key.** Assert `threshold_hit_at` is rounded/stable per event type; add a regression test that a scanner run twice in one window fires once. | This is what let 1.9M notifications out. Nothing currently prevents a recurrence. | **S–M** |
| **R4** | **De-duplicate the calendar triangle** (C5) — one dedup key across the 3 engines; decide which engine owns which fact. | Three emails for one event is the user-visible symptom Elize will hit first. | **M** |
| **R5** | **Route Tier-C through the gateway** (Presentations ×7, Signature ×8, Lease, Match, RCR…). Register their keys. | Restores the ability to turn things off. Largest block; do it in slices by module. | **M–L** |
| **R6** | **Agency-level per-type governance** (`agency_id` + `is_active` on the catalogue) + wizard surfacing (#10a). | Closes C8; gives parity with calendar classes. | **M** |
| **R7** | **Build guard** — fail the build on a raw `->notify()` outside the gateway. | Without it, everything above erodes. | **S** |

**Recommended order: R1 · R2 · R3 first** (all small, all live-visible, all independent), then R4,
then R5 in slices, with R7 landing before R5 so the new discipline is enforced as it is applied.

---

## 6. What this means for AT-245 (proforma admin notify)

AT-245 must **not** add a 32nd bypass. It should be the **first citizen of the new model**:

1. Register its key in `notification_event_types` (so it is visible and switchable).
2. Send **only** via `NotificationDispatcher::fire()` — never a raw `->notify()`.
3. Let the dispatcher resolve channels; do not hardcode them in `via()`.
4. Inherit the dispatch log automatically (idempotency + cooldown for free).

If AT-245 is built that way, it costs nothing extra and becomes the reference implementation R5
migrates everything else toward. If it is built the way the last 31 were, it is another thing users
cannot turn off.

---

## 7. Confidence & method

- **Live-verified** (read-only `nexus_os`): all dispatch counts, the storm, the dead toggles, the
  user-preference rows, the absence of `agency_id`/`is_active` on the catalogue.
- **Code-verified** with file:line: both inventories cross-checked; every collision above cites the
  exact send site.
- **Not verified:** whether the 1.9M dispatch-log rows all produced *bell rows* — the `notifications`
  table holds 89K total, so either the in-app writes failed, or the bell was pruned. **Worth a
  follow-up**, but it does not change any finding: the dispatch log is the idempotency ledger, and
  it recorded 1.9M sends that should have been ~15K.

*No code changed. No tickets raised.*
