# Spec: Ellie — Tour Knowledge ("How do I do X")

**Status:** Live · Owner: Andre · Related: `.ai/specs/ellie.md`, `.ai/specs/ellie-navigation-atlas.md`

---

## What this feature does and why

The Navigation Atlas told Ellie *where* a page is. It could not tell her *how* to do
the task once there — e.g. "how do I get a buyer onto the buyer pipeline?" got a
"look for a button, ask your manager" non-answer.

CoreX already has 88 guided tours (`app/Support/Tours`) — ordered, plain-language,
agent-facing walkthroughs of real features (373 steps total). Each tour is exactly
the step-by-step "how" Ellie lacked. This feature reads that catalogue live and feeds
the matching walkthrough into Ellie's answer.

Example: the `buyer-pipeline` tour already states *"Buyers land here automatically when
you capture what they're looking for on their contact"* — the real answer to the
question above.

**Why read the registry live (not ingest into the KB):** tours are code and change with
the product. A live service is never stale and needs no re-ingest. It also needs no
embeddings, so it works locally/offline like the Navigation Atlas.

## Pillars

Cross-cutting AI surface. Reads tour definitions + route/permission metadata (Agent
pillar via `User::hasPermission` / `TourRegistry::visibleTo`). Writes nothing.

## Files created / modified

- **`app/Services/AI/TourKnowledgeService.php`** (new) — `search()` (keyword scoring
  over title/description/steps + `TourRegistry::visibleTo` gate + dominance filter) and
  `buildContext()` (formats the steps + a link for Ellie).
- **`app/Services/AI/NavigationAtlasService.php`** — new public `urlIfAccessible()` reused
  for permission-safe link resolution.
- **`app/Http/Controllers/EllieController.php`** — injects tour how-to context
  (`ELLIE_TOUR_KNOWLEDGE_2026`).
- **`tests/Feature/AI/TourKnowledgeServiceTest.php`** (new).

## User flow

1. Agent asks Ellie "how do I get a buyer on the pipeline?"
2. `EllieController@send` calls `TourKnowledgeService::buildContext`.
3. It scores the 88 tours, drops any the user can't see, keeps the dominant match, and
   returns its ordered steps + a link.
4. The block is appended to Ellie's knowledge context; she explains the actual steps.

## Permissions

No new keys. Gating is `TourRegistry::visibleTo($tour, $user)` — the tours' own standard
(owner bypass, explicit tour `permission`, else inherit the route gate). The optional
link is resolved through `NavigationAtlasService::urlIfAccessible` (paramless + permitted),
so a tour whose page needs a parameter or is off-limits still yields its steps but no link.

## How to make it smarter (operator playbook)

Add or improve a guided tour in `app/Support/Tours/defs/*.php` — the tour instantly
becomes (a) an in-app walkthrough AND (b) Ellie how-to knowledge. No re-ingest, no
Python deploy, just a `git pull` + php-fpm reload.

## Acceptance criteria

- [x] "How do I get a buyer on the buyer pipeline?" returns the buyer-pipeline steps
      incl. the "buyers land automatically" explanation, with the pipeline link.
- [x] Tours the user cannot see (`visibleTo` false) are excluded.
- [x] Non-workflow questions (e.g. "prime lending rate") return nothing.
- [x] Works with no OpenAI key (keyword scoring; no embeddings).
- [x] Covered by `tests/Feature/AI/TourKnowledgeServiceTest.php`.

## Future

- Rank the tour for the user's current module higher (Ellie already gets app context).
- Blend Navigation Atlas (where) + Tour Knowledge (how) into a single "guide me" answer.
