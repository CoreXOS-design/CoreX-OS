# Spec — Description-detected feature warning ("marked" vs "mentioned")

Status: **DRAFT, awaiting Johan's approval.** Not built. Written during the 2026-08-04
autonomy session as the scoped follow-up to the matching-model fix (structured-features-only
matching, decision 2) — see `.ai/audits/core-match-autonomy-session-2026-08-04.md` §1/§4.

## What this feature does and why

The matching engine now scores must-have / nice-to-have features against a property's
**structured** `features_json` only — it no longer scans the description/headline text
(that fallback was silently letting a property "pass" a must-have it was never actually
tagged with, which is what let "4 Alomsee" wrongly match a "sea view" + "security" must-have
via prose alone).

That fix is correct, but it exposes a real gap the other direction: an agent's description
routinely *does* mention a feature (e.g. "secure complex", "gorgeous ocean view") that never
got ticked in the structured feature picker — so a genuinely-qualifying property now silently
fails to match, because the agent's data entry is incomplete, not because the property lacks
the feature. This spec is the agent-facing warning Johan asked to scope: **detect the gap and
tell the agent, so they fix the data at the source** — matching stays honest (structured only)
and the agent gets a nudge to make the structured data actually complete.

**Pillar:** Property. Reads `Property.description` / `Property.headline` / `Property.features_json`,
writes back to `Property.features_json` only when the agent accepts a suggestion (no new
writes otherwise).

## Reuse — this is 90% already built (COPY → ADAPT, not new)

The property workspace (`resources/views/corex/properties/show.blade.php`) already has a
complete "AI-detected feature suggestion" UI: a modal + inline chip list
(`aiFeatureSugg`), each chip showing a confidence badge with **Accept** (adds the label to
the right feature category / space) and **Discard** buttons, driven by a server-injected
`_aiSuggestions` JS payload built by `App\Services\AI\PropertyAiSuggestionService::forProperty()`
from **photo** analysis (`PropertyImageAnalysis`). This spec proposes feeding the *same*
array from a *second* source — description text — so the existing UI, existing Alpine
methods (`acceptAiFeature`/`discardAiFeature`/`acceptAiSpace`/`discardAiSpace`), and existing
accept/discard/dedup logic all work unchanged. **No frontend changes needed for v1.**

`PropertyAiSuggestionService::TOKEN_MAP` already maps the canonical feature vocabulary
(`ContactMatchController::FEATURE_OPTIONS`) onto the web workspace's category/space
vocabulary — reuse this exact map, don't invent a second one.

## Current gap in the reused map — a decision Johan needs to make

`TOKEN_MAP` deliberately drops `sea_view` and generic `security` today ("no clean web
vocabulary equivalent" — see the comment at `PropertyAiSuggestionService.php:31,49`). **These
are exactly the two tokens that caused the "4 Alomsee" miss in Job A.** Two ways forward:

- **v1-minimal:** detect only the 7 tokens already in `TOKEN_MAP` (furnished, pet_friendly,
  air_conditioning, balcony, fibre, solar, borehole) + the 5 space-mapped tokens (pool, garden,
  garage, study, granny_flat) = 12 of 14. Zero new categories, zero schema/vocabulary change,
  ships fast. `sea_view`/generic `security` stay undetectable, same limitation the AI-photo
  path already has.
- **v1-full:** additionally add `sea_view` to the `theProperty` category and a generic
  `Security` item to the `security` category in both `TOKEN_MAP` and the web
  `_FEATURE_CATEGORIES` list (`show.blade.php`), closing the gap completely — this is the
  one that would have actually caught the Alomsee case. Slightly more surface area (one
  category-list edit in a page already covered by "COPY-ADAPT", not a schema change — categories
  are a PHP/JS constant, not a DB table).

**Recommend v1-full** — the whole point of this feature is catching exactly the Alomsee-shape
gap, and v1-minimal wouldn't have caught it. Flagging as the one open decision in this spec.

## Detection logic — also reuse, not reinvent

The description/headline text-scan logic being *removed* from
`MatchingService::propertyHasFeature()` this session (git history: commit `7ebc3674`, the
now-deleted "Fallback: scan prose for the feature" block) is the exact primitive needed here
— just repurposed from "silently pass a match" to "explicitly surface a suggestion". Reuse:

- `MatchingService::canonicalFeature()` for normalization (currently `protected static` —
  needs to become `public static`; it's a pure, stateless, side-effect-free string utility,
  safe to widen visibility, no behaviour change).
- The same needle-matching approach: for each candidate token, check
  `str_contains($hay, $needle)` where `$hay` is the lowercased description+headline and
  `$needle` is the canonical token with underscores replaced by spaces.

New method, same file (keeps the single-responsibility "turn raw signals into web-vocabulary
suggestions" job in one class):

```php
// App\Services\AI\PropertyAiSuggestionService
public function detectFromDescription(Property $property): array
{
    $hay = strtolower(($property->description ?? '') . ' ' . ($property->headline ?? ''));
    if ($hay === '') return [];

    $already = /* canonical tokens already in features_json — reuse
                  MatchingService::propertyFeatureTokens() logic, or duplicate the
                  ~15-line features_json normaliser; TBD at build time which is cleaner */;

    $found = [];
    foreach (self::TOKEN_MAP as $token => $target) {
        if (in_array($token, $already, true)) continue; // already marked, no suggestion
        $needle = str_replace('_', ' ', $token);
        if (! str_contains($hay, $needle) && ! str_contains($hay, $token)) continue;
        $found[] = $target; // same {space:...} / {feature:{category,label}} shape as vision suggestions
    }
    return $found;
}
```

Wire into `forProperty()` (or the controller at `PropertyController.php:520-525`): merge
`detectFromDescription()`'s output into the existing `$features`/`$spaces` arrays, deduped by
label against BOTH structured features_json (already handled — see above) and against
whatever the vision pass already suggested (avoid a duplicate chip for the same label from
two sources). Simplest: tag each suggestion with its origin (`'source' => 'vision'|'text'`) so
a future UI iteration *could* show a small badge distinguishing them — not required for v1,
the existing UI doesn't need to know or care where a suggestion came from.

## UI placement

No new page, no new nav entry — this rides the existing property workspace's "AI suggestions"
modal/chips (`show.blade.php`, `resources/views/corex/properties/show.blade.php:1938-1989` for
the chip UI, `:6201-6300` for the Alpine state). The modal already auto-opens when there's
anything unreviewed (`aiModalOpen = true` when `hasAiSuggestions`) — a text-detected suggestion
would surface exactly the same way a photo-detected one does today. Consider (build-time
decision, not blocking the spec) whether the modal's copy needs a one-line addition
distinguishing "detected in your photos" from "detected in your description" — cosmetic, not
required for correctness.

## User flow

1. Agent writes/edits a property description that mentions "sea view" but never ticks the
   Sea View feature checkbox.
2. Next time they open the property workspace, the AI-suggestions modal (or its inline chip
   strip, if already dismissed once) shows a "Sea View" suggestion chip.
3. Agent clicks Accept → `Sea View` is added to `features.theProperty` (or wherever v1-full
   places it) exactly like an accepted photo-suggestion is today. Saving the form persists it
   into `features_json` through the existing save path — no new persistence code.
4. Agent clicks Discard → suggestion is dismissed for this review pass (matches existing
   `discardAiFeature` behaviour — not persisted as "never suggest again"; if they want that,
   it's a separate enhancement, not scoped here).

## Permissions

None beyond what already gates the property workspace / property edit (`authorizeProperty()`
in `PropertyController`). This is a read+suggest capability layered on an existing authorised
surface, not a new permission-gated action.

## Acceptance criteria

- A property whose description contains a v1-scope token (e.g. "sea view", "secure complex" →
  "security" if v1-full) but whose `features_json` doesn't carry it shows that token as a
  suggestion chip the next time the workspace loads.
- A property whose `features_json` ALREADY has the token shows no duplicate suggestion for it.
- Accepting a text-detected suggestion behaves identically to accepting a photo-detected one
  (same Alpine methods, same save path) — no new code paths in the frontend.
- A property with no description/headline text produces zero text-detected suggestions
  (empty-safe).
- `MatchingService::canonicalFeature()`'s visibility change (`protected` → `public`) has zero
  behavioural effect — it's called with the exact same arguments from the exact same
  call sites as before; only external callers gain the ability to call it too.

## Files to create / modify

- `app/Services/AI/PropertyAiSuggestionService.php` — add `detectFromDescription()`; if
  v1-full, extend `TOKEN_MAP` with `sea_view` + a generic `security` entry.
- `app/Services/Matching/MatchingService.php` — widen `canonicalFeature()` from `protected
  static` to `public static` (one-line visibility change, no logic change).
- `app/Http/Controllers/CoreX/PropertyController.php:520-525` — merge
  `detectFromDescription()`'s output into `$aiImageSuggestions` before it's passed to the view
  (or call both and merge in the service — build-time call).
- `resources/views/corex/properties/show.blade.php` — **only if v1-full**: add `Sea View` /
  `Security` to `_FEATURE_CATEGORIES` (find this JS constant near the Alpine component
  definition, alongside `_ALL_SPACE_TYPES`). No changes needed for v1-minimal.
- New test file, e.g. `tests/Feature/Properties/DescriptionFeatureDetectionTest.php` —
  mirrors the shape of `tests/Feature/Matching/PropertyTypeFamilyAndStructuredFeaturesTest.php`
  from this session: description-mentions-but-not-marked → suggested; already-marked →
  not suggested; empty description → empty result.

## Explicitly out of scope for this spec

- Detecting from photos beyond what `PropertyAiSuggestionService` already does (that's the
  existing, separate vision pipeline — spec `.ai/specs/property-image-recognition.md`).
- "Never suggest this again" per-property dismissal memory — Discard today is
  per-review-pass, matching existing photo-suggestion behaviour; changing that is a separate
  decision.
- Re-scanning on every keystroke / live-as-you-type detection — this is a page-load /
  re-open detection, same cadence as the existing AI-photo pass, not a typing-triggered
  feature.
