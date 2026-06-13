# Marketing AI Copy (Ellie) — Module Spec

> Status: ACTIVE — `AT-7` follow-up
> Last updated: 2026-06-13 (Andre)
> Pillars: **Property** (read), **Agent** (read), **Agency** (read/scope + AI budget)

---

## 1. What this feature does and why

On the property Marketing hub (`/corex/properties/{property}/marketing`) the agent can press
**Generate with Ellie AI** to draft platform ad copy (Facebook / Instagram) for the listing.
Ellie writes the copy from the property's own data so the agent gets a usable first draft in
one click instead of staring at a blank box.

The non-negotiable rule: **Ellie may only state what the property actually records.** It must
not invent amenities, lifestyle claims, locations, views, schools, condition, or any selling
point that is not present in the property's structured facts, features list, or description.
Marketing copy that overstates a listing is a CPA/PPRA compliance risk — the system must not
manufacture it.

---

## 2. Pillar connections

- **Property** — READ. Source of every fact: type, beds, baths, garages, size, price, location,
  `features_json`, `description`/`excerpt`.
- **Agent** — READ (implicit). The CTA points buyers at the listing agent.
- **Agency** — READ + AI budget. The call is gated by `Agency::canMakeAiCall()` and every call
  is recorded to the unified cost ledger (`ai_usage_events`).

No write-back to a pillar — the draft lives in the page until the agent publishes a
`PropertyMarketingPost` (existing flow, unchanged).

---

## 3. AI model & cost

- **Lowest tier only.** Uses the system's configured cheapest model
  (`config('services.anthropic.models.fast')` → `claude-haiku-4-5`), per the "constraint is
  fuel" principle — never a premium tier for a draft.
- Direct Anthropic call (same pattern as the other non-MIC callers: VisionRecognition,
  IntentExtraction). The MIC `AnthropicGateway` is MIC-narrative-only.
- Every successful call records to `ai_usage_events` via `AiUsageRecorder`
  (`source = SOURCE_MARKETING_COPY`, `surfaceRef = property:{id}:{platform}`). Spec:
  `ai-cost-ledger.md` §4.3.

---

## 4. Grounding contract (the strict rule)

The prompt sends Ellie a closed set of **allowed facts** — and nothing else is permitted:

1. The property's structured attributes that are actually set (type, beds, baths, garages,
   floor size, price, suburb/city).
2. The `features_json` list.
3. The free-text `description` / `excerpt`.

Rules enforced via the Anthropic `system` prompt:

- Use ONLY the facts provided. Every claim in the copy must trace to one of them.
- Do NOT invent, infer, assume, or embellish — no amenity, view, finish, neighbourhood,
  school, beach, distance, lifestyle, or condition claim that is not explicitly present.
- Omit anything unknown; never guess to fill a gap.
- You may rephrase, summarise, and arrange the given facts attractively — you may not add new
  facts.
- ZAR currency, South African buyer audience.

If the property has almost no data (empty description + empty features), Ellie writes minimal
copy from the structured facts only — it does not pad.

**Reference numbers & links.** Listing/web/stock reference numbers must NEVER appear in the
copy (they often live in P24-imported descriptions). They are stripped from the description
*before* it reaches the model, the model is told never to emit one, and any that slip through
are stripped from the output. Instead, the system appends the property's **public live-preview
link** (`route('corex.properties.preview', [property, title-slug])`) as the call-to-action — the
model is told not to write any URL itself, so the link is always correct and clickable.

---

## 5. Failure states (graceful, not a 500)

`generateCopy` returns `ok:false` + a clear message the UI shows directly:

- **AI not configured** — `ANTHROPIC_API_KEY` empty or `anthropic.enabled = false` (e.g. a dev
  box with no key): "Ellie AI isn't configured on this environment yet." (HTTP 422)
- **Budget reached** — `Agency::canMakeAiCall()` is false: "Your agency's monthly AI budget has
  been reached." (HTTP 422)
- **Upstream/parse error** — logged, generic "Couldn't generate copy, please try again." (HTTP 500)

---

## 6. Permissions

Gated by `access_properties` (existing route middleware + `authorizeProperty()` in the
controller). No new permission key.

---

## 7. Acceptance criteria

- [ ] With a valid key, **Generate with Ellie AI** returns copy grounded only in the property's
      facts/features/description; spot-check shows no invented amenity/location/lifestyle claims.
- [ ] A property with rich `features_json` + `description` yields richer copy; a sparse property
      yields minimal copy with no padding.
- [ ] Uses the configured lowest tier (`models.fast`) and records one `ai_usage_events` row per
      successful call with the right `source`/`surfaceRef`/`agency_id`.
- [ ] With no key (local), the button shows "Ellie AI isn't configured…" instead of a raw error.
- [ ] Budget-capped agency shows the budget message and makes no API call.

---

## 8. Files

- `app/Services/MarketingCopyService.php` — grounding prompt, config-driven model, budget gate,
  typed failure messages.
- `app/Http/Controllers/PropertyMarketingController.php` — `generateCopy()` maps failures to
  friendly `ok:false` responses with correct status.
- `resources/views/marketing/hub.blade.php` — surface the returned message.
