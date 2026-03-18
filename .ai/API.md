# CoreX OS — API Reference

All API routes are defined in `routes/api.php` and prefixed with `/api`.
Authentication uses **Laravel Sanctum** (Bearer token).

---

## Authentication

### POST `/api/login`
Authenticate a user and receive a Sanctum token.

**Auth required:** No

**Request body:**
```json
{
  "email": "user@hfcoastal.co.za",
  "password": "secret"
}
```

**Success response (200):**
```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "name": "Johan Smith",
    "email": "johan@hfcoastal.co.za",
    "branch": "Shelly Beach",
    "ffc_status": "Active"
  }
}
```

**Error response (422):**
```json
{
  "message": "The provided credentials are incorrect.",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

---

### POST `/api/logout`
Revoke the current access token.

**Auth required:** Yes (`Authorization: Bearer {token}`)

**Success response (200):**
```json
{
  "message": "Logged out"
}
```

---

## User

### GET `/api/profile`
Get the authenticated user's profile.

**Auth required:** Yes (`Authorization: Bearer {token}`)

**Success response (200):**
```json
{
  "id": 1,
  "name": "Johan Smith",
  "email": "johan@hfcoastal.co.za",
  "branch": "Shelly Beach",
  "ffc_status": "Active"
}
```

---

## Technical Notes

- **Package:** Laravel Sanctum v4.3 (`laravel/sanctum`)
- **Token storage:** `personal_access_tokens` table (migration `2026_03_12`)
- **User model:** `HasApiTokens` trait added
- **Routes registered in:** `bootstrap/app.php` via `api:` parameter
- **Token name:** `corex-mobile` (used when creating tokens via login)
- **All authenticated endpoints** return 401 if token is missing/invalid
