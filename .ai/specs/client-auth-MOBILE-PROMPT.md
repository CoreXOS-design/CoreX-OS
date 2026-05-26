# Mobile App Prompt — Client Login & Client Portal

> Paste the section below into the Claude session running in the **mobile app repo**.
> The CoreX OS web side (DB migrations + 11 API endpoints + admin UI) is built
> on the `andre` branch and ready to be merged to `main`.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Add a brand-new **Client side** to the CoreX mobile app, alongside the existing User/Agent side. Clients (buyers/sellers/tenants/landlords stored as `Contact` rows in CoreX) sign in with their email, get one OTP for activation, set a password, and from then on log in with email + password. Once signed in they see their **Core Matches** for the agency they pick.

Backend base URL: `https://corex.hfcoastal.co.za`. Spec on the web side: `.ai/specs/client-auth.md`.

### Login screen redesign

The current login screen becomes **two paths**, side-by-side or as a segmented control at the top:

- **User Login** (label this clearly) — the existing flow you already have. Unchanged.
- **Client Login** — the new flow defined below.

The user picks one. Persist the last-used path so on next launch the app opens to whichever they used last.

### Client login flow

**Screen 1 — Email entry**
- Single email input + "Continue" button.
- POST `/api/v1/client-auth/lookup` with `{ "email": "<value>" }`.
- Response shapes:
  ```json
  // Not found
  { "exists": false, "requires_otp": false, "requires_password": false,
    "message": "You are not on any agency contact list. Ask your agent to add you.",
    "agencies": [] }

  // Found, no password set yet → OTP path
  { "exists": true, "requires_otp": true, "requires_password": false,
    "must_change_password": false,
    "agencies": [{ "id": 1, "name": "Home Finders Coastal", "slug": "hfc" }] }

  // Found, password already set → password path
  { "exists": true, "requires_otp": false, "requires_password": true,
    "must_change_password": false,
    "agencies": [...] }
  ```
- If `exists === false` → show the message inline, leave the user on Screen 1.
- If `requires_otp === true` → push to **Screen 2 (OTP send/verify)**.
- If `requires_password === true` → push to **Screen 4 (Password login)**.

**Screen 2 — Send + enter OTP**
- On entry: POST `/api/v1/client-auth/otp/send` with `{ "email": "<value>" }`. Response: `{ "sent": true, "expires_in_min": 10 }`. 429 → "Please wait" toast.
- Show a 6-digit code input + "Verify" button + "Resend code" link (disabled for 60s).
- "Verify" → POST `/api/v1/client-auth/otp/verify` with `{ "email": "<value>", "code": "123456" }`.
- Success response:
  ```json
  { "activation_token": "<short-lived bearer>", "email": "...", "expires_in_min": 15 }
  ```
- Hold the activation token in memory only (do NOT persist). Push to **Screen 3**.
- 422 → "Invalid or expired code" inline error, stay on screen.

**Screen 3 — Set password**
- Password + confirm password fields (8 chars min, soft validation).
- "Create Password & Sign In" → POST `/api/v1/client-auth/password/set` with header `Authorization: Bearer <activation_token>` and body:
  ```json
  { "password": "...", "password_confirmation": "...", "device_name": "iPhone 15 — André" }
  ```
- Response:
  ```json
  {
    "token": "<long-lived sanctum bearer>",
    "agencies": [{ "id":1, "name":"...", "slug":"hfc", "is_preferred":false, "is_locked":false }],
    "client": { "id":7, "email":"...", "has_password":true, "password_must_change":false,
                "preferred_agency_id":null, "locked_to_agency_id":null, "current_agency_id":null }
  }
  ```
- Persist `token` in secure storage (Keychain / KeyStore). This is the session token.
- If `agencies.length > 1` → push to **Screen 5 (Agency picker)**. Else stash `client.current_agency_id` (or first agency) and proceed to **Client Home**.

**Screen 4 — Password login (returning client)**
- Password input + "Sign In" + small "Forgot password?" link.
- "Sign In" → POST `/api/v1/client-auth/login` with:
  ```json
  { "email": "...", "password": "...", "device_name": "iPhone 15 — André" }
  ```
- Success response (same shape as `/password/set` response, plus `must_change_password: bool`).
- Persist token. If `must_change_password === true` → push to **Screen 3 (Set password)** with the long-lived token in `Authorization: Bearer` (the endpoint accepts both activation token AND a `client` token while `password_must_change` is true).
- Otherwise: if `current_agency_id` came back set → straight to home. If multiple agencies and no current → **Screen 5**. If single agency → home.
- 422 → "Invalid credentials" inline.
- "Forgot password?" → POST `/api/v1/client-auth/password/forgot` with `{ "email": "..." }`. If 422 with the message *"This account uses an agent-managed login..."* — show it (means their email is a fake `@corexclient.co.za` and they need their agent). Otherwise show "Code sent" and route them to **Screen 2** with `purpose: "recovery"`.

**Screen 5 — Agency picker**
- List of agencies from the previous response. Tap to select.
- For each row a star icon (favourite — visual only here) and a "Only use this agency" checkbox at the bottom.
- "Continue" → POST `/api/v1/client-auth/agency/select` with header `Authorization: Bearer <token>` and body:
  ```json
  { "agency_id": 1, "lock": true, "favourite": false }
  ```
- Response gives the updated `client` + agencies. Proceed to home.
- Show this screen on every login UNTIL the client has set `lock: true`. After that, `/login` returns the locked agency directly and Screen 5 is skipped.

### Client Home (post-login)

- Top bar: agency name (tap to re-open Screen 5 if the client has more than one agency), gear icon, sign-out icon.
- Content for v1: **Core Matches** list.
- GET `/api/v1/client/me` on app open / pull-to-refresh — returns:
  ```json
  {
    "client": { "id":7, "email":"...", "has_password":true, "password_must_change":false,
                "preferred_agency_id":1, "locked_to_agency_id":1, "current_agency_id":1,
                "last_login_at":"2026-05-09T14:22:11+02:00" },
    "agencies": [...],
    "contact": { "id":42, "first_name":"Andre", "last_name":"Roets",
                 "full_name":"Andre Roets", "email":"...", "phone":"...", "agency_id":1 }
  }
  ```
- GET `/api/v1/client/matches` — returns:
  ```json
  {
    "agency_id": 1,
    "matches": [
      {
        "id": 88, "status": "active", "listing_type": "sale",
        "created_at": "2026-04-30T...",
        "result_count": 12,
        "results": [
          { "id":501, "address":"12 Beach Rd, Shelly Beach", "suburb":"Shelly Beach",
            "beds":3, "baths":2, "price":1850000, "thumbnail":"https://..." }
        ]
      }
    ]
  }
  ```
- 409 from `/matches` means no agency selected yet — bounce back to Screen 5.
- Render each match as a card with the count badge + first 3 result thumbnails. Tap → match detail (full results list).

### Settings screen (gear icon)

Three rows:

1. **Change password** → POST `/api/v1/client-auth/password/change` with `{ "current_password":"...", "password":"...", "password_confirmation":"..." }`.
2. **Switch agency** → re-show Screen 5.
3. **Sign out** → POST `/api/v1/client-auth/logout` then clear token from secure storage and pop to login screen.

### Auth header + token rules

- All `/api/v1/client/*` and most `/api/v1/client-auth/*` endpoints require `Authorization: Bearer <token>` and `Accept: application/json`.
- The token returned by `/login` and `/password/set` is the long-lived session token. Sliding 30-day expiry — assume it stays valid as long as it's used.
- 401 on any client endpoint → token expired or revoked. Clear secure storage, kick user back to **Screen 1 (email entry)**, show toast "Please sign in again."
- 423 on any client endpoint → `password_must_change` is true. Push to **Screen 3** with current bearer token. Only `/me`, `/password/set`, `/password/change`, `/logout` will work until the password is changed.
- 429 → "Too many requests. Please slow down." toast.
- 403 → "Client app token required." (means an agent token was used by mistake).

### Edge cases to handle

- Client enters an email that has both real and fake variants → server treats fake `@corexclient.co.za` specially (no email is sent for OTP, no recovery). UI doesn't need to know — just react to the status code.
- Client launches app while offline + has a saved token → show home shell; queue `/me` and `/matches` for when network returns.
- Client uninstalls and reinstalls → token is gone, they re-enter password on Screen 4. No new OTP needed.
- Agent created the client's login (Screen 4 path with `must_change_password=true` returned) → after `/login`, immediately push **Screen 3** before showing home.

### Out of scope for v1

- Push notifications to clients
- Biometric (FaceID / fingerprint) unlock — store the token in secure storage now so this is a v2 add-on
- Client viewing deals, documents, FICA — all separate specs later
- WhatsApp / SMS OTP channels — email only for now
- Saved-search alerts

### Acceptance criteria (mobile)

1. From a fresh install, a real-email contact can: enter email → receive OTP → set password → land on Core Matches list within 2 minutes.
2. From a fresh install, an agent-created (`@corexclient.co.za`) client can: enter email → enter agent's temp password → forced password change → land on Core Matches.
3. After sign-in, killing and reopening the app lands directly on Core Matches (no re-auth) for at least 30 days, as long as the client uses the app every now and then.
4. A client whose email is on two agencies sees the picker on first login. Choosing "Only use this agency" makes future logins skip the picker.
5. Sign-out clears the saved token and bounces to the User/Client choice screen.
6. 401 anywhere mid-session bounces to the email-entry screen with a toast (no crash, no white screen).
7. The User Login path is byte-for-byte unchanged — same endpoints, same UX. Only the entry point on the launch screen changes.

### Files (mobile-side, suggested layout)

```
src/screens/auth/
  LoginChoiceScreen          // user vs client toggle
  user/                      // existing screens, untouched
  client/
    ClientEmailScreen        // Screen 1
    ClientOtpScreen          // Screen 2 (also used for recovery)
    ClientSetPasswordScreen  // Screen 3
    ClientPasswordScreen     // Screen 4
    ClientAgencyPickerScreen // Screen 5
src/screens/client/
  ClientHomeScreen           // Core Matches list
  ClientMatchDetailScreen
  ClientSettingsScreen
src/services/clientAuthApi.ts // wrapper around all 11 endpoints
src/store/clientSession.ts    // token + current client + current agency
```

When done, post a short note in the PR with: which paths persist the token, what happens on 401, and how you handle the multi-agency picker. Don't over-engineer — match the existing app's styling and idioms.
