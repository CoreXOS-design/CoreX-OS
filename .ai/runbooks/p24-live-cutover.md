# Property24 Live Syndication — Cutover Runbook

> Last updated: 2026-06-25 (Andre) — env cutover done; **blocked on P24 IP whitelist**.

## Status

| Item | State |
|------|-------|
| Env pointed at live | ✅ Done |
| Sandbox flag off | ✅ Done |
| Live credentials authenticate | ✅ Confirmed (auth gate passes) |
| Live smoke test returns 200 | ⛔ **Blocked** — P24 WAF is IP-blocking the server |

**Remaining action is entirely on P24's side: whitelist server IP `91.99.130.85`.**
Nothing left to change in CoreX.

## Architecture (how P24 syndication is wired)

Two layers, different homes — see `app/Services/Syndication/Property24/Property24ApiClient.php`:

- **Per-agency credentials → DB** (`agencies` table): `p24_username`, `p24_password`
  (encrypted), `p24_agency_id`, `p24_user_group_id`, `p24_enabled`. Set via
  Admin → Agency. The client prefers these; env is only a fallback when an
  agency row has no creds.
- **Base URL / sandbox flag / version → global env / config**
  (`config/services.php` → `property24_syndication`). Shared across all tenants
  on the install. `api_version` is hardcoded `v53` in config.

## The env change (live server `.env`)

```bash
# Property24 syndication — LIVE
P24_EXDEV_API_URL=https://api.property24.com
P24_EXDEV_SANDBOX=false
P24_EXDEV_IMAGE_BASE_URL=https://corex.hfcoastal.co.za   # must match APP_URL / host that serves /storage images

# Clear the env credential fallback so a creds-less agency can't fall back to sandbox creds against live:
P24_EXDEV_USERNAME=
P24_EXDEV_PASSWORD=
P24_EXDEV_AGENCY_ID=
```

After editing: `php artisan config:clear` (and `config:cache` if config is cached).

Live credentials go on the **agency row** (Admin → Agency), not env.

## Verify (run on the live server)

Authenticated smoke test via the client:

```bash
php artisan tinker --execute='echo json_encode((new App\Services\Syndication\Property24\Property24ApiClient(App\Models\Agency::where("p24_enabled",true)->whereNotNull("p24_username")->first()))->smokeTest()).PHP_EOL;'
```

- `200 / success:true` → fully live.
- `503` → see diagnosis below.

## Diagnosis log — 2026-06-25 503 investigation

Authenticated smoke test returned **HTTP 503** ("Server is temporarily
unavailable, usually due to high load or maintenance") repeatedly.

Raw curl isolation (bypassing CoreX) found the cause in the response headers —
P24's traffic-defense layer (`X-TD-*`):

| Source IP | Auth | `x-td-result` | Result |
|-----------|------|---------------|--------|
| Dev laptop | none | `Allowed` | 401 (expected) |
| Server `91.99.130.85` | none | **`Blocked`** | 503 |
| Server `91.99.130.85` | live creds | **`Blocked`** | 503 |

Auth is irrelevant — the block is purely the **source IP**. The env cutover is
correct and credentials are valid; **P24 is IP-blocking `91.99.130.85`** at the
edge before the request is processed. The 503 body is the generic page P24
serves for a blocked connection, not a real outage.

Server outbound IP (confirm before escalating — it can change):
```bash
curl -sS https://ifconfig.me; echo
```

## Escalation — message to P24 Take-On team

> Our live Listing Service v53 calls from server IP **91.99.130.85** are being
> blocked at your traffic-defense layer — responses carry **`X-TD-Result: Blocked`**
> and HTTP **503**. The same endpoint from a different IP returns
> `X-TD-Result: Allowed` / 401 as expected, and our credentials are valid.
> Please **whitelist `91.99.130.85`** for the live Property24 Listing Service for
> Home Finders Coastal.

## When P24 confirms the whitelist

1. Re-run the authenticated smoke test above — expect `200 / success:true`.
2. Only after a 200: proceed with real listing syndication.
3. If still 503, re-check the server IP (`ifconfig.me`) hasn't changed and
   re-confirm with P24.
