# Spec: Ellie — Navigation Atlas ("Where do I go to…")

**Status:** Live · Owner: Andre · Related: `.ai/specs/ellie.md`, `.ai/atlas/ai-tools-cost-ledger.md`

---

## What this feature does and why

Ellie could answer "how does X work" from the knowledge base but was weak at the
most common agent question: **"Where do I go to do X?"** She had no map of the live
application — her training docs only linked to help articles (`/corex/training-help/…`),
never to the actual feature page (e.g. the presentation generator).

The Navigation Atlas gives Ellie a curated, permission-aware directory of every
user-facing destination in CoreX. Ask *"where do I go to do a new presentation for a
property?"* and Ellie replies with the destination **and a working link**
(`/presentations/create`), never pointing a user at a page their role cannot open.

**Why a curated registry keyed by route name (not auto-scan, not hardcoded URLs):**
- The route table has hundreds of action routes; a curated list keeps quality high.
- Keying by route **name** means the live URL is resolved at runtime — rename a URL
  and the atlas follows. Nothing goes stale (Golden Rule: fix root causes).
- The required permission is derived at runtime from each route's `permission:`
  middleware, so the registry never duplicates permission keys and can't drift out
  of sync with the middleware.

## Pillars

Cross-cutting AI surface. Reads route/permission metadata (Agent pillar via
`User::hasPermission`). Writes nothing — Ellie advises, humans decide.

## Data model / migrations

None. The registry is `config/corex-navigation-atlas.php`.

## Files created / modified

- **`config/corex-navigation-atlas.php`** (new) — destination registry. Each entry
  is keyed by route name with `label`, `category`, `blurb`, `keywords`.
- **`app/Services/AI/NavigationAtlasService.php`** (new) — `isNavigationQuery()`,
  `search()` (keyword/synonym scoring + permission filter + live URL resolution),
  `buildContext()` (formats excerpts + sources for Ellie).
- **`app/Http/Controllers/EllieController.php`** — injects navigation context into
  the model's knowledge context when the message is a navigation query
  (`ELLIE_NAVIGATION_ATLAS_2026`).
- **`services/hf-ai/app.py`** — knowledge-context instruction extended to surface
  `Direct link:` URLs verbatim.
- **`.ai/docs/training/11_NAVIGATION_MAP.md`** (new) — plain-language "where do I go"
  reference; ingested via `php artisan training:ingest` as reinforcement/fallback.

## User flow

1. Agent asks Ellie "where do I go to create a presentation?"
2. `EllieController@send` detects navigation intent (`NavigationAtlasService::isNavigationQuery`).
3. `buildContext()` scores the registry, resolves live URLs, drops any destination the
   user lacks permission for, and returns the top 3 as excerpt blocks with `Direct link:`.
4. The blocks are prepended to the knowledge context sent to the Ellie model (port 3100).
5. Ellie replies conversationally with the destination and the exact link.

## Permissions

No new permission keys. Access is enforced *per destination* by reading the route's
existing `permission:<key>` middleware and checking `User::hasPermission($key)`.

## How Ellie's knowledge is extended (operator playbook)

Two levers, both low-risk and additive:
1. **Add/expand destinations** — edit `config/corex-navigation-atlas.php`. Add
   synonyms to `keywords` (the cheapest way to improve match quality). No deploy of
   the Python service needed; it's pure Laravel config.
2. **Refresh the training reference** — after editing
   `.ai/docs/training/11_NAVIGATION_MAP.md`, run `php artisan training:ingest --force`
   on the server (requires `OPENAI_API_KEY` in `/corex/.env` for embeddings; falls back
   to keyword matching without it).

## Acceptance criteria

- [x] "Where do I go to create a presentation?" surfaces `/presentations/create`.
- [x] Destinations the user lacks permission for are excluded from results.
- [x] Routes requiring parameters are never emitted (no broken links).
- [x] URLs are resolved live from the route table (rename-safe).
- [x] Works with no OpenAI key (keyword scoring; no embeddings required).
- [x] Non-navigation questions are unaffected (KB search path unchanged).
- [x] Covered by `tests/Feature/AI/NavigationAtlasServiceTest.php`.

## Future

- Context-aware ranking: boost destinations related to the module the user is
  currently in (Ellie already receives app context).
- Auto-audit: a test/command that flags atlas route names missing from the route
  table so the registry can't rot.
