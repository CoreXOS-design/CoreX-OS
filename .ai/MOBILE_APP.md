# CoreX Mobile — App Status

> Flutter companion app for CoreX OS
> Last updated: 2026-03-12

---

## Project Location

`c:\Users\USER-PC\Documents\Projects\corex_mobile\`

---

## Tech Stack

| Item | Value |
|------|-------|
| Framework | Flutter (Dart SDK ^3.10.7) |
| State management | Provider (ChangeNotifier) |
| HTTP | `http` package |
| Token storage | `shared_preferences` |
| Fonts | Google Fonts (Inter) |
| API target | `http://91.99.130.85:8084/api` (staging) |
| Mock data | **OFF** — real API calls only |

---

## File Structure

```
lib/
├── main.dart                    — Entry point, CoreXApp, AuthGate
├── theme.dart                   — AppTheme (colours, geometry, dark theme)
├── providers/
│   └── auth_provider.dart       — Auth state (login, logout, checkAuth)
├── screens/
│   ├── login_screen.dart        — Login form (email/password)
│   └── home_screen.dart         — Empty home screen (greeting + logout)
├── services/
│   └── api_service.dart         — HTTP client (login, profile, properties endpoints)
└── widgets/
    ├── quick_action_card.dart   — UNUSED (was for old tab grid)
    └── property_list_tile.dart  — UNUSED (was for old properties tab)
```

---

## What's Built

### Authentication (working)
- Native login screen with email/password
- Calls `POST /api/login` on staging server
- Stores Sanctum Bearer token in SharedPreferences
- Auto-login on app restart via `checkAuth()` (reads stored token, fetches profile)
- Logout clears token and returns to login screen

### Home Screen (minimal)
- Shows time-based greeting ("Good morning/afternoon/evening")
- Shows authenticated user's name
- "CoreX OS" watermark centered
- Logout button at bottom

### Theme/Design System (done)
- Dark theme matching CoreX web app
- Background: `#0D0F14`, Surface: `#13161D`, Surface 2: `#1A1E28`
- Brand Blue: `#0EA5E9`, Brand Dark: `#0B2A4A`
- Text: Primary `#EEF0F5`, Secondary `#8890A4`, Muted `#545B6E`
- Border radius: 6px everywhere
- Font: Inter via google_fonts
- Inputs: surface fill, no border, focus ring in brand blue
- Buttons: brand blue, white text, semibold, full-width, 48px height

### API Service (done)
- Base URL: `http://91.99.130.85:8084/api`
- Bearer token sent in Authorization header
- Endpoints implemented: login, profile, properties (properties not used in UI currently)
- Mock data available but disabled

---

## What's NOT Built Yet

### Navigation
- No bottom navigation bar (removed intentionally — will be added back with custom tabs)
- No sidebar or drawer

### Screens needed
- Properties list
- Contacts list
- Deals list
- Agent profile/dashboard
- Document viewer
- Notifications

### Features needed
- Push notifications
- Offline support / data caching
- Pull-to-refresh patterns
- Image/photo upload (for properties, documents)
- Deep linking
- Biometric login (fingerprint/face)
- Light theme toggle (dark only for now)

### API endpoints needed on web app
- `GET /api/properties` — list properties (endpoint exists in api_service.dart but not in Laravel routes yet)
- `GET /api/contacts` — list contacts
- `GET /api/deals` — list deals
- `GET /api/notifications` — push notification feed
- Any other module-specific endpoints as screens are built

---

## Backend Requirements (Laravel side)

All API routes live in `routes/api.php`. See `.ai/API.md` for current endpoints.

Currently available:
- `POST /api/login` — Sanctum token auth
- `GET /api/profile` — authenticated user profile
- `POST /api/logout` — revoke token

New endpoints must be added to both:
1. `routes/api.php` (Laravel)
2. `lib/services/api_service.dart` (Flutter)
3. `.ai/API.md` (documentation)

---

## Unused Files (can be cleaned up)

These files remain from the old tabbed layout and are no longer imported:
- `lib/widgets/quick_action_card.dart`
- `lib/widgets/property_list_tile.dart`
- `lib/screens/properties_screen.dart` (if still exists)
- `lib/screens/profile_screen.dart` (if still exists)
