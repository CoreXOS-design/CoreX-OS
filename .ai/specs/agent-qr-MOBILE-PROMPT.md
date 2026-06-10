# Mobile prompt — Agent QR Onboarding

Paste this into Claude Code in the CoreX mobile-app repo. The backend (CoreX OS on Laravel) already ships the endpoints described below — do not change them.

---

## Feature

Add a "Scan agent QR" flow to the Client Login screen. A prospect scans an agent's QR code → fills in a short form → instantly becomes a client of that agent and is logged in.

## Backend API (already live)

Base URL: same host as the existing client-auth endpoints (e.g. `https://corex.hfcoastal.co.za/api/v1`).

### 1. Preview the agent (after scan)
```
GET /api/v1/client-auth/agent-qr/{slug}
```
No auth. Returns 404 if slug unknown / agent inactive.

**200 response:**
```json
{
  "agent": {
    "first_name": "André",
    "last_name":  "Roets",
    "full_name":  "André Roets",
    "photo_url":  "https://corex.hfcoastal.co.za/storage/users/photos/12.jpg",
    "agency":     { "id": 1, "name": "Home Finders Coastal", "slug": "home-finders-coastal" }
  }
}
```

### 2. Register + log in
```
POST /api/v1/client-auth/agent-qr/{slug}/register
```
No auth. Rate-limited 5/hour per slug per IP.

**Request body:**
```json
{
  "first_name":            "John",
  "last_name":             "Smith",
  "phone":                 "+27 82 123 4567",
  "email":                 "john@example.com",
  "password":              "MyPass123!",
  "password_confirmation": "MyPass123!",
  "device_name":           "iPhone 15 — John"
}
```

**201 response (new client):**
```json
{
  "existing":    false,
  "token":       "1|abcdef...sanctum-token...",
  "agent":       { ...same as GET... },
  "agency":      { "id": 1, "name": "Home Finders Coastal", "slug": "home-finders-coastal" },
  "contact":     { "id": 4521 },
  "client_user": { "id": 88, "email": "john@example.com" }
}
```

**200 response (email already a CoreX client — no new password set):**
```json
{
  "existing": true,
  "message":  "Account already exists — signed in with your existing CoreX credentials and linked to this agent.",
  "token":    "...",
  "agent":    { ... }, "agency": { ... }, "contact": { ... }, "client_user": { ... }
}
```

Treat the returned `token` exactly like the token from `POST /client-auth/login` — store it in secure storage, set `Authorization: Bearer {token}` on subsequent requests, the `client` ability is already set.

## QR code format (updated 2026-06-10)
The QR encodes a canonical, human-readable public-profile URL of the form:
```
https://corexos.co.za/corex/agents/{name-slug}/{slug}
```
Where `{slug}` (the **last** path segment) is 10 lowercase alphanumeric chars
(Crockford-ish alphabet, no `0/o/1/i/l`) and `{name-slug}` is a cosmetic name
slug you can ignore. Extract `{slug}` from the URL path (last segment) — don't
open the URL in a browser, just parse it.

Match either pattern (both resolve the same slug):
- New: `…/corex/agents/{name-slug}/{slug}`
- Legacy (pre-2026-06-10 printed cards): `…/r/a/{slug}`

If the scanned payload is not a CoreX agent URL on the CoreX host, or the
extracted slug doesn't match `/^[a-z0-9]{6,16}$/`, show "Not a CoreX agent QR
code" and stay on the scanner.

> Note: opened in a normal browser (no app), the same URL now shows the agent's
> public profile page instead of the old 404 — so a card scanned without the app
> still does something useful.

## UX

### A. Login screen change
Add a third action below "User Login" / "Client Login":
- **[Scan agent QR]** button — opens the camera scanner.

### B. Scanner screen
- Full-screen camera with crosshair overlay
- Cancel button top-left
- On successful scan → push the "Sign up with agent" screen, passing the slug

### C. Sign-up with agent screen
- Header: agent photo + "You're signing up with **{agent.full_name}**" + agency name underneath
- (call `GET /agent-qr/{slug}` immediately on mount to populate this header; show a skeleton until it loads; on 404 → toast + pop back)
- Form:
  - First name *
  - Surname *
  - Cell phone
  - Email *
  - Password * (min 8)
  - Confirm password *
- Submit → `POST /agent-qr/{slug}/register`
  - 201 / 200 (`existing:true` or `false`) → save token, route to Client home in that agency
  - 422 → field errors
  - 429 → "Too many sign-ups from this device, try again later"
  - other → generic error

### D. After success
Save the Sanctum token to secure storage (same key the normal client-login flow uses). Route directly to the Client home screen — no agency picker needed, the agent's agency is the only one in scope. (Subsequent logins use email + password through the normal `/client-auth/login` flow.)

## Acceptance
1. A QR pointing to a real agent slug routes through scanner → preview header → form → logged-in home.
2. Unknown slug shows a friendly error and returns to scanner.
3. A QR that isn't a CoreX URL is rejected without an API call.
4. Existing-email path still lands the user logged-in (using `existing: true` branch), with a small toast: "Welcome back — also linked to {agent.full_name}."
5. Rate-limit 429 shows the right copy and doesn't crash.
6. Form validation matches the server (min 8 password, confirmation, valid email).
7. The flow can be cancelled at any step.

## Out of scope
- Web fallback (the QR URL deliberately 404s in a browser for v1 — no app-install landing yet).
- Agent-side analytics ("X people scanned my QR this week") — separate spec.
