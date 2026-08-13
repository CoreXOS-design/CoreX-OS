# Push Notifications — Dispatch Guards Spec

> Status: LIVE (2026-06-10)
> Pillars: Agent (User devices), Contact/Deal/Property (event subjects), Lead (portal fan-out)
> Owner: Andre

## Why this exists

A production user's handset was bricked by a **push-notification storm** —
the same push delivered repeatedly via FCM in a tight window, each one
vibrating and waking the screen until the phone locked up and the app had to
be uninstalled. The mobile client was hardened defensively, but the **root
cause is server-side**: the dispatch path could emit a flood in the first
place.

## Root cause (what actually shipped the storm)

Two code paths called the FCM transport (`FcmService::send`) **directly, with
zero guards**:

1. `App\Services\CommandCenter\NotificationDispatcher::sendPush()` (pillar alerts)
2. `App\Listeners\Leads\PushNewPortalLeadToMobile` (portal-lead fan-out) — the
   **primary surface**. It pushed to *every device of every user in the
   agency* on every `NewPortalLeadReceived` event, and the P24 leads poller
   (`PullP24LeadsJob`, **every 5 minutes**) could re-fire that event for the
   same lead (cursor that fails to advance, a dedup miss on the ±1-minute
   window, a re-import). Each re-fire = a fresh agency-wide push, unbounded.

Amplifier: **duplicate device-token rows**. `DeviceToken` is unique on
`(user_id, token)`, but token rotation and same-token-after-re-login left
multiple rows for one physical handset. The agency-wide `pluck('token')`
resolved the same token several times → several buzzes per event.

Mapping to the standard "common causes": the culprit was **"a fan-out that
resolves the same device token multiple times"** combined with **no
idempotency / no per-device cooldown / no bounded retry** on the transport.
It was *not* a model-observer save-back loop, and *not* an infinite FCM retry
(the old transport caught and swallowed errors).

## The fix — one guarded funnel

Every device push now flows through **`App\Services\Push\PushNotificationService`**.
The two call sites above delegate to it; `FcmService` is reduced to a dumb,
single-attempt transport behind the `PushTransport` contract (swapped for
`NullPushTransport` when no FCM creds are configured, so local/CI never crash).

`PushNotificationService::dispatch()` enforces, in order:

| Guard | Mechanism | Stops |
|-------|-----------|-------|
| **Token de-dup** | collapse to one row per distinct token string | one handset buzzing N× from duplicate rows |
| **Idempotency (key, token)** | atomic `Cache::add('push:idem:'…, ttl)` | the same logical push delivered twice to a device within `idempotency_ttl` |
| **Per-device rate cap** | atomic per-minute counter `push:rate:{token}:{minute}` | any flood (even distinct keys / genuine burst) — hard backstop |
| **Bounded retry + backoff** | retry loop, `max_attempts`, exponential `retry_base_ms` | infinite re-send on transient FCM failure |
| **Stale-token prune** | dead tokens from the report are `delete()`d, never retried | retrying a `NotRegistered`/`Invalid` token |
| **Per-user/min metrics** | `push:metric:user:{id}:{minute}` + warn over cap | invisibility of a future regression |

### Idempotency keys (stable per logical event, never random)

- Pillar alerts: `user:{id}|{eventKey}|{SubjectType}:{id}|{thresholdBucketYmdHi}`
- Portal leads: `portal_lead:{leadId}`

### Device-token hygiene (`DeviceTokenController::store`)

- One active row per physical device: registering a token **supersedes** any
  other user's active row for the same token (re-login on the same handset).
- Re-registering a soft-deleted `(user, token)` **revives** the row
  (`withTrashed` lookup) instead of INSERTing and crashing on the unique index.
- Registration is idempotent — N calls never create duplicate rows.

## Config — `config/push.php`

| Key | Env | Default | Meaning |
|-----|-----|---------|---------|
| `idempotency_ttl` | `PUSH_IDEMPOTENCY_TTL` | 300s | de-dup window per (key, device) |
| `rate_per_minute` | `PUSH_RATE_PER_MINUTE` | 5 | hard per-device cap (0 = off) |
| `max_attempts` | `PUSH_MAX_ATTEMPTS` | 3 | transient-failure retry cap |
| `retry_base_ms` | `PUSH_RETRY_BASE_MS` | 200 | backoff base (0 = no sleep, for tests) |

## Acceptance criteria (all proven by tests)

- One logical event fired N× → **exactly one** push per device. ✓
- Distinct events still each deliver once. ✓
- Duplicate token rows (cross-user / rotation) collapse to one send. ✓
- Per-device rate cap bounds a distinct-key flood. ✓
- Transient failure retries at most `max_attempts`, then stops. ✓
- Retry that later succeeds delivers once (no per-attempt duplication). ✓
- Dead tokens pruned, not retried. ✓
- Portal-lead re-fire buzzes each agency device once; other agencies untouched. ✓
- Device-token registration: idempotent, soft-delete revival, supersede-on-relogin. ✓

## Files

- `app/Services/Push/Contracts/PushTransport.php` — transport contract
- `app/Services/Push/PushNotificationService.php` — the guarded funnel
- `app/Services/Push/PushSendResult.php`, `PushDispatchSummary.php` — value objects
- `app/Services/Push/FcmService.php` — kreait adapter (single attempt)
- `app/Services/Push/NullPushTransport.php` — no-op fallback
- `config/push.php` — tunables; binding in `AppServiceProvider::register()`
- `app/Services/CommandCenter/NotificationDispatcher.php` — the ONE gateway call site
  (both pillar alerts and portal leads now flow through here — see
  `.ai/specs/portal-leads.md`'s "Mobile API" section; `PushNewPortalLeadToMobile`
  below is retired, kept in this list only as forwarding-pointer history)
- ~~`app/Listeners/Leads/PushNewPortalLeadToMobile.php`~~ — **RETIRED (AT-235 S2,
  2026-07-xx)**. File no longer exists. Portal-lead push is now one channel of
  the single `NewPortalLeadAgentNotification` sent via the gateway (targeted to
  the listing/co-listing/buyer's agent only, never agency-wide, and honours
  `notify_push`) — see `app/Listeners/Leads/EmailPortalLeadToAgent.php`.
- `app/Http/Controllers/Api/DeviceTokenController.php` — token hygiene
- `tests/Feature/Push/*`, `tests/Unit/Push/FcmServiceTest.php`, `tests/Support/SpyPushTransport.php`

## Android delivery gap found & fixed 2026-08-13 — no notification channel on the wire

Investigating "the mobile app never shows a push pop-up" (raised alongside the Portal
Leads visibility bug — see `.ai/specs/portal-leads.md`) surfaced two real gaps, split
across the two repos:

**Backend (this repo, fixed):** `FcmService::send()` built the FCM message with no
`android` config at all — no `notification.channel_id`. Without one, Android 8+ (which
requires every notification to belong to a channel) routes the push into FCM's
**auto-created fallback channel at default importance**, regardless of any
high-importance channel the app itself had defined — CoreX Mobile had one
(`corex_test`), but it was only ever used by the app's own local "send test
notification" button, never referenced by anything the server sent. Result: pushes
landed silently in the notification shade with no heads-up banner, sound, or vibration.
Fixed: every `FcmService::send()` call now sets
`android.notification.channel_id = 'corex_alerts'` (+ `notification_priority: PRIORITY_HIGH`,
`priority: high`, default sound/vibration). **`corex_alerts` is now a cross-repo
contract string** — the mobile app MUST create a real `AndroidNotificationChannel` with
this exact id at high importance at startup, or Android will still fall back silently.

**Also fixed in the same pass:** `sent` was computed as `count(tokens) - count(dead)`
(dead = FCM's `unknownTokens()` + `invalidTokens()` only) — so any OTHER per-message
failure (a transient auth error, quota, sender-ID mismatch) was silently counted as a
successful delivery. The push-storm postmortem above already established `sent` needs
to be trustworthy for the per-user metrics tripwire; a formula that can over-count
defeats that. Now `sent = $report->successes()->count()` — FCM's own per-message result,
not an inference from two specific failure buckets. `tests/Unit/Push/FcmServiceTest.php`
locks in both (mocks the kreait `Messaging` contract directly — no DB/HTTP, runs in
<0.1s).

**Mobile (NOT this repo — flagged, not fixed here):** even with the channel_id now on
the wire, Android only auto-displays it for background/terminated app states. The app's
foreground handler (`_onForegroundMessage`) only shows an in-app `MaterialBanner`, never
a real system notification via `flutter_local_notifications` — so a lead arriving while
the app is open produces no pop-up at all, by design of the current code, not a
transport failure. It also has several silent early-returns (missing nav context,
`localPushEnabled` off, outside the agent's open-hours window, any exception, 10s dedup,
a 6-per-60s rate cap) that can swallow even the banner. Separately, iOS push is entirely
unconfigured (no `Runner.entitlements`, no `aps-environment`, no `UIBackgroundModes:
remote-notification` in `Info.plist`) — `getToken()` fails silently on iOS with no
recovery path. Both need a mobile-repo prompt, not a backend fix.
