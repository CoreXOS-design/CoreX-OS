# Mobile App Prompt — Agent Profile: FFC/Cell/WhatsApp/Socials + Preview My Agent Page

> Paste the section below into the Claude session running in the **mobile app repo**.
> The CoreX OS backend (routes + controller + tests) is built and tested on the `QA2` branch.
> Do not point this at Staging or live — check with Johan which environment/host to build
> and test against first.

---

## ▼▼▼ COPY-PASTE INTO MOBILE APP CLAUDE SESSION ▼▼▼

Add editable fields to the agent's own Profile screen — **FFC Number, Cell, WhatsApp,
Facebook Profile URL, Instagram Profile URL** — plus a **"Preview my agent page"** button.
The web app already edits all five fields on the agent's My Portal → Profile tab; this brings
mobile to parity. It's the exact same database row the website reads and writes — an edit made
on the phone shows on the website (and vice versa) on next load. No client-side merge logic
needed, just re-fetch.

Also: the existing "who am I" call the app already uses apparently doesn't show the signed-in
user's role anywhere in the UI — the backend now returns it (see below), so wire up display of
`role_label` wherever the profile screen currently shows the user's name/email but not their
role.

### Data shape

`GET /api/v1/mobile/profile` and `PATCH /api/v1/mobile/profile` both return:

```json
{
  "id": 5,
  "name": "Andre Agent",
  "email": "andre@example.com",
  "role": "agent",
  "role_label": "Agent",
  "cell": "0821234567",
  "whatsapp_number": "0821234567",
  "ffc_number": "FF123456",
  "website_social_facebook": "https://facebook.com/andre.agent",
  "website_social_instagram": "https://instagram.com/andre.agent",
  "public_profile_url": "https://qatesting2.corexos.co.za/corex/agents/andre/9kkpazukgy",
  "can_edit": true
}
```

Any of `cell`/`whatsapp_number`/`ffc_number`/`website_social_facebook`/`website_social_instagram`
can be empty/null except `cell`, which is always required. `public_profile_url` is the agent's
live, public "business card" page — the same URL already shown on web (see example above);
it always resolves, whether or not the agent has filled in the optional fields yet.

`can_edit` reflects the signed-in user's `edit_own_profile` permission. If `false`, render the
five fields **read-only** (no Save button) rather than letting the user submit and hit a 403.

### Endpoints (sanctum bearer token, same auth as every other mobile endpoint)

| Method | Path | Body | Returns |
|---|---|---|---|
| GET   | `/api/v1/mobile/profile` | — | profile object above |
| PATCH | `/api/v1/mobile/profile` | `{ cell: string, whatsapp_number?: string, ffc_number?: string, website_social_facebook?: string, website_social_instagram?: string }` | updated profile object |

Headers: `Authorization: Bearer <token>`, `Accept: application/json`.

- `422` — validation failed. `cell` is required; `whatsapp_number`, when present, must look
  like a South African mobile number (with or without `+27`/`0` prefix) — show the field
  error(s) inline, same as any other form on this app.
- `401` — token expired: trigger the existing re-login flow.
- `403` — the signed-in user doesn't hold `edit_own_profile` (shouldn't normally be reachable
  if you gate the Save button on `can_edit`, but handle it defensively with a toast).

### UI

On the existing **Profile screen**:

- Add input fields for **FFC Number**, **Cell** (marked required, e.g. with a `*`), **WhatsApp**,
  **Facebook Profile URL**, **Instagram Profile URL** — plain text inputs (WhatsApp/Cell can use
  a phone-shaped keyboard; the two social fields are plain URLs, no special picker).
- Load current values from `GET /api/v1/mobile/profile` on screen mount / pull-to-refresh.
- A **Save** button PATCHes the changed fields (or just resend all five — the endpoint isn't
  picky about unchanged values) and updates local state from the response.
- Add a **"Preview my agent page"** button/link that opens `public_profile_url` — either in an
  in-app browser (WebView) or the device's external browser, whichever this app's existing
  pattern uses elsewhere for external links. This is the agent's own live public profile page —
  what a client sees when they scan the agent's QR code or get sent their link.
- Show `role_label` somewhere on the profile screen header (e.g. under the agent's name) —
  this is the piece that was previously missing entirely.

### Sync rules (web ↔ mobile)

- Both clients write to the same `users` row via the same validated fields — no client-side
  merging needed.
- Re-fetch on screen mount and pull-to-refresh so a web-side edit shows up on the phone.

### Acceptance

- Editing FFC Number/Cell/WhatsApp/Facebook/Instagram on mobile and saving is visible on the
  web My Portal → Profile tab on next page load, and a web edit is visible on mobile after
  pull-to-refresh.
- Clearing Cell and hitting Save shows the required-field error and does not clear the
  previously-saved value server-side (the PATCH is simply rejected, 422).
- The role label now renders on the Profile screen.
- "Preview my agent page" opens the correct public URL for the signed-in agent (verify against
  the value returned in `public_profile_url`, not a hardcoded/derived string on the client).

### Files to look at / modify (typical mobile structure)

- API client module (where other `mobile/*` calls live) — add a `profile` service with
  `getProfile()` / `updateProfile(payload)`.
- Profile screen / view-model — add the five editable fields + role display + preview button.
- Reuse the existing token-storage / auth interceptor — do not roll new auth.

### Spec source of truth

Full backend spec: `.ai/specs/mobile-agent-profile.md` (in the CoreX OS backend repo). If
anything above conflicts with that file, that file wins.

## ▲▲▲ END COPY-PASTE ▲▲▲
