# AT-235 — Notification gateway consolidation: plan of record

> **Status:** GREENLIT (Johan, 2026-07-13): *"we are finding the proper solution and building the
> fix asap."* No deferral. Primary lane.
> **Goal:** ONE gateway. Every producer through it. Settings honoured in exactly one place. One
> ledger.
> **HANDOFF CLAUSE:** if the e-sign gate greens, Phase 1 preempts this lane and **m5 takes the
> baton from this file.** It is written to be picked up mid-stream — every stage records what is
> done, what is next, and *why* each decision was made.
> **Findings:** `.ai/audits/2026-07-13-at235-notifications-vs-event-classes.md`
> **Landed already:** R1 (dead toggles retired) · R2 (inert channel config) · R3 (dedup key) ·
> R7 (build guard) — Staging `0bd77a66`, QA1 `7da373cc`.
> **Author:** m6. **Last updated:** 2026-07-13.

---

## 0. STATUS BOARD — update this as you go (handoff reads this first)

| Stage | What | State |
|---|---|---|
| **S0** | Gateway core — `send()` can carry ANY notification class | ✅ **DONE** (+ C11 closed: capability now caps preference) |
| **S1** | AT-245 proforma converted — **citizen #1, the reference implementation** | ✅ **DONE** — allow-list 23 → 21 |
| **S2** | Migrate producers, module by module | 🔄 **slice (a) Leads DONE** — allow-list 21 → 20; C10 closed; push listener retired. **NEXT: slice (b) Comms** |
| **S3** | Fold `CalendarNotificationDispatcher` in; kill the three-engine calendar mess | ⬜ |
| **S4** | One ledger — retire the 4 competing idempotency mechanisms | ⬜ |
| **S5** | Agency governance (`agency_id` + `is_active`) + Setup Wizard (CLAUDE.md #10a) | ⬜ |

**Six unbuilt watchers stay hidden.** They are new features, not part of this fix. Johan decides
their build priority separately. Do not touch them in this lane.

---

## 1. THE BLOCKING FACT (why S0 must come first)

`NotificationDispatcher::fire()` **hardcodes the notification it sends**:

```php
$notification = new PillarEventNotification(eventKey: …, title: …, body: …);
$user->notify($notification);
```

It can only ever send a *generic title/body* alert. But **every one of the 22 bypasses has its own
notification class** with bespoke mail content — `ProformaCreatedNotification`,
`SignatureActivityNotification`, `RcrDeadlineApproachingNotification`, the seven Presentations
ones…

**So "route the producers through the gateway" is impossible today.** A producer that switched to
`fire()` would lose its email entirely and send a generic stub instead. *That is why 31 bypasses
exist: the gateway was never able to carry them.* Nobody was being lazy — the door was locked.

**The architectural move:** separate

- **WHO / WHETHER / WHERE** — preference, agency policy, open hours, cooldown, idempotency,
  ledger. → **the gateway's job, and only the gateway's.**
- **WHAT** — the message itself, its mail template, its FCM payload. → **the caller's notification
  class, unchanged.**

Today those two are welded together in one method. S0 separates them. Everything else follows.

---

## 2. S0 — the gateway core

**New method** on `NotificationDispatcher` (which becomes the gateway; renaming it is cosmetic and
deliberately NOT done, to keep this diff reviewable):

```php
public function send(
    User $user,
    string $eventKey,          // must exist in notification_event_types
    Model $subject,            // what the alert is ABOUT (dedup identity)
    Notification $notification,// the CALLER'S own notification class
    array $args                // threshold_hit_at (REQUIRED), payload…
): bool
```

It runs the identical guard chain that `fire()` already runs — preference → channels → open hours →
dedup → cooldown — and then, instead of building its own notification:

```php
Notification::sendNow($user, $notification, $channelsResolvedByTheGateway);
```

`sendNow()` with an explicit channel list **overrides the notification's `via()`**. That is the
point: **channel selection stops living inside `via()`** (where it is invisible, per-class, and
inconsistent) and lives in exactly one place.

`fire()` is kept, and simply becomes `send()` with a `PillarEventNotification` — so the 8 existing
callers are untouched and cannot regress.

**Push:** the gateway pushes only if the notification opts in by implementing `toFcmPayload()`
(duck-typed via `method_exists`, so no interface is forced on 22 classes at once). The stable
idempotency key is unchanged.

### The consent invariant — write it down, it has already nearly been broken once

> **Resolved channels are a CEILING, never a floor.**
> A producer, an agency setting, or a class config may narrow what is sent. **None of them may
> widen it past what the user asked for.** In R2 the fix that made the agency's channel config
> *work* also bypassed `via()` — which is where the user's `notify_email` master switch was being
> checked — and would have handed agencies the power to override user consent. The veto had to be
> re-applied deliberately. **Every stage of this consolidation must re-check that invariant.**

---

## 3. S1 — AT-245 proforma: citizen #1

`ProformaGenerationService` currently does:

```php
Notification::send($admins, new ProformaCreatedNotification($invoice, $actor, $reference));
```

An admin **cannot switch it off**, and **nothing records that it fired**. It is the exact feature
AT-235 recommended be gateway-native — and it was built the old way, which is why the R7 guard
caught it on its first merge.

**Convert it:**
1. Register `proforma.created` in `notification_event_types` (migration + seeder — reference data
   must travel, AT-162).
2. Call `gateway->send($admin, 'proforma.created', $invoice, new ProformaCreatedNotification(…), ['threshold_hit_at' => now()])` — a **discrete event**, so `now()` is the correct dedup key and is passed explicitly.
3. Delete its entry from `NotificationGatewayGuardTest::KNOWN_BYPASSES`. **The allow-list must
   shrink, not be inherited.**

**Why first:** it is one call site, it is the smallest possible end-to-end proof of the whole
model, and it converts the newest bypass before it calcifies. It becomes the worked example every
later stage copies.

---

## 4. S2 — migrate the producers (the bulk)

22 files. Do them **in module slices**, each slice its own dual-deploy, each with the failure-tested
standard. For every producer:

1. Register its event key in the catalogue (if absent).
2. Replace `->notify()` / `Notification::send()` with `gateway->send(…)`.
3. Pass an explicit `threshold_hit_at`: **stable** for a persistent condition (notify once),
   `now()` for a discrete event (notify each time). *A time bucket is not a fact — see R3.*
4. Delete the entry from `KNOWN_BYPASSES`.
5. Remove any channel logic from the notification's `via()` — the gateway owns channels now.

**Slice order** (smallest blast radius first):

| Slice | Files | Note |
|---|---|---|
| a. Leads | `EmailPortalLeadToAgent`, `PushNewPortalLeadToMobile` | Also fixes C10 — push currently ignores `notify_push` entirely. |
| b. Comms | `MailboxHealthRecorder`, `CommsAccessGrantService` | |
| c. Misc | `MatchPropertyJob`, `SendAgentInviteJob`, `RcrDeadlineReminderJob`, `ImporterController`, `CheckLeaseExpiry` | Invites/onboarding may be *transactional* — see §6. |
| d. Presentations | 7 classes | Largest, most uniform. |
| e. Docuperfect / e-sign | `SignatureService`, `SalesDocumentController`, `SigningController`, `SendSignatureReminders` | ⚠️ **Touches the e-sign pipeline gate** (`scripts/dev-check.ps1`) — needs a test diff in `tests/Feature/Docuperfect/SigningView/`. Budget for it. **Coordinate with the e-sign lane.** |
| f. Contacts | `NotifyAgentOfClientTestimonial` | Fires `contact.testimonial_submitted`, which is **not in the catalogue** (C9) — register it. |
| g. Deals | `DealV2/NotificationService` | |

---

## 5. S3 — the calendar (R4)

Three engines can email about ONE calendar event, sharing **no dedup key**:
`CalendarReminderService` (AT-178) · `CalendarNotificationDispatcher` (03:00 colour transition) ·
`SendCalendarDigests` (06:30). Plus an in-app double-write.

**Decide which engine owns which fact, then fold the other two into the gateway.** Proposal:
reminders own "this event is coming up"; the digest owns "here is your day"; the colour transition
is an *escalation*, not a third reminder — it should reuse the reminder's dedup identity so it
cannot double-send.

`CalendarNotificationDispatcher` already honours the agency class config + the user's masters
(post-R2) but not the per-event preference or the ledger. Folding it into the gateway gives it both.

---

## 6. S4 — one ledger (R6)

Four idempotency mechanisms today, none shared:
`notification_dispatch_log` (gateway only) · `calendar_reminders_log` (AT-178) ·
`task.metadata->reminder_sent` (JSON flag) · a Cache key (`CheckLeaseExpiry`).

Nothing can answer *"have we already told this user this fact?"* across paths. Consolidate onto
`notification_dispatch_log`; the others either feed it or retire.

**Scope boundary — state it and hold it:** the gateway governs **notifications to CoreX users**. It
does **not** govern **mail to clients/contacts** (a signing request to a seller, a deal document to
a buyer). Those are transactional and correctly ungoverned by user preferences. Two *user-facing*
mail bypasses do sit outside the notification layer and must be brought in during S3/S4 —
`SendCalendarDigests` and `OversightDigestJob` — and are named in `NotificationGatewayGuardTest`
so the gap stays visible.

---

## 7. S5 — agency governance

`notification_event_types` has **no `agency_id` and no `is_active`** — so an agency admin cannot
disable a notification type at all, while calendar classes *are* fully agency-configurable. Two
governance models, opposite answers to "who owns this setting".

Add agency-level enable/disable, resolved in the gateway **between** the agency policy and the user
preference (agency narrows; user narrows further; **neither widens** — §2's invariant).

**CLAUDE.md #10a:** any new agency setting must reach the **Agency Onboarding Setup Wizard** in the
same prompt. A setting only on the settings page is not done.

---

## 8. Standing rules for this lane

1. **Failure-test everything.** A test that has never failed has not been tested. Run each new test
   against the un-fixed code — *and* isolate the control (a cooldown, cache or unique index will
   otherwise hold your test up and you will ship theatre). Assert your fixtures are real.
2. **The allow-list may only SHRINK.** Every migrated producer is deleted from
   `KNOWN_BYPASSES`. A stale entry is a hole.
3. **Reference data must travel** — new catalogue rows need a migration *and* the seeder
   (`deploy:sync-reference-data`), or a fresh environment gets an empty settings page (AT-162).
4. **Dual-deploy per stage** (qa1 + Staging). **Nothing to live** without Johan's explicit word.
5. **Channels are a ceiling, never a floor** (§2).
