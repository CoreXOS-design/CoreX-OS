# Spec: Property Image Recognition — Auto-Detect Features

**Status:** Draft — awaiting approval
**Author:** Andre (drafted via Claude)
**Date:** 2026-05-28

---

## What this feature does and why

On property photo upload (mobile or web), each image is sent to **Claude Haiku 4.5 vision** with the existing canonical property feature vocabulary. Detected features are returned with confidence scores. The agent sees the property's feature checklist with AI-suggested boxes **pre-ticked + AI-badged**, and confirms before save.

**Business reason:** Listing agents currently tick the feature checklist manually after photo upload — slow and inconsistent. Auto-detection makes feature data cleaner and faster, which directly improves [[mobile-core-matches]] quality and listing portal exports.

---

## Pillar connections

| Pillar | Read | Write |
|---|---|---|
| Property | Existing photos, current `features_json` | Updated `features_json` (agent-confirmed AI suggestions merged in) |
| Contact | — | — |
| Agent (User) | Who uploaded; audit `features_json_meta` | — |
| Deal | — | — |

---

## Vocabulary — single source of truth

**No new feature list.** Vision uses the EXISTING canonical lists:

1. **Feature booleans** — `App\Http\Controllers\CoreX\ContactMatchController::FEATURE_OPTIONS` (14 items: pool, furnished, pet_friendly, garden, sea_view, security, garage, fibre, solar, air_conditioning, study, granny_flat, balcony, borehole).
2. **Space types** — `_ALL_SPACE_TYPES` in [show.blade.php:4949](resources/views/corex/properties/show.blade.php#L4949) (~50 room types).

The system prompt to Claude Haiku injects both lists verbatim so the model returns only tokens that already exist in CoreX. No vocab drift, no orphan tags.

---

## Architecture

```
Mobile/Web upload  ──[image]──►  POST /api/mobile/properties/{id}/images
                                          │
                                          ├──► store image (existing flow, unchanged)
                                          │
                                          └──► dispatch AnalysePropertyImageJob (queued)
                                                    │
                                                    └──► Claude Haiku 4.5 vision
                                                            system prompt: "List which of these features/spaces appear in this image. Return JSON: {features:[{token, confidence}], spaces:[{token, confidence}]}. Only use tokens from the provided lists."
                                                            │
                                                            └──► writes results to property_image_analyses table
                                                                  + appends `ai_suggested_features` to property meta (additive, never overwrites agent input)

Frontend  ──[poll]──►  GET /api/mobile/properties/{id}/ai-suggestions
                          ← { features:[{token, confidence, source_image_id}], spaces:[...] }

Agent reviews checklist with pre-ticked boxes + AI badge → confirms → POST merges into features_json
```

---

## Data model / migrations

**Migration 1 — `create_property_image_analyses`:**
```
property_image_analyses
  id, agency_id (multi-tenancy), property_id, property_image_id,
  status enum('queued','processing','complete','failed'),
  detected_features json     -- [{token, confidence}]
  detected_spaces   json     -- [{token, confidence}]
  raw_response      json     -- full Claude response for debugging
  cost_usd          decimal(8,5) nullable
  error             text nullable
  processed_at      timestamp nullable
  created_at, updated_at
```

**Migration 2 — `add_ai_meta_to_properties`:**
- `features_json_meta` json nullable — `{ "pool": {"source": "ai", "confidence": 0.92, "confirmed_by_user_id": 5, "confirmed_at": "..."}, "garden": {"source": "manual"} }`

No change to `features_json` shape — backward-compatible. The meta column is additive audit data.

---

## UI placement and navigation entry

- **Upload UI:** existing property edit / wizard — no nav change. After image upload completes, a new section appears: *"AI suggestions from your photos"* with the checklist of detected features pre-ticked + AI badge on each.
- **Per-image preview:** clicking an AI-suggested feature shows which photo(s) triggered it (small thumbnail strip).
- **Existing manual checklist:** unchanged in behaviour. Agent can untick AI suggestions, tick anything missed. On save, every box is stamped `source: ai|manual` in `features_json_meta`.
- **Sidebar entry:** none new — feature is embedded in existing property edit flow.

---

## User flow

1. Agent on mobile opens a property, hits *Add Photos*, picks 25 images, uploads.
2. Existing upload flow stores files; for each new image, `AnalysePropertyImageJob` is dispatched to the queue.
3. Mobile shows upload progress, then *"AI is analysing your photos… (12/25)"* via polling.
4. Once all 25 complete, the *AI suggestions* section appears below the gallery:
   - **Features:** ☑ Pool · ☑ Sea view · ☑ Garden · ☑ Garage (each with a small "AI" badge and a confidence pip)
   - **Spaces detected:** 3× Bedroom, 2× Bathroom, 1× Kitchen, 1× Pool, 1× Patio (advisory only — spaces still entered via existing spaces UI)
5. Agent reviews, unticks anything wrong, ticks anything missed, hits *Save*.
6. `features_json` is updated; `features_json_meta` records `source/confidence/confirmed_by` per feature.
7. AI badge remains visible next to AI-sourced features on the property show page, so anyone reviewing the listing knows which fields are human-confirmed-from-AI vs human-entered.

---

## Permissions

- New permission key: `use_property_image_ai` in `CoreXPermissionSeeder.php`
- Default-granted to roles with `properties.edit`
- Controller gate on the suggestions endpoint and the merge endpoint
- If permission absent, upload works as today; no AI section renders

---

## Acceptance criteria

1. Uploading 25 photos triggers 25 queued vision jobs; all complete within 90 seconds on the production queue worker.
2. AI suggestions section appears below the gallery with detected features pre-ticked, confidence pip, AI badge.
3. Saving the property writes `features_json` (existing shape, unchanged) AND `features_json_meta` (new audit shape) — both round-trip correctly through edit→save→reload.
4. Property show page renders an AI badge next to features whose `features_json_meta[feature].source = 'ai'`.
5. Vision tokens NEVER include strings outside the canonical `FEATURE_OPTIONS` + `_ALL_SPACE_TYPES` lists.
6. Cost per analysed image is logged on `property_image_analyses.cost_usd`; weekly summary surfaces in admin.
7. If Claude API is down, job retries 3× with backoff, then marks `failed` with error — no user-facing crash.
8. Multi-tenancy: `property_image_analyses.agency_id` set; `BelongsToAgency` scope applied; cross-agency access blocked.
9. Hard delete of an image cascades to its analysis row.
10. `scripts/dev-check.ps1` passes with 0 new failures.

---

## Files to create or modify

### New
- `database/migrations/YYYY_MM_DD_create_property_image_analyses_table.php`
- `database/migrations/YYYY_MM_DD_add_ai_meta_to_properties.php`
- `app/Models/PropertyImageAnalysis.php` (with `BelongsToAgency`)
- `app/Jobs/AnalysePropertyImageJob.php` — queued, retryable
- `app/Services/AI/VisionRecognitionService.php` — Claude Haiku 4.5 vision client (mirrors `ClaudeVisionParserService` pattern)
- `app/Http/Controllers/Api/PropertyImageAiController.php` — `GET /api/mobile/properties/{id}/ai-suggestions`, `POST /api/mobile/properties/{id}/features/merge-ai`
- `resources/views/components/ai-badge.blade.php` (shared with [[ellie-voice]])
- `resources/views/corex/properties/partials/ai-suggestions.blade.php`
- `resources/js/property-ai-suggestions.js`

### Modify
- `app/Models/Property.php` — `$casts` adds `features_json_meta => array`
- `app/Http/Controllers/Api/MobilePropertyController.php` — dispatch `AnalysePropertyImageJob` on image store
- `resources/views/corex/properties/show.blade.php` — render AI badge for AI-sourced features
- `resources/views/corex/properties/create-edit.blade.php` — include AI suggestions partial
- `database/seeders/CoreXPermissionSeeder.php` — add `use_property_image_ai`
- `routes/api.php` — register the two new routes under `/api/mobile/properties/{id}/`
- `config/services.php` — confirm `anthropic.key` is set (already exists)

---

## Out of scope

- Auto-detection of price band / valuation from photo aesthetics
- Defect detection (cracks, damp, missing fittings) — separate spec when needed
- OCR of floor-plan images — covered by [[docuperfect]] vision path, not this spec
- Bulk re-analysis of existing portfolio photos — possible follow-up via artisan command
- Listing description generation — already lives in `MarketingCopyService`

---

## Cost estimate

Per image (Claude Haiku 4.5 vision, ~1,600 image tokens + 250 prompt + 150 output):
- Input:  1,850 × $1/M  = $0.00185
- Output: 150   × $5/M  = $0.00075
- **~$0.0026 per image**

| Scenario | Cost |
|---|---|
| One property, 25 images | $0.065 (~R1.20) |
| 10 agents × 4 properties/week × 25 photos | ~$26/month (~R470) |

Cost-control levers if needed: resize to 1024px before upload, batch multiple images per Claude call, skip non-room images client-side.

---

## Related specs

- [[ellie-voice]] — shares the `ai-badge` component and the broader "AI-attributed data" pattern
- [[mobile-core-matches]] — direct beneficiary of richer `features_json`
- [[listings]] — feature data feeds portal syndication
