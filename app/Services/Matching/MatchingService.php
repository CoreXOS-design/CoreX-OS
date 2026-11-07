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
     * Relaxed-mode price tolerance (.ai/specs/matches.md §5.1 — "price_min /
     * price_max | Relaxed | +/-30% band").
     *
     * ONE number, shared by the SQL candidate query AND by score()'s price hard
     * gate. 2026-09-10: those two had drifted apart. propertiesForMatch()
     * widened the SQL bound by 30% and then called score() WITHOUT a band, so
     * the gate re-cut at the exact stated ceiling and returned 0 — the widened
     * rows were fetched only to be thrown away, and the relaxed near-miss
     * surfacing the spec describes had never actually worked. The price gate's
     * own docblock already claimed it gated on "the SAME tolerance-widened
     * band"; it simply was never handed one. Named here so SQL and scorer can
     * never disagree again — BUILD_STANDARD §6 (fix the class).
     */
    // SUPERSEDED (spec 13.15): no screen uses a fixed 30% band any more; BuyerMatchRules::toleranceFor() (the agency's setting) is the one price rule.
    public const RELAXED_PRICE_BAND = 0.30;

    /**
     * Statuses that belong to the OPPOSITE listing intent — the belt-and-braces
     * cross-check that stops a property mis-tagged with the wrong listing_type
     * (but a correct status) from slipping through.
     *
     * 2026-09-10 (Johan, live bug — contact 18900 / match 671 showed ZERO
     * rentals): this used to be a WHITELIST (STATUS_BY_LISTING_TYPE) of the
     * statuses each intent was ALLOWED to carry. A whitelist fails CLOSED on
     * any status it has not heard of, and the rental list never contained
     * `to_let` — CoreX's own canonical on-market rental status
     * (Property::systemStatuses(), Property.php "the four on-market pickers
     * active/for_sale/to_let/under_offer"). Result: 19 of agency 1's 26 live
     * rentals were discarded before a single one was scored, silently, with no
     * error anywhere. `under_offer`, `on_show`, `on_auction` and every
     * agency-defined status in PropertySettingItem were dropped by the same
     * mechanism.
     *
     * Inverted to a BLACKLIST so the check fails OPEN: only a status that is
     * unambiguously the OTHER market's excludes. A neutral status (active,
     * on_show, available, or anything an agency defines for itself) passes
     * either way, and a status nobody has taught this class about can never
     * again silently delete live stock — BUILD_STANDARD §2 (the input-space
     * rule) and §3 (prevent or absorb, never break).
     *
     * `under_offer` is the one status this check would let through that
     * Property::MATCHING_EXCLUDED_ON_MARKET_STATUSES then stops on purpose —
     * Johan's ruling of 2026-09-10, recorded there.
     *
     * 2026-09-15 merge note (Staging promotion): QA1 independently carried a
     * STATUS_BY_LISTING_TYPE whitelist for this exact check — the same
     * pre-fix shape this docblock describes above, just never updated to the
     * 2026-09-10 blacklist. Dropped in favour of this already-shipped,
     * Johan-approved fix; nothing QA1 added here was reachable by any caller
     * (grep confirmed STATUS_BY_LISTING_TYPE had zero references outside its
     * own declaration), so nothing behavioural was lost. Its one substantive
     * addition — `to_let` must never be treated as a wrong-intent status for
     * a rental match — already holds true here: `to_let` appears only in the
     * SALE exclusion list below, never the rental one.
     */
    private const WRONG_INTENT_STATUSES = [
        // A SALE match must never surface a listing sitting on a rental status.
        'sale'   => ['to_let', 'to_rent', 'torent', 'for_rent', 'forrent', 'available_rent', 'let_out', 'rented'],
        // A RENTAL match must never surface a listing sitting on a sale status.
        'rental' => ['for_sale', 'forsale', 'on_auction', 'sold', 'sold_by_3rd_party', 'transferred'],
    ];

    /** Allowed values for the agency `matches_visibility_scope` setting. */
    public const SCOPE_AGENT  = 'agent';
    public const SCOPE_BRANCH = 'branch';
    public const SCOPE_AGENCY = 'agency';

    /**
     * Property-type FAMILY hard gate (Johan's ruling, 2026-08-04). property_type
     * itself is too granular and too messy for a hard filter (the same "vacant
     * land" concept is stored as "Vacant Land / Plot", "VacantLand" and "Vacant
     * Land" across live data) — family is the right grain: built residential
     * stock must never match a land/farm/commercial wishlist and vice versa,
     * while a House buyer still sees Townhouses (same family, still scored
     * soft on the specifics). Tokens are normalised the same way
     * canonicalFeature() normalises feature labels — lowercase, punctuation
     * collapsed to underscores — so every raw spelling variant lands on one
     * family. A raw value not found here returns null (unmapped, not
     * penalised) rather than being force-classified — an agency-custom type
     * must never silently hard-exclude a buyer (false-negative guardrail).
     *
     * 2026-08-11 gap fix: bare "Land" (PrivateProperty's raw property_type,
     * distinct from P24's "Vacant land") was missing from the land family —
     * an unmapped type is never gated, so PP land listings silently matched
     * house/apartment wishlists. Added 'land'. Audited every distinct
     * property_type currently in `properties` and `prospecting_listings`
     * against this list before landing the fix — 'land' was the only gap.
     */
    private const PROPERTY_TYPE_FAMILIES = [
        'built' => [
            'house', 'apartment', 'apartment_flat', 'flat', 'townhouse', 'sectional_title',
            'cottage', 'dual_living', 'duet', 'duplex', 'garage', 'penthouse', 'room',
            'studio_apartment', 'simplex', 'unit', 'villa',
        ],
        'land' => [
            'vacant_land', 'vacant_land_plot', 'vacantland', 'plot', 'stand', 'development', 'land',
        ],
        'farm' => [
            'farm', 'agricultural', 'agricultural_holding', 'smallholding',
        ],
        'commercial' => [
            'office', 'business', 'commercial_property', 'industrial_property',
            'commercial', 'industrial', 'retail', 'warehouse',
        ],
    ];

    /**
     * Classify a raw property_type label into a family, or null if unrecognised.
     * Null is a deliberate "don't know, don't gate" — see PROPERTY_TYPE_FAMILIES.
     */
    public static function propertyTypeFamily(?string $rawType): ?string
    {
        $key = strtolower(trim((string) $rawType));
        if ($key === '') return null;
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');
        if ($key === '') return null;

        foreach (self::PROPERTY_TYPE_FAMILIES as $family => $tokens) {
            if (in_array($key, $tokens, true)) return $family;
        }
        return null;
    }

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
            // Prod-audit 2026-09-16 — stock visibility follows the ASSIGNED agent
            // (agent_id, set on create and moved by reassignTo()), not the creator.
            self::SCOPE_AGENT  => ['agent_id' => $match->agent_id ?? $match->created_by_user_id],
            self::SCOPE_BRANCH => [
                'agent_id'  => null,
                'branch_id' => $match->agent?->branch_id ?? $match->createdBy?->branch_id,
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
     *
     * 2026-09-10 -> 2026-09-15: this class carried its OWN copy of that fix
     * (NON_MATCHABLE_EXTRA_STATUSES + nonMatchableStatuses()), derived from
     * Property::OFF_MARKET_STATUSES. Superseded 2026-09-15 after the same
     * defect class recurred one level up — this class's copy had ALSO drifted
     * (missing 'prospecting'/'not_selling'; 560 of 842 properties, 66%, of the
     * agency-wide matchable pool were ingested-but-unmandated stock). Now
     * delegates to Property::isMatchableStatus() — the ONE place this is
     * defined, so it cannot drift a third time — which folds in this class's
     * own 'rented'/'pending'/'under_offer' matching-only exclusions verbatim
     * as Property::MATCHING_EXCLUDED_ON_MARKET_STATUSES. Method kept here
     * (not called directly) so every existing caller of
     * MatchingService::isMatchableStatus() continues to work unchanged.
     *
     * AT-Core-Matches (2026-09-29) — Property::isMatchableStatus() itself
     * switched from a blacklist to an agency-configurable allow-list (Johan's
     * ruling: Core Matches shows ONLY active/expired/other-agency-sold
     * stock). This wrapper's own signature grew the same optional $agencyId
     * so every caller here (candidatesForProperty(), matchesForProperty())
     * stays agency-aware; $agencyId omitted resolves to the code default.
     */
    public static function isMatchableStatus(?string $status, ?int $agencyId = null): bool
    {
        return Property::isMatchableStatus($status, $agencyId);
    }

    /**
     * All active matches in the same agency that this property could possibly satisfy.
     * Hard filters are applied in SQL using indexed columns.
     */
    public function candidatesForProperty(Property $property): Collection
    {
        if (!$property->id || !self::isMatchableStatus($property->status, $property->agency_id)) {
            return collect();
        }

        $query = ContactMatch::query()
            ->active()
            ->where('agency_id', $property->agency_id)
            ->buyerInPlay((int) $property->agency_id) // a Won/Lost buyer (agency setting) has no Core Matches
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
        if (!self::isMatchableStatus($property->status, $property->agency_id)) {
            return collect();
        }

        $candidates = ContactMatch::query()
            ->active()
            ->where('agency_id', $property->agency_id)
            ->buyerInPlay((int) $property->agency_id) // a Won/Lost buyer (agency setting) has no Core Matches
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
        // AT-Core-Matches (2026-09-29) — allow-list, not blacklist. Case-
        // insensitive (statuses are stored mixed-case) and NULL-tolerant (an
        // incomplete-but-live listing stays matchable) — mirrors
        // Property::isMatchableStatus() in SQL. Agency-aware: resolves this
        // match's own agency's configured list, or the code default.
        $allowedStatuses = Property::coreMatchAllowedStatuses($match->agency_id);
        $query = Property::query()
            ->where(function (Builder $sub) use ($allowedStatuses) {
                $sub->whereNull('status')
                    ->orWhereRaw('LOWER(TRIM(status)) IN ('
                        . collect($allowedStatuses)->map(fn ($s) => "'" . addslashes($s) . "'")->implode(',')
                        . ')');
            })
            // .ai/specs/other-agency-stock.md §6 — Other Agency Stock stays a
            // genuine Core Match candidate (deliberately not excluded above);
            // this ONLY hides it from a STAFF viewer whose agency hasn't opted
            // their role into seeing it. auth()->user() is null on a public
            // buyer share link / unauthenticated context, which is never
            // gated — Johan: buyers see it like any other property.
            ->visibleOtherAgencyStock();

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
            $query->where('agent_id', $match->agent_id ?? $match->created_by_user_id);
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
        // 2026-08-19 (Falan, live bug) — this used to read the legacy singular
        // property_type column, which the controller only ever populates with
        // the FIRST of the buyer's selected types (property_type <-> property_types
        // reconciliation, ContactMatchController::update()). A buyer with
        // House+Townhouse+Vacant Land ticked got a candidate query pre-filtered
        // to House alone — the townhouses and land were excluded before score()
        // ever ran, never a scoring/family-gate problem (those already correctly
        // read the full array via propertyTypeList(), see below). An explicit
        // override still wins and still behaves as a single value — that's a
        // deliberate ad-hoc single-type filter on the public/mobile match views
        // (SharedMatchController, MobileCoreMatchController), not the buyer's
        // persisted multi-select, and must not be widened.
        $propertyTypes = array_key_exists('property_type', $overrides)
            ? array_filter([$overrides['property_type']])
            : $match->propertyTypeList();
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
        // Multi-value counterpart — property_type is a multi-select on the buyer's
        // side (propertyTypeList()); the candidate query must match ANY of the
        // wanted types, not force the whole set down to one value.
        $strLooseIn = function (Builder $q, string $col, array $vals) {
            $q->where(function (Builder $q2) use ($col, $vals) {
                $q2->whereNull($col)->orWhereIn($col, $vals);
            });
        };
        // listing_type uses STRICT equality — sale vs rental are different markets and
        // properties with NULL listing_type are bad data, not legitimate ambiguity.
        // This is a HARD filter and is never relaxed.
        if ($listingType) {
            $query->where('listing_type', $listingType);

            // Belt-and-braces: also exclude a property mis-tagged with the wrong
            // listing_type but sitting on the OTHER market's status. Blacklist,
            // not whitelist — see WRONG_INTENT_STATUSES for why (a whitelist
            // silently ate every `to_let` rental in the agency).
            $wrongIntent = self::WRONG_INTENT_STATUSES[$listingType] ?? null;
            if ($wrongIntent) {
                $query->where(function (Builder $sub) use ($wrongIntent) {
                    $sub->whereNull('status')
                        ->orWhereRaw('LOWER(TRIM(status)) NOT IN ('
                            . collect($wrongIntent)->map(fn ($s) => "'" . addslashes($s) . "'")->implode(',')
                            . ')');
                });
            }
        }
        // category / property_type stay loose — half-filled listings still appear.
        if ($category)          $strLoose($query, 'category', $category);
        // Spec 13.15: the type rule is the canonical type (a wishlist "Apartment / Flat" also takes a stored "Apartment"/"Flat"),
        // the same one score() applies, so this pre-filter can only drop what score() would drop anyway.
        if (!empty($propertyTypes)) {
            $rawTypes = $this->rawTypeNamesFor($propertyTypes, $match->agency_id ?: null);
            $query->where(function (Builder $q2) use ($rawTypes) {
                $q2->whereNull('property_type')->orWhereRaw("TRIM(property_type) = ''")->orWhereRaw('LOWER(TRIM(property_type)) IN (' . collect($rawTypes)->map(fn ($t) => "'" . addslashes($t) . "'")->implode(',') . ')');
            });
        }

        // Numeric criteria: a property-side value that is NULL *or 0* means the
        // listing simply hasn't captured that figure, and an incomplete listing
        // is never penalised.
        //
        // 2026-09-10 (Johan, live bug — match 671): this used to tolerate NULL
        // only, so a captured-but-unpriced listing (price = 0) was deleted here
        // as though it cost nothing and therefore fell below every buyer's
        // minimum. score() has ALWAYS read 0 the other way — its price gate says
        // "Only gates when the PROPERTY reports a price: 0/null price is
        // incomplete data, not a mismatch", and its beds gate says the same for
        // 0 beds. So the SQL pre-filter was throwing away rows the scorer would
        // have happily scored (the St Michaels On Sea 2-bed scored 60 and was
        // never seen). Same class as the status whitelist above: two halves of
        // one engine disagreeing about what "missing" means. They agree now.
        $numLoose = function (Builder $q, string $col, string $op, int $val) {
            $q->where(function (Builder $q2) use ($col, $op, $val) {
                $q2->whereNull($col)->orWhere($col, 0)->orWhere($col, $op, $val);
            });
        };
        // In relaxed mode the SQL bound is widened into a tolerance band so a
        // near-miss survives to the scoring stage — score() then decays it and
        // the MIN_SCORE_TO_DISPLAY floor drops anything genuinely too far off.
        // Spec 13.15: ONE price rule for every screen - the agency's tolerance above the buyer's maximum, the minimum never relaxed.
        $priceTol = $relaxed ? BuyerMatchRules::toleranceFor($match->agency_id ?: null) : 0.0;
        $countTol = $relaxed ? 1    : 0;    // allow 1 short on beds / baths / garages
        $sizeTol  = $relaxed ? 0.30 : 0.0;  // ±30% floor / erf size band

        // Price must compare against the LISTING'S effective price (rent for a
        // rental, sale price for a sale) — not the raw `price` column, which is
        // 0/null on rentals (the rent lives in `rental_amount`). Mirrors
        // Property::effectivePrice() at the SQL layer since this filter runs
        // before rows are ever hydrated into models.
        $numLoosePrice = function (Builder $q, string $op, int $val) {
            $sql = Property::effectivePriceSql('properties');
            $q->where(function (Builder $q2) use ($sql, $op, $val) {
                $q2->whereRaw("({$sql}) IS NULL")->orWhereRaw("({$sql}) {$op} ?", [$val]);
            });
        };
        if ($priceMin)   $numLoosePrice($query, '>=', (int) $priceMin);
        if ($priceMax)   $numLoosePrice($query, '<=', (int) ceil($priceMax * (1 + $priceTol)));
        if ($bedsMin)    $numLoose($query, 'beds', '>=', max(0, (int) $bedsMin - $countTol));
        if ($bathsMin)   $numLoose($query, 'baths', '>=', max(0, (int) $bathsMin - $countTol));
        if ($garagesMin) $numLoose($query, 'garages', '>=', max(0, (int) $garagesMin - $countTol));
        if ($floorMin)   $numLoose($query, 'size_m2', '>=', (int) floor($floorMin * (1 - $sizeTol)));
        if ($floorMax)   $numLoose($query, 'size_m2', '<=', (int) ceil($floorMax * (1 + $sizeTol)));
        if ($erfMin)     $numLoose($query, 'erf_size_m2', '>=', (int) floor($erfMin * (1 - $sizeTol)));
        if ($erfMax)     $numLoose($query, 'erf_size_m2', '<=', (int) ceil($erfMax * (1 + $sizeTol)));

        // Hard-cutover suburb filter: match by P24 suburb id.
        $suburbIds = self::suburbIdsWithParentAreas(
            $overrides['p24_suburb_ids'] ?? $match->p24SuburbIdList()
        );
        if (!empty($suburbIds)) {
            $query->whereIn('p24_suburb_id', $suburbIds);
        }

        return $query->with(['agent', 'branch'])
            ->get()
            ->map(function (Property $p) use ($match, $priceTol) {
                // $priceTol, not 0.0 — the scorer's price gate must use the same
                // band the SQL above widened by. See RELAXED_PRICE_BAND.
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
     * Batched counts-only equivalent of calling
     * propertiesForMatch($m, ['agent_id' => null, 'include_hidden' => true])
     * once per match — same results, one query per distinct agency instead of
     * one-to-three queries PER MATCH. Built for
     * ContactMatchController::propertyCountsFor() (the "All Core Matches"
     * oversight page), which needs total/visible/hidden counts for
     * potentially hundreds of matches on one page load — the per-match call
     * was the page's N+1.
     *
     * This assumes EXACTLY that override shape (no agent_id constraint,
     * hidden properties counted). It is NOT a general replacement for
     * propertiesForMatch(), which stays SQL-first and untouched for its
     * single-match, usually agent-scoped callers (results(), printList(),
     * candidatesForProperty()) — pushing `WHERE agent_id = X` into SQL is the
     * right, selective thing to do there, and would be wrong here (this path
     * needs every agent's properties, for every match, on one page).
     *
     * Correctness note: matchSurvivesFilters() below is a PHP mirror of
     * propertiesForMatch()'s WHERE-clause logic above (listing type + status,
     * category, property_type, price/beds/baths/garages/floor/erf tolerance
     * bands, suburb ids) and must be kept in lockstep with it — there is
     * deliberately no single shared implementation, because the single-match
     * path filters in SQL and this one filters an already-fetched in-memory
     * candidate set. score() itself IS shared (called unchanged, verbatim) —
     * only the WHERE-equivalent filtering is duplicated, and only here.
     *
     * @param  \Illuminate\Support\Collection<int,ContactMatch>  $matches
     * @return array<int,array{total:int,visible:int,hidden:int}>
     */
    public function propertyCountsForMatches($matches): array
    {
        $counts = [];
        $candidatesByAgency = [];

        foreach ($matches as $match) {
            if (!$match->isCountable()) {
                $counts[$match->id] = ['total' => 0, 'hidden' => 0, 'visible' => 0, 'ids' => [], 'visible_ids' => []];
                continue;
            }

            $agencyId = $match->agency_id;
            if (!array_key_exists($agencyId, $candidatesByAgency)) {
                $candidatesByAgency[$agencyId] = $this->matchableCandidatePool($agencyId);
            }
            $candidates = $candidatesByAgency[$agencyId];

            $resolved = $candidates
                ->filter(fn (Property $p) => $this->matchSurvivesFilters($p, $match))
                ->map(function (Property $p) use ($match) {
                    // AT-503 — the SAME price band propertiesForMatch() hands the scorer. Without it
                    // the gate re-cut at the exact stated ceiling, so a near-miss the buyer's link
                    // and a send DO include was missing here: a never-sent property that the board
                    // never counted as new (the 2026-09-10 drift, in this second copy of the loop).
                    $sc = $this->score($p, $match);
                    $p->setAttribute('match_score', $sc);
                    return $p;
                })
                ->filter(fn (Property $p) => $p->match_score >= self::MIN_SCORE_TO_DISPLAY);

            $hiddenIds = $match->hidden_property_ids ?? [];
            $hidden = $resolved->filter(fn ($p) => in_array($p->id, $hiddenIds, true))->count();
            $counts[$match->id] = [
                'total'   => $resolved->count(),
                'hidden'  => $hidden,
                'visible' => $resolved->count() - $hidden,
                // AT-Core-Matches, "send N new" board badge — the resolved set is
                // already sitting right here; exposing the ids lets the board diff
                // against "ever shared" without a second, per-match resolve pass
                // (the exact N+1 this method's own batching was built to kill).
                'ids'     => $resolved->pluck('id')->all(),
                // AT-503 — the SAME set a send snapshots (resolve(..., false) drops
                // hidden properties). The board's "Send N new" must diff THIS, never
                // 'ids': a hidden property is never in any snapshot, so diffing
                // 'ids' made "Send 1 new" stick forever.
                'visible_ids' => $resolved->reject(fn ($p) => in_array($p->id, $hiddenIds, true))->pluck('id')->values()->all(),
            ];
        }

        return $counts;
    }

    /**
     * The agency-wide "could possibly match something" candidate pool — every
     * non-off-market property regardless of agent/branch (agent_id=null means
     * propertiesForMatch() never constrains by agent for this call shape
     * either), fetched ONCE per agency and reused across every match's filter
     * pass instead of one propertiesForMatch() SQL round-trip per match.
     *
     * Public: used by propertyCountsForMatches() below (agent_id=null,
     * include_hidden=true — the "All Core Matches" oversight page) AND by
     * PropertyMatchScoringService::recomputeForBuyer() when it's given a
     * pre-fetched pool (agent_id=null, include_hidden=false —
     * RegenerateBuyerMatchesJob's per-agency buyer-match regeneration, the
     * SAME propertiesForMatch() N+1 shape, third and fourth time this exact
     * pattern has turned up in one day). Deliberately does NOT eager-load
     * agent/branch — neither caller's scoring path reads either relation.
     */
    public function matchableCandidatePool(?int $agencyId): Collection
    {
        // AT-Core-Matches (2026-09-29) — allow-list, not blacklist. See
        // propertiesForMatch()'s identical clause above for the rationale.
        $allowedStatuses = Property::coreMatchAllowedStatuses($agencyId);
        $query = Property::query()
            ->where(function (Builder $sub) use ($allowedStatuses) {
                $sub->whereNull('status')
                    ->orWhereRaw('LOWER(TRIM(status)) IN ('
                        . collect($allowedStatuses)->map(fn ($s) => "'" . addslashes($s) . "'")->implode(',')
                        . ')');
            });
        if ($agencyId) {
            $query->where('agency_id', $agencyId);
        }

        return $query->get();
    }

    /**
     * PHP mirror of propertiesForMatch()'s per-match WHERE clauses — see that
     * method for the SQL this must stay identical to. agent_id/branch_id are
     * not re-checked here: both callers (propertyCountsForMatches(),
     * PropertyMatchScoringService::recomputeForBuyer()) always use
     * agent_id=null with no branch_id override, so those two conditions are
     * already no-ops in propertiesForMatch() too under either call shape.
     * include_hidden DOES differ between callers (true for the oversight
     * counts page, false for buyer-match regeneration), so it's the one
     * parameter this takes rather than assuming a single fixed shape.
     */
    private function matchSurvivesFilters(Property $p, ContactMatch $match, bool $includeHidden = true): bool
    {
        // Both callers always evaluate in relaxed mode — same as
        // propertiesForMatch()'s default ($overrides['relaxed'] never set by
        // either).
        $priceTol = BuyerMatchRules::toleranceFor($match->agency_id ?: null);
        $countTol = 1;
        $sizeTol  = 0.30;

        if (!$includeHidden && !empty($match->hidden_property_ids) && in_array($p->id, $match->hidden_property_ids, true)) {
            return false;
        }

        $listingType = $match->listing_type;
        if ($listingType) {
            if ($p->listing_type !== $listingType) {
                return false;
            }
            // Mirror of propertiesForMatch()'s WRONG_INTENT_STATUSES clause.
            $wrongIntent = self::WRONG_INTENT_STATUSES[$listingType] ?? null;
            if ($wrongIntent !== null && $p->status !== null
                && in_array(strtolower(trim((string) $p->status)), $wrongIntent, true)) {
                return false;
            }
        }

        $category = $match->category;
        if ($category && $p->category !== null && $p->category !== $category) {
            return false;
        }

        $propertyTypes = $match->propertyTypeList();
        if (!empty($propertyTypes) && trim((string) $p->property_type) !== ''
            && !in_array(strtolower(trim((string) $p->property_type)), $this->rawTypeNamesFor($propertyTypes, $match->agency_id ?: null), true)) {
            return false;
        }

        $numLooseOk = function ($val, string $op, int $threshold): bool {
            // NULL or 0 = the listing never captured this figure. Mirrors
            // propertiesForMatch()'s $numLoose and score()'s own gates, which
            // both read 0 as "incomplete", not as a real value of zero.
            if ($val === null || (int) $val === 0) {
                return true; // incomplete listings are never penalised
            }
            return $op === '>=' ? ((int) $val >= $threshold) : ((int) $val <= $threshold);
        };

        // effectivePrice() collapses "no data" to 0.0 rather than null — treat
        // <= 0 as null here too, so an incomplete listing still isn't penalised
        // (matches the NULL-tolerant semantics $numLooseOk already implements).
        $effPrice = $p->effectivePrice() > 0 ? $p->effectivePrice() : null;
        if ($match->price_min && !$numLooseOk($effPrice, '>=', (int) $match->price_min)) return false;
        if ($match->price_max && !$numLooseOk($effPrice, '<=', (int) ceil($match->price_max * (1 + $priceTol)))) return false;
        if ($match->beds_min && !$numLooseOk($p->beds, '>=', max(0, (int) $match->beds_min - $countTol))) return false;
        if ($match->baths_min && !$numLooseOk($p->baths, '>=', max(0, (int) $match->baths_min - $countTol))) return false;
        if ($match->garages_min && !$numLooseOk($p->garages, '>=', max(0, (int) $match->garages_min - $countTol))) return false;
        if ($match->floor_size_min && !$numLooseOk($p->size_m2, '>=', (int) floor($match->floor_size_min * (1 - $sizeTol)))) return false;
        if ($match->floor_size_max && !$numLooseOk($p->size_m2, '<=', (int) ceil($match->floor_size_max * (1 + $sizeTol)))) return false;
        if ($match->erf_size_min && !$numLooseOk($p->erf_size_m2, '>=', (int) floor($match->erf_size_min * (1 - $sizeTol)))) return false;
        if ($match->erf_size_max && !$numLooseOk($p->erf_size_m2, '<=', (int) ceil($match->erf_size_max * (1 + $sizeTol)))) return false;

        $suburbIds = self::suburbIdsWithParentAreas($match->p24SuburbIdList());
        if (!empty($suburbIds) && !in_array((int) $p->p24_suburb_id, $suburbIds, true)) {
            return false;
        }

        return true;
    }

    /**
     * Batched equivalent of calling propertiesForMatch($m, ['agent_id' => null,
     * 'include_hidden' => false]) once per $match and keeping the best score
     * per property across all of them — same result,
     * count($candidatePool)-worth of in-memory filtering instead of
     * count($matches) SQL round-trips.
     *
     * Built for PropertyMatchScoringService::recomputeForBuyer(), which
     * previously called propertiesForMatch() once per wishlist for every one
     * of an agency's buyer contacts (388 contacts on agency 1 — the exact
     * same propertiesForMatch() N+1 shape as
     * ContactMatchController::propertyCountsFor(), just triggered from a
     * different call site). $candidatePool is expected to come from
     * matchableCandidatePool() above, fetched ONCE per job run and shared
     * across every contact — not re-fetched per contact.
     *
     * @param  \Illuminate\Support\Collection<int,ContactMatch>  $matches
     * @param  \Illuminate\Support\Collection<int,Property>      $candidatePool
     * @return array<int,array{score:int,tier:?string}> keyed by property_id
     */
    public function bestScoreAcrossMatches($matches, Collection $candidatePool): array
    {
        $best = [];
        foreach ($matches as $match) {
            if (!$match->isCountable()) {
                continue;
            }
            foreach ($candidatePool as $p) {
                if (!$this->matchSurvivesFilters($p, $match, includeHidden: false)) {
                    continue;
                }
                $score = $this->score($p, $match);
                if ($score < self::MIN_SCORE_TO_DISPLAY) {
                    continue;
                }
                if (!isset($best[$p->id]) || $score > $best[$p->id]['score']) {
                    $best[$p->id] = ['score' => $score, 'tier' => self::tierFor($score)];
                }
            }
        }

        return $best;
    }

    /**
     * Hard-filter SQL — applies match criteria as WHERE clauses against contact_matches
     * given a candidate property.  Returns true only if the *match* could plausibly
     * cover this *property*. (Reverse of propertiesForMatch.)
     */
    protected function applyHardFilters(Builder $query, Property $property): void
    {
        $price    = (int) $property->effectivePrice();
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

        // AT-432 — a buyer who will not bid at an auction (open_to_auction = false)
        // is never matched to an auction lot. Default true, so every existing
        // wishlist is unaffected.
        if ($property->isAuction()) {
            $query->where('open_to_auction', true);
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

        // AT-289 suburb gate, moved here (was previously bolted on per-caller —
        // e.g. MIC, the demand-count accessor — leaving matchesForProperty()'s
        // OWN callers, principally the property page's Core Matches tab,
        // unprotected). propertiesForMatch() already hard-filters suburb this
        // way in the opposite direction; this is the missing reverse half, so
        // every consumer of applyHardFilters() gets it uniformly. Open wishlist
        // (no suburb list) = compatible with anything, same rule as
        // suburbCompatible().
        if ($property->p24_suburb_id) {
            $query->where(function (Builder $q) use ($property) {
                $q->whereRaw('JSON_LENGTH(COALESCE(p24_suburb_ids, JSON_ARRAY())) = 0')
                    ->orWhereRaw('JSON_CONTAINS(COALESCE(p24_suburb_ids, JSON_ARRAY()), ?)', [(string) (int) $property->p24_suburb_id]);
            });
        }

        // Hidden / must-have filtering happens in PHP after fetch (JSON columns).
    }

    /**
     * 0-100 score for a (property, match) pair. The int every caller has always used; assess() has the detail.
     *
     * @param  float|null  $priceBandPct  null (the normal call) = the agency's "price tolerance above max" setting (spec 13.15);
     *                                    a number overrides it for ad-hoc callers.
     */
    public function score(Property $property, ContactMatch $match, ?float $priceBandPct = null): int
    {
        return $this->assess($property, $match, $priceBandPct)['score'];
    }

    /**
     * The one verdict on a (property, match) pair (.ai/specs/mic-work-tab.md section 13.15). BuyerMatchRules decides the hard
     * filters (a failure is 0 and the property is not a match); among the properties that pass, only the criteria the buyer
     * actually stated count, so a wishlist that states little scores 100 on everything that fits it. A criterion the property
     * has no value for is skipped and listed in `unverified`, and anything unverified cannot score "strong" (it tops out one
     * point under TIER_STRONG_MIN), so the agent knows why.
     *
     * @return array{score:int, tier:?string, unverified:list<string>, reason:?string}
     */
    public function assess(Property $property, ContactMatch $match, ?float $priceBandPct = null): array
    {
        $check = $this->rules()->check($property, $match, $priceBandPct);

        return $this->assessFrom($check, $property, $match, $priceBandPct);
    }

    /**
     * The verdict AND the field-by-field picture for the "Buyer wants vs property has" panel (spec 13.18): one pass of the rules (evaluate, run to the
     * end), the score worked out from that same pass - the card shows exactly what the filters decided, nothing is calculated twice.
     *
     * @return array{score:int, tier:?string, unverified:list<string>, reason:?string, fields:list<array{key:string,label:string,wants:string,has:string,state:string}>,
     *               must_haves:list<array{label:string,state:string}>, deal_breakers:list<array{label:string,state:string}>}
     */
    public function fit(Property $property, ContactMatch $match, ?float $priceBandPct = null): array
    {
        $e = $this->rules()->evaluate($property, $match, $priceBandPct, true);
        $check = ['ok' => $e['reason'] === null, 'reason' => $e['reason'], 'unverified' => $e['reason'] === null ? $e['unverified'] : []];

        return $this->assessFrom($check, $property, $match, $priceBandPct) + ['fields' => $e['fields'], 'must_haves' => $e['must_haves'], 'deal_breakers' => $e['deal_breakers']];
    }

    private function rules(): BuyerMatchRules
    {
        return $this->rules ??= new BuyerMatchRules($this, app(\App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver::class));
    }

    /** @param array{ok:bool, reason:?string, unverified:list<string>} $check */
    private function assessFrom(array $check, Property $property, ContactMatch $match, ?float $priceBandPct): array
    {
        if (! $check['ok']) {
            return ['score' => 0, 'tier' => null, 'unverified' => [], 'reason' => $check['reason']];
        }
        $unverified = $check['unverified'];
        $skip = array_flip($unverified);
        $bandPct = $priceBandPct ?? BuyerMatchRules::toleranceFor($match->agency_id ?: ($property->agency_id ?: null));

        $mustHaves = $match->must_have_features ?? [];
        $components = [];
        // Substantial components carry a match on their own; cosmetic ones (category, type, sizes) cannot (Johan, 2026-08-11/12:
        // a near-blank wishlist must not give 100% on everything, and a wishlist means exactly what it says).
        $hasSubstantialSignal = false;

        if (($match->price_min || $match->price_max) && ! isset($skip['price'])) {
            $components[] = [25, $this->priceFitRatio($property, $match, $bandPct)];
            $hasSubstantialSignal = true;
        }
        if (! empty($match->p24SuburbIdList())) {
            $components[] = [20, $this->suburbFitRatio($property, $match)];
            $hasSubstantialSignal = true;
        }
        if ($match->beds_min && ! isset($skip['bedrooms'])) {
            $components[] = [8, $this->minMetRatio((int) $property->beds, (int) $match->beds_min)];
            $hasSubstantialSignal = true;
        }
        if ($match->baths_min && ! isset($skip['bathrooms'])) {
            $components[] = [7, $this->minMetRatio((int) $property->baths, (int) $match->baths_min)];
            $hasSubstantialSignal = true;
        }
        if ($match->garages_min && ! isset($skip['garages'])) {
            $components[] = [5, $this->minMetRatio((int) $property->garages, (int) $match->garages_min)];
            $hasSubstantialSignal = true;
        }
        // Category and type already passed the hard rules (a mismatch is excluded, an unknown one is unverified).
        if ($match->category && ! isset($skip['category'])) {
            $components[] = [5, 1.0];
        }
        if (! empty($match->propertyTypeList()) && ! isset($skip['property type'])) {
            $components[] = [5, 1.0];
        }
        if (($match->floor_size_min || $match->floor_size_max) && ! isset($skip['floor size'])) {
            $components[] = [5, 1.0];
        }
        if (($match->erf_size_min || $match->erf_size_max) && ! isset($skip['erf size'])) {
            $components[] = [5, 1.0];
        }
        if (! isset($skip['features'])) {
            $wants = $match->nice_to_have_features ?? [];
            if (! empty($wants) && $this->propertyHasStructuredFeatures($property)) { // no feature list on the property: a wish we cannot check is skipped, not scored 0
                $hits = 0;
                foreach ($wants as $f) {
                    if ($this->propertyHasFeature($property, (string) $f)) {
                        $hits++;
                    }
                }
                $components[] = [15, $hits / count($wants)];
                $hasSubstantialSignal = true;
            }
            if (! empty($mustHaves)) {
                $components[] = [15, 1.0]; // every must-have is present: a missing one already excluded the property
                $hasSubstantialSignal = true;
            }
        }

        if (empty($components) || ! $hasSubstantialSignal) {
            // Nothing the buyer stated can be checked: a wishlist of must-haves alone is a full match; a property we could not
            // verify anything on is shown at the display floor, never higher; a wishlist that states nothing scores 0 (AT-71).
            $score = $unverified !== [] ? self::MIN_SCORE_TO_DISPLAY : (! empty($mustHaves) ? 100 : 0);
        } else {
            $totalWeight = 0;
            $earned = 0.0;
            foreach ($components as [$weight, $fit]) {
                $totalWeight += $weight;
                $earned += $weight * $fit;
            }
            $score = (int) round(min(100, max(0, $earned / $totalWeight * 100)));
        }

        if ($unverified !== [] && $score >= self::TIER_STRONG_MIN) {
            $score = self::TIER_STRONG_MIN - 1;
        }

        return ['score' => $score, 'tier' => self::tierFor($score), 'unverified' => $unverified, 'reason' => null];
    }

    /**
     * Every stored spelling of the wishlist's property types, lower-cased: the wishlist's own names plus every raw type the MIC type
     * map files under the same canonical type (spec 13.15). A wishlist name the map cannot read stays as written (never widened, never
     * an exclusion of anything else).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function rawTypeNamesFor(array $names, ?int $agencyId): array
    {
        $resolver = app(\App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver::class);
        $keys = [];
        $out = [];
        foreach ($names as $n) {
            $out[] = strtolower(trim((string) $n));
            $k = $resolver->keyFor($n, $agencyId);
            if ($k !== null && $k !== 'other') {
                $keys[$k] = true;
            }
        }
        foreach ($resolver->map($agencyId) as $raw => $k) {
            if (isset($keys[$k])) {
                $out[] = (string) $raw;
            }
        }

        return array_values(array_unique($out));
    }

    private ?BuyerMatchRules $rules = null;

    /** Does the property carry structured feature data at all? (No = every feature rule is "unknown", not a pass or a fail.) */
    public function featuresKnown(Property $property): bool
    {
        return $this->propertyHasStructuredFeatures($property);
    }

    public function hasFeature(Property $property, string $feature): bool
    {
        return $this->propertyHasFeature($property, $feature);
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
        $price = (int) $property->effectivePrice();
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

        // Inside the tolerance band but OUTSIDE the buyer's stated range: decay
        // across the band instead of paying full price marks.
        //
        // 2026-09-10: without this, handing score() a band (which is what makes
        // relaxed near-misses reachable at all) would pay a property 30% over
        // budget the SAME price score as one bang inside the range — a live
        // R11 400 rental scored 100 against a R10 000 ceiling. That is a worse
        // version of the very complaint that created the price hard gate on
        // 2026-08-11 ("Lucille Maxwell's R951,501 ceiling matched 88-91% on a
        // property ~R130k over budget"). .ai/specs/matches.md §5.1 is explicit
        // that a near-miss is "surfaced with a decayed match_score"; this is
        // where it decays.
        //
        // A caller passing no band ($bandPct = 0.0) is unaffected: minFull/
        // maxFull then equal min/max, so neither branch below can be entered
        // and the behaviour is byte-for-byte what it was.
        if ($max && $price > $max) {
            return max(0.0, 1 - ($price - $max) / max(1, $maxFull - $max));
        }
        if ($min && $price < $min) {
            return max(0.0, 1 - ($min - $price) / max(1, $min - $minFull));
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
    /**
     * A wishlist's suburbs PLUS their parent areas (Johan's ruling,
     * 2026-09-10) — "Margate Beach" also means "Margate", "Uvongo Beach" also
     * means "Uvongo". See P24Suburb::withParentAreaIds() for the rule and why
     * it is scoped per city.
     *
     * Every suburb decision in this class routes through here — the SQL
     * candidate query, suburbCompatible()'s hard gate, and the
     * matchSurvivesFilters() mirror — so the query and the scorer can never
     * disagree about which areas a buyer asked for. That disagreement is
     * exactly the class of bug this file was just cleaned of.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    public static function suburbIdsWithParentAreas(array $ids): array
    {
        return empty($ids) ? [] : \App\Models\P24Suburb::withParentAreaIds($ids);
    }

    public function suburbCompatible(Property $property, ContactMatch $match): bool
    {
        $ids = self::suburbIdsWithParentAreas($match->p24SuburbIdList());
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
        // "no_stairs" is a POSITIVE property fact (the agent ticked "No Stairs"),
        // not the absence of "Stairs" — an untagged property is unknown, so it must
        // not satisfy a No Stairs requirement. Handled before the generic no_X rule.
        if ($key === 'no_stairs') {
            return in_array('no_stairs', $this->propertyFeatureTokens($property), true);
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

        // Johan's ruling (2026-08-04) — structured features_json ONLY. A
        // description/headline text-scan fallback previously lived here; it
        // was removed because it let a property "match" a must-have it was
        // never actually tagged with (the Alomsee case: the listing's prose
        // happened to mention the ocean, so it silently passed a "sea view"
        // must-have it was never marked with in the structured data). The
        // agent-facing fix for the underlying gap — "this feature is in your
        // description but not marked, mark it" — is a separate, not-yet-built
        // follow-up; matching itself must not paper over an unmarked feature.
        return in_array($want, $this->propertyFeatureTokens($property), true);
    }

    /**
     * True when the listing carries any structured feature data (a non-empty
     * features_json, OR a Pool space tagged in spaces_json). Used to decide
     * whether a must-have failure is a genuine mismatch (data present,
     * feature absent) or merely unknown (no data).
     */
    protected function propertyHasStructuredFeatures(Property $property): bool
    {
        $raw = $property->features_json ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw) && !empty($raw)) return true;

        // A tagged Pool space is itself structured data for pool_own/
        // pool_communal must-haves, even when features_json is empty.
        return !empty($property->poolTokens());
    }

    /**
     * The set of canonical feature tokens a property positively HAS.
     *
     * Handles both features_json shapes:
     *   • array-of-strings ["Pet Friendly", "Pool"] — every entry is a present feature
     *   • dict {"pool": true, "pets_allowed": false, "listing_visibility": "Public"}
     *     — only truthy boolean/"yes"/"1" flags count; meta strings are skipped.
     *
     * Also merges 'pool'/'pool_own'/'pool_communal' derived from the Pool
     * space in spaces_json (Property::poolTokens()) — the canonical own-vs-
     * communal signal, independent of whatever features_json separately says.
     *
     * @return string[]
     */
    protected function propertyFeatureTokens(Property $property): array
    {
        $raw = $property->features_json ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $out = [];
        if (is_array($raw)) {
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
        }

        // 'garage' bridge (2026-08-18, live incident — contact 17097/match 349
        // returned ZERO Core Matches for an otherwise-plain sale wishlist).
        // 'garage' is a normal FEATURE_OPTIONS checkbox agents pick as a
        // must-have, but garage COUNT is tracked on the dedicated `garages`
        // numeric column (garages_min already scores this separately below),
        // never duplicated as a "Garage" text tag inside features_json. Every
        // property with real structured features_json data (so the must-have
        // gate above doesn't skip as "unknown") but no redundant "Garage" tag
        // therefore hard-failed a 'garage' must-have unconditionally — not a
        // reconcile-merge regression, confirmed identical on pre-merge live
        // (712f937b2). Same bridge pattern as poolTokens() below: a property
        // fact the property row already carries becomes a positive token.
        if ((int) ($property->garages ?? 0) > 0) {
            $out[] = 'garage';
        }

        return array_values(array_unique(array_merge($out, $property->poolTokens())));
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
            // NOT collapsed to plain 'pool' — own vs communal is the whole
            // point of these two tokens (Property::poolTokens()).
            'own_pool'          => 'pool_own',
            'private_pool'      => 'pool_own',
            'private_swimming_pool' => 'pool_own',
            'communal_pool'     => 'pool_communal',
            'shared_pool'       => 'pool_communal',
            'complex_pool'      => 'pool_communal',
            'solar_power'       => 'solar',
            'solar_panels'      => 'solar',
            'solar_panel'       => 'solar',
            'solar_geyser'      => 'solar',
            'garages'           => 'garage',
            // 2026-08-18 live incident (contact 17097/match 349, Falan) — a
            // property tagged "Single Garage"/"Double Garage" canonicalized to
            // single_garage/double_garage, never plain 'garage', so it failed
            // a garage must-have despite the tag explicitly saying so. Two of
            // the 4 properties this incident affects had exactly this tag.
            'single_garage'     => 'garage',
            'double_garage'     => 'garage',
            'triple_garage'     => 'garage',
            'granny_flat'       => 'flatlet',
            'cottage'           => 'flatlet',
        ];
        return $synonyms[$key] ?? $key;
    }
}
