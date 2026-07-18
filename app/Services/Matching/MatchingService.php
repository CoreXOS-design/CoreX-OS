<?php

namespace App\Services\Matching;

use App\Models\ContactMatch;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Core matching engine. SQL-first. Applies hard filters via the database,
 * then computes a 0-100 score per (property, match) pair in PHP.
 *
 * See .ai/specs/matches.md §5 for the scoring weights.
 */
class MatchingService
{
    public const MIN_SCORE_TO_SURFACE = 40;

    /**
     * Lowest score a property may have and still be shown on a Core Match.
     * Anything below this is treated as "not a match" and dropped.
     */
    public const MIN_SCORE_TO_DISPLAY = 50;

    /** Tier cut-offs (inclusive lower bounds). See tierFor(). */
    public const TIER_STRONG_MIN = 80;
    public const TIER_GOOD_MIN   = 65;
    public const TIER_FAIR_MIN   = 50;

    /**
     * Property statuses considered valid for each listing intent. A sale match
     * must never surface a rental listing's status and vice versa.
     */
    private const STATUS_BY_LISTING_TYPE = [
        'sale'   => ['for_sale', 'forsale', 'active', 'available', 'on_market'],
        'rental' => ['for_rent', 'forrent', 'to_rent', 'torent', 'available_rent', 'active'],
    ];

    /** Allowed values for the agency `matches_visibility_scope` setting. */
    public const SCOPE_AGENT  = 'agent';
    public const SCOPE_BRANCH = 'branch';
    public const SCOPE_AGENCY = 'agency';

    /**
     * Classify a 0-100 score into a display tier.
     * Returns null when the score is below the display floor.
     */
    public static function tierFor(int $score): ?string
    {
        if ($score >= self::TIER_STRONG_MIN) return 'strong';
        if ($score >= self::TIER_GOOD_MIN)   return 'good';
        if ($score >= self::TIER_FAIR_MIN)   return 'fair';
        return null;
    }

    /**
     * Read the agency-wide visibility scope setting and translate it into
     * `propertiesForMatch` overrides for the given match.
     */
    public static function scopeOverridesFor(ContactMatch $match): array
    {
        $scope = (string) \App\Models\PerformanceSetting::get('matches_visibility_scope', self::SCOPE_AGENCY);

        return match ($scope) {
            self::SCOPE_AGENT  => ['agent_id' => $match->created_by_user_id],
            self::SCOPE_BRANCH => [
                'agent_id'  => null,
                'branch_id' => $match->createdBy?->branch_id,
            ],
            default            => ['agent_id' => null], // agency
        };
    }

    /**
     * Off-market / concluded statuses that make a property INELIGIBLE to match —
     * in EITHER direction (agent notify OR display / shared page). A let-out or
     * rented rental, a sold/transferred/withdrawn listing, an expired or
     * cancelled mandate: none of these may ever surface as a "new match" or fire
     * a match email. A property is "matchable" only while it is genuinely live.
     *
     * THE single source of truth for match eligibility. It replaces the earlier
     * split EXCLUDED_FOR_NOTIFY / EXCLUDED_FOR_DISPLAY lists, which (a) disagreed
     * — notify was missing let_out/expired/cancelled/unavailable — and (b) were
     * compared with a case-SENSITIVE `in_array(..., true)` against a lowercase
     * list, while the `status` column is stored mixed-case across ingress paths
     * (P24 sync writes capitalised 'Sold'/'Withdrawn'/'Rented'; the wizard writes
     * lowercase). The result: 769 `Sold` and every `let_out` rental leaked into
     * agent match emails. Fix-the-class — one list, one normalised predicate
     * (isMatchableStatus), every matching entry point routed through it.
     */
    private const NON_MATCHABLE_STATUSES = [
        'sold', 'transferred', 'rented', 'let_out',
        'withdrawn', 'expired', 'cancelled',
        'unavailable', 'archived', 'draft', 'pending',
    ];

    /**
     * Case-insensitive match-eligibility test for a property's lifecycle status.
     *
     * A NULL / blank status is treated as matchable: an incomplete-but-live
     * listing must not be silently suppressed — the same "incomplete listings
     * shouldn't be penalised" rule the scorer applies. Only an EXPLICIT
     * off-market status blocks the match.
     */
    public static function isMatchableStatus(?string $status): bool
    {
        $s = strtolower(trim((string) $status));
        return $s === '' || !in_array($s, self::NON_MATCHABLE_STATUSES, true);
    }

    /**
     * All active matches in the same agency that this property could possibly satisfy.
     * Hard filters are applied in SQL using indexed columns.
     */
    public function candidatesForProperty(Property $property): Collection
    {
        if (!$property->id || !self::isMatchableStatus($property->status)) {
            return collect();
        }

        $query = ContactMatch::query()
            ->active()
            ->where('agency_id', $property->agency_id)
            ->countable((int) $property->agency_id); // AT-71 — exclude uncountable (empty) wishlists

        $this->applyHardFilters($query, $property);

        return $query->with(['contact', 'createdBy'])->get();
    }

    /**
     * All matches that this property satisfies, sorted by score desc.
     * Used by the property page Core Matches tab.
     */
    public function matchesForProperty(Property $property): Collection
    {
        if (!self::isMatchableStatus($property->status)) {
            return collect();
        }

        $candidates = ContactMatch::query()
            ->active()
            ->where('agency_id', $property->agency_id)
            ->countable((int) $property->agency_id); // AT-71 — exclude uncountable (empty) wishlists

        $this->applyHardFilters($candidates, $property);

        return $candidates->with(['contact', 'createdBy'])->get()
            ->reject(fn (ContactMatch $m) => in_array($property->id, $m->hidden_property_ids ?? [], true))
            ->map(function (ContactMatch $m) use ($property) {
                $m->setAttribute('match_score', $this->score($property, $m));
                return $m;
            })
            ->sortByDesc('match_score')
            ->values();
    }

    /**
     * All properties that satisfy this match, sorted by score desc.
     * Used by the match results page, the agent mobile app, the buyer portal
     * and the public shared page.
     *
     * Relaxed matching (default): numeric criteria (price, beds, baths,
     * garages, sizes) are widened into a tolerance band in SQL so a near-miss
     * — e.g. a 2-bed for a 3-bed search at the right price — still surfaces.
     * score() then decays it, and anything below MIN_SCORE_TO_DISPLAY (50%)
     * is dropped. Each returned Property carries `match_score` (0-100) and
     * `match_tier` ('strong'|'good'|'fair'). listing_type, property status
     * and suburb remain HARD filters and are never relaxed.
     *
     * Pass `['relaxed' => false]` to restore exact-bound behaviour.
     *
     * @param  array<string,mixed>  $overrides  optional UI filter overrides (price, suburb, etc.)
     */
    public function propertiesForMatch(ContactMatch $match, array $overrides = []): Collection
    {
        // AT-71 — an uncountable (empty / below-bar) wishlist matches nothing.
        // Guards the empty→100 inflation at the entry point of this path.
        if (!$match->isCountable()) {
            return collect();
        }

        $relaxed = $overrides['relaxed'] ?? true;
        $query = Property::query()
            // Off-market listings never surface. Case-insensitive (statuses are
            // stored mixed-case) and NULL-tolerant (an incomplete-but-live
            // listing stays matchable) — mirrors isMatchableStatus() in SQL.
            ->where(function (Builder $sub) {
                $sub->whereNull('status')
                    ->orWhereRaw('LOWER(TRIM(status)) NOT IN ('
                        . collect(self::NON_MATCHABLE_STATUSES)->map(fn ($s) => "'$s'")->implode(',')
                        . ')');
            });

        if ($match->agency_id) {
            $query->where('agency_id', $match->agency_id);
        }

        // Visibility scope. Default: only the match owner's properties.
        // Override with `agent_id` (specific agent or null for any) and/or `branch_id`.
        // Resolved by the agency setting `matches_visibility_scope` — see scopeOverridesFor().
        if (array_key_exists('agent_id', $overrides)) {
            if ($overrides['agent_id'] !== null) {
                $query->where('agent_id', $overrides['agent_id']);
            }
        } else {
            $query->where('agent_id', $match->created_by_user_id);
        }
        if (!empty($overrides['branch_id'])) {
            $query->where('branch_id', $overrides['branch_id']);
        }

        $includeHidden = !empty($overrides['include_hidden']);
        if (!$includeHidden && !empty($match->hidden_property_ids)) {
            $query->whereNotIn('id', $match->hidden_property_ids);
        }

        $listingType  = $overrides['listing_type']  ?? $match->listing_type; // sale vs rental is a hard filter — never mix the two
        $category     = $overrides['category']      ?? $match->category;
        $propertyType = $overrides['property_type'] ?? $match->property_type;
        $priceMin     = $overrides['price_min']     ?? $match->price_min;
        $priceMax     = $overrides['price_max']     ?? $match->price_max;
        $bedsMin      = $overrides['beds_min']      ?? $match->beds_min;
        $bathsMin     = $overrides['baths_min']     ?? $match->baths_min;
        $garagesMin   = $overrides['garages_min']   ?? $match->garages_min;
        $floorMin     = $overrides['floor_size_min'] ?? $match->floor_size_min;
        $floorMax     = $overrides['floor_size_max'] ?? $match->floor_size_max;
        $erfMin       = $overrides['erf_size_min']  ?? $match->erf_size_min;
        $erfMax       = $overrides['erf_size_max']  ?? $match->erf_size_max;

        // String criteria: allow NULL on the property side (incomplete listings shouldn't be penalised).
        $strLoose = function (Builder $q, string $col, string $val) {
            $q->where(function (Builder $q2) use ($col, $val) {
                $q2->whereNull($col)->orWhere($col, $val);
            });
        };
        // listing_type uses STRICT equality — sale vs rental are different markets and
        // properties with NULL listing_type are bad data, not legitimate ambiguity.
        // This is a HARD filter and is never relaxed.
        if ($listingType) {
            $query->where('listing_type', $listingType);

            // Belt-and-braces: also constrain by status so a property mis-tagged
            // with the wrong listing_type but correct status doesn't slip through.
            $allowedStatuses = self::STATUS_BY_LISTING_TYPE[$listingType] ?? null;
            if ($allowedStatuses) {
                $query->where(function (Builder $sub) use ($allowedStatuses) {
                    $sub->whereNull('status')
                        ->orWhereRaw('LOWER(status) IN ('
                            . collect($allowedStatuses)->map(fn ($s) => "'$s'")->implode(',')
                            . ')');
                });
            }
        }
        // category / property_type stay loose — half-filled listings still appear.
        if ($category)     $strLoose($query, 'category', $category);
        if ($propertyType) $strLoose($query, 'property_type', $propertyType);

        // Numeric criteria: allow NULL on the property side too.
        $numLoose = function (Builder $q, string $col, string $op, int $val) {
            $q->where(function (Builder $q2) use ($col, $op, $val) {
                $q2->whereNull($col)->orWhere($col, $op, $val);
            });
        };
        // In relaxed mode the SQL bound is widened into a tolerance band so a
        // near-miss survives to the scoring stage — score() then decays it and
        // the MIN_SCORE_TO_DISPLAY floor drops anything genuinely too far off.
        $priceTol = $relaxed ? 0.30 : 0.0;  // ±30% price band
        $countTol = $relaxed ? 1    : 0;    // allow 1 short on beds / baths / garages
        $sizeTol  = $relaxed ? 0.30 : 0.0;  // ±30% floor / erf size band

        if ($priceMin)   $numLoose($query, 'price', '>=', (int) floor($priceMin * (1 - $priceTol)));
        if ($priceMax)   $numLoose($query, 'price', '<=', (int) ceil($priceMax * (1 + $priceTol)));
        if ($bedsMin)    $numLoose($query, 'beds', '>=', max(0, (int) $bedsMin - $countTol));
        if ($bathsMin)   $numLoose($query, 'baths', '>=', max(0, (int) $bathsMin - $countTol));
        if ($garagesMin) $numLoose($query, 'garages', '>=', max(0, (int) $garagesMin - $countTol));
        if ($floorMin)   $numLoose($query, 'size_m2', '>=', (int) floor($floorMin * (1 - $sizeTol)));
        if ($floorMax)   $numLoose($query, 'size_m2', '<=', (int) ceil($floorMax * (1 + $sizeTol)));
        if ($erfMin)     $numLoose($query, 'erf_size_m2', '>=', (int) floor($erfMin * (1 - $sizeTol)));
        if ($erfMax)     $numLoose($query, 'erf_size_m2', '<=', (int) ceil($erfMax * (1 + $sizeTol)));

        // Hard-cutover suburb filter: match by P24 suburb id.
        $suburbIds = $overrides['p24_suburb_ids'] ?? $match->p24SuburbIdList();
        if (!empty($suburbIds)) {
            $query->whereIn('p24_suburb_id', $suburbIds);
        }

        return $query->with(['agent', 'branch'])
            ->get()
            ->map(function (Property $p) use ($match) {
                $sc = $this->score($p, $match);
                $p->setAttribute('match_score', $sc);
                $p->setAttribute('match_tier', self::tierFor($sc));
                return $p;
            })
            // In relaxed mode drop anything below the display floor (50%).
            ->filter(fn (Property $p) => !$relaxed || $p->match_score >= self::MIN_SCORE_TO_DISPLAY)
            ->sortByDesc('match_score')
            ->values();
    }

    /**
     * Hard-filter SQL — applies match criteria as WHERE clauses against contact_matches
     * given a candidate property.  Returns true only if the *match* could plausibly
     * cover this *property*. (Reverse of propertiesForMatch.)
     */
    protected function applyHardFilters(Builder $query, Property $property): void
    {
        $price    = (int) ($property->price ?? 0);
        $beds     = (int) ($property->beds ?? 0);
        $baths    = (int) ($property->baths ?? 0);
        $garages  = (int) ($property->garages ?? 0);
        $floor    = (int) ($property->size_m2 ?? 0);
        $erf      = (int) ($property->erf_size_m2 ?? 0);

        if ($property->listing_type) {
            $query->where(function (Builder $q) use ($property) {
                $q->whereNull('listing_type')->orWhere('listing_type', $property->listing_type);
            });
        }

        $query->where(function (Builder $q) use ($price) {
            $q->whereNull('price_min')->orWhere('price_min', '<=', $price);
        });
        $query->where(function (Builder $q) use ($price) {
            $q->whereNull('price_max')->orWhere('price_max', '>=', $price);
        });

        $query->where(function (Builder $q) use ($beds) {
            $q->whereNull('beds_min')->orWhere('beds_min', '<=', $beds);
        });
        $query->where(function (Builder $q) use ($baths) {
            $q->whereNull('baths_min')->orWhere('baths_min', '<=', $baths);
        });
        $query->where(function (Builder $q) use ($garages) {
            $q->whereNull('garages_min')->orWhere('garages_min', '<=', $garages);
        });

        if ($floor > 0) {
            $query->where(function (Builder $q) use ($floor) {
                $q->whereNull('floor_size_min')->orWhere('floor_size_min', '<=', $floor);
            });
            $query->where(function (Builder $q) use ($floor) {
                $q->whereNull('floor_size_max')->orWhere('floor_size_max', '>=', $floor);
            });
        }
        if ($erf > 0) {
            $query->where(function (Builder $q) use ($erf) {
                $q->whereNull('erf_size_min')->orWhere('erf_size_min', '<=', $erf);
            });
            $query->where(function (Builder $q) use ($erf) {
                $q->whereNull('erf_size_max')->orWhere('erf_size_max', '>=', $erf);
            });
        }

        // Category + property_type — match if the criterion is null OR exact-match
        if ($property->category) {
            $query->where(function (Builder $q) use ($property) {
                $q->whereNull('category')->orWhere('category', $property->category);
            });
        }
        if ($property->property_type) {
            $query->where(function (Builder $q) use ($property) {
                $q->whereNull('property_type')->orWhere('property_type', $property->property_type);
            });
        }

        // Hidden / must-have / suburb filtering happens in PHP after fetch (JSON columns).
    }

    /**
     * 0-100 score for a (property, match) pair.
     *
     * Only criteria the user actually specified contribute to the denominator.
     * If every specified criterion is fully satisfied, the score is 100.
     * Missing nice-to-haves (e.g. pool) drag the score down proportionally.
     * Returns 0 if must-haves are missing or the property is hidden.
     */
    public function score(Property $property, ContactMatch $match, float $priceBandPct = 0.0): int
    {
        $mustHaves = $match->must_have_features ?? [];
        if (!empty($mustHaves)) {
            // A must-have is a HARD gate — but only against a listing that
            // actually carries structured feature data to contradict it. A
            // listing with NO structured features (empty features_json) is
            // "unknown", not a mismatch: hard-failing it to 0 would silently
            // drop every good property whose feed simply didn't populate the
            // feature list. Same rule as category/property_type below
            // ("incomplete listings shouldn't be penalised"). See the Core
            // Match zero-results fix — feature tokens are normalised through
            // canonicalFeature() so `pet_friendly` matches "Pet Friendly"
            // (P24 string arrays) and "pets_allowed" (native dict keys) alike.
            if ($this->propertyHasStructuredFeatures($property)) {
                foreach ($mustHaves as $mh) {
                    if (!$this->propertyHasFeature($property, (string) $mh)) {
                        return 0;
                    }
                }
            }
        }

        $components = [];

        if ($match->price_min || $match->price_max) {
            $components[] = [25, $this->priceFitRatio($property, $match, $priceBandPct)];
        }
        if (!empty($match->p24SuburbIdList())) {
            $components[] = [20, $this->suburbFitRatio($property, $match)];
        }
        if ($match->beds_min) {
            $components[] = [8, $this->minMetRatio((int) $property->beds, (int) $match->beds_min)];
        }
        if ($match->baths_min) {
            $components[] = [7, $this->minMetRatio((int) $property->baths, (int) $match->baths_min)];
        }
        if ($match->garages_min) {
            $components[] = [5, $this->minMetRatio((int) $property->garages, (int) $match->garages_min)];
        }
        // String criteria: a NULL value on the PROPERTY side means the listing is
        // incomplete, not a mismatch — "incomplete listings shouldn't be
        // penalised" (the same rule propertiesForMatch applies in SQL). Skip the
        // component (neutral) when the property has no value; only a present-but-
        // different value scores 0. (AT-75 — prospecting listings carry no
        // category, so without this a category-specifying wishlist was unfairly
        // dragged down on every canvass listing.)
        if ($match->category && $property->category) {
            $components[] = [5, $property->category === $match->category ? 1.0 : 0.0];
        }
        if ($match->property_type && $property->property_type) {
            $components[] = [5, $property->property_type === $match->property_type ? 1.0 : 0.0];
        }
        if ($match->floor_size_min || $match->floor_size_max) {
            $components[] = [5, $this->rangeFitRatio((int) $property->size_m2, $match->floor_size_min, $match->floor_size_max)];
        }
        if ($match->erf_size_min || $match->erf_size_max) {
            $components[] = [5, $this->rangeFitRatio((int) $property->erf_size_m2, $match->erf_size_min, $match->erf_size_max)];
        }
        $wants = $match->nice_to_have_features ?? [];
        if (!empty($wants)) {
            $hits = 0;
            foreach ($wants as $f) {
                if ($this->propertyHasFeature($property, (string) $f)) $hits++;
            }
            $components[] = [15, $hits / count($wants)];
        }

        if (empty($components)) {
            // AT-71 — belt-and-braces against the empty-wishlist inflation bug.
            // No scorable component means either (a) the wishlist is uncountable
            // (no criteria at all) → must NOT inflate to a full match → 0; or
            // (b) it specified ONLY must-have features, which were already
            // verified above → a genuine full match → 100.
            return $match->isCountable() ? 100 : 0;
        }

        $totalWeight = 0;
        $earned      = 0.0;
        foreach ($components as [$weight, $fit]) {
            $totalWeight += $weight;
            $earned      += $weight * $fit;
        }

        return (int) round(min(100, max(0, $earned / $totalWeight * 100)));
    }

    /**
     * Price within [min,max] → 1.0; outside → linearly decays to 0 at ±50% of range.
     *
     * AT-75 — `$bandPct` (e.g. 0.10) widens the full-score band by ±pct before
     * decay begins, so a listing slightly past the stated band still scores full
     * (Johan's agency-configurable price-band-width knob). Default 0.0 preserves
     * the original behaviour for every existing caller (e.g. Core Matches).
     */
    protected function priceFitRatio(Property $property, ContactMatch $match, float $bandPct = 0.0): float
    {
        $price = (int) ($property->price ?? 0);
        if ($price <= 0) return 0.0;

        $min = (int) ($match->price_min ?: 0);
        $max = (int) ($match->price_max ?: 0);

        $bandPct  = max(0.0, $bandPct);
        $minFull  = $min ? (int) floor($min * (1 - $bandPct)) : 0; // tolerated lower edge
        $maxFull  = $max ? (int) ceil($max * (1 + $bandPct)) : 0;  // tolerated upper edge

        if ($minFull && $price < $minFull) {
            $delta = $minFull - $price;
            return max(0.0, 1 - $delta / max(1, $min * 0.5));
        }
        if ($maxFull && $price > $maxFull) {
            $delta = $price - $maxFull;
            return max(0.0, 1 - $delta / max(1, $max * 0.5));
        }
        return 1.0;
    }

    /** Value within [min,max] → 1.0; outside → 0. NULL on either side ignored. */
    protected function rangeFitRatio(int $value, ?int $min, ?int $max): float
    {
        if ($value <= 0) return 0.0;
        if ($min && $value < $min) return 0.0;
        if ($max && $value > $max) return 0.0;
        return 1.0;
    }

    /** value >= min → 1.0; otherwise proportional. */
    protected function minMetRatio(int $value, int $min): float
    {
        if ($min <= 0) return 1.0;
        if ($value >= $min) return 1.0;
        return max(0.0, $value / $min);
    }

    protected function suburbFitRatio(Property $property, ContactMatch $match): float
    {
        return $this->suburbCompatible($property, $match) ? 1.0 : 0.0;
    }

    /**
     * AT-289 — is this property's suburb compatible with the wishlist's declared
     * area? Open (no suburb list) = compatible (buyer is open to anywhere);
     * otherwise the property's p24_suburb_id must be one the buyer asked for.
     * The single source of truth for the suburb rule — suburbFitRatio scores with
     * it (soft, for the browse engine), and the per-property DEMAND-CLAIM count
     * (PropertyMatchScoringService::activeCanonicalBuyersForProperty) GATES on it,
     * so a buyer explicitly wanting a DIFFERENT suburb is never counted as "demand
     * for properties like yours in {property_suburb}".
     */
    public function suburbCompatible(Property $property, ContactMatch $match): bool
    {
        $ids = $match->p24SuburbIdList();
        if (empty($ids)) return true;                   // open to anywhere
        if (!$property->p24_suburb_id) return false;    // buyer named suburbs; property has none
        return in_array((int) $property->p24_suburb_id, $ids, true);
    }

    protected function propertyHasFeatures(Property $property, array $features): bool
    {
        foreach ($features as $f) {
            if (!$this->propertyHasFeature($property, (string) $f)) return false;
        }
        return true;
    }

    protected function propertyHasFeature(Property $property, string $feature): bool
    {
        $key  = strtolower(trim($feature));
        if ($key === '') return true;

        // Negation tokens: "no_pool", "not_pool", "unfurnished", "no_pets"
        // are satisfied when the property does NOT have the underlying feature.
        $negations = [
            'no_pool'      => 'pool',
            'not_pool'     => 'pool',
            'unfurnished'  => 'furnished',
            'no_pets'      => 'pet_friendly',
            'not_pet_friendly' => 'pet_friendly',
        ];
        if (isset($negations[$key])) {
            return !$this->propertyHasFeature($property, $negations[$key]);
        }
        // Generic "no_X" / "not_X" → negate inner check
        if (preg_match('/^(no|not)_(.+)$/', $key, $m)) {
            return !$this->propertyHasFeature($property, $m[2]);
        }

        // Canonicalise the wishlist token, then compare against the canonical
        // tokens a property positively carries. This bridges the three real
        // storage shapes that previously never matched each other:
        //   • wishlist wizard  → "pet_friendly" (snake_case token)
        //   • P24 imports      → ["Pet Friendly", ...] (Title-Case strings)
        //   • CoreX-native     → {"pets_allowed": true} (snake_case dict keys)
        $want = self::canonicalFeature($feature);
        if ($want === '') return true;

        if (in_array($want, $this->propertyFeatureTokens($property), true)) {
            return true;
        }

        // Fallback: scan prose for the feature, using the spaced form of the
        // canonical token ("sea view") as well as the token itself ("sea_view").
        $needle = str_replace('_', ' ', $want);
        $hay = strtolower((string) ($property->description ?? '') . ' ' . ($property->headline ?? ''));
        return $hay !== '' && (str_contains($hay, $needle) || str_contains($hay, $want));
    }

    /**
     * True when the listing carries any structured feature data (a non-empty
     * features_json). Used to decide whether a must-have failure is a genuine
     * mismatch (data present, feature absent) or merely unknown (no data).
     */
    protected function propertyHasStructuredFeatures(Property $property): bool
    {
        $raw = $property->features_json ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) && !empty($raw);
    }

    /**
     * The set of canonical feature tokens a property positively HAS.
     *
     * Handles both features_json shapes:
     *   • array-of-strings ["Pet Friendly", "Pool"] — every entry is a present feature
     *   • dict {"pool": true, "pets_allowed": false, "listing_visibility": "Public"}
     *     — only truthy boolean/"yes"/"1" flags count; meta strings are skipped.
     *
     * @return string[]
     */
    protected function propertyFeatureTokens(Property $property): array
    {
        $raw = $property->features_json ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $k => $v) {
            if (is_int($k)) {
                // array-of-strings form: the value IS the feature label
                $tok = self::canonicalFeature((string) $v);
            } else {
                // dict form: key is the label, value is the on/off flag. Skip
                // anything that isn't an affirmative flag so meta keys like
                // listing_visibility:"Public" never register as a feature.
                $truthy = $v === true || $v === 1 || $v === '1'
                    || (is_string($v) && in_array(strtolower(trim($v)), ['yes', 'true', 'y'], true));
                if (!$truthy) continue;
                $tok = self::canonicalFeature((string) $k);
            }
            if ($tok !== '') $out[] = $tok;
        }
        return $out;
    }

    /**
     * Normalise a raw feature label (from a wishlist token OR a property's
     * features_json / dict key) to a single canonical token, so the two sides
     * compare correctly regardless of storage shape. Collapses case, spaces,
     * and punctuation, then maps known synonyms onto one canonical spelling.
     */
    protected static function canonicalFeature(string $raw): string
    {
        $key = strtolower(trim($raw));
        if ($key === '') return '';
        // Collapse any run of non-alphanumerics to a single underscore.
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');
        if ($key === '') return '';

        // Synonym → canonical token. Keys are already normalised (snake_case).
        static $synonyms = [
            'pets_allowed'      => 'pet_friendly',
            'pets'              => 'pet_friendly',
            'pet'               => 'pet_friendly',
            'pet_friendly'      => 'pet_friendly',
            'sea_views'         => 'sea_view',
            'ocean_view'        => 'sea_view',
            'ocean_views'       => 'sea_view',
            'air_conditioned'   => 'air_conditioning',
            'air_conditioner'   => 'air_conditioning',
            'aircon'            => 'air_conditioning',
            'a_c'               => 'air_conditioning',
            'fiber'             => 'fibre',
            'fibre_ready'       => 'fibre',
            'fiber_ready'       => 'fibre',
            'swimming_pool'     => 'pool',
            'splash_pool'       => 'pool',
            'solar_power'       => 'solar',
            'solar_panels'      => 'solar',
            'solar_panel'       => 'solar',
            'solar_geyser'      => 'solar',
            'garages'           => 'garage',
            'granny_flat'       => 'flatlet',
            'cottage'           => 'flatlet',
        ];
        return $synonyms[$key] ?? $key;
    }
}
