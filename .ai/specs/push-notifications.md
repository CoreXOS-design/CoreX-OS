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
- `app/Services/CommandCenter/NotificationDispatcher.php` — pillar call site
- `app/Listeners/Leads/PushNewPortalLeadToMobile.php` — portal-lead call site
- `app/Http/Controllers/Api/DeviceTokenController.php` — token hygiene
- `tests/Feature/Push/*`, `tests/Support/SpyPushTransport.php`
