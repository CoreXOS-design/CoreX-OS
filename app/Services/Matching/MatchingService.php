<?php

namespace App\Services\Matching;

use App\Models\ContactMatch;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Core matching engine. SQL-first. Applies hard filters via the database,
 * then computes a 0-100 score per (property, match) pair in PHP.
 *
 * See .ai/specs/matches.md §5 for the scoring weights.
 */
class MatchingService
{
    public const MIN_SCORE_TO_SURFACE = 40;

    /** Hard-fail statuses for the auto-match notification job. */
    private const EXCLUDED_FOR_NOTIFY = ['sold', 'withdrawn', 'draft'];

    /** Hard-fail statuses for display lists (match results / shared page). */
    private const EXCLUDED_FOR_DISPLAY = ['sold', 'withdrawn', 'draft', 'archived', 'pending'];

    /**
     * All active matches in the same agency that this property could possibly satisfy.
     * Hard filters are applied in SQL using indexed columns.
     */
    public function candidatesForProperty(Property $property): Collection
    {
        if (!$property->id || in_array($property->status, self::EXCLUDED_FOR_NOTIFY, true)) {
            return collect();
        }

        $query = ContactMatch::query()
            ->active()
            ->where('agency_id', $property->agency_id);

        $this->applyHardFilters($query, $property);

        return $query->with(['contact', 'createdBy'])->get();
    }

    /**
     * All matches that this property satisfies, sorted by score desc.
     * Used by the property page Core Matches tab.
     */
    public function matchesForProperty(Property $property): Collection
    {
        if (in_array($property->status, self::EXCLUDED_FOR_DISPLAY, true)) {
            return collect();
        }

        $candidates = ContactMatch::query()
            ->active()
            ->where('agency_id', $property->agency_id);

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
     * Used by the match results page and the public shared page.
     *
     * @param  array<string,mixed>  $overrides  optional UI filter overrides (price, suburb, etc.)
     */
    public function propertiesForMatch(ContactMatch $match, array $overrides = []): Collection
    {
        $query = Property::query()
            ->whereNotIn('status', self::EXCLUDED_FOR_DISPLAY);

        if ($match->agency_id) {
            $query->where('agency_id', $match->agency_id);
        }

        $includeHidden = !empty($overrides['include_hidden']);
        if (!$includeHidden && !empty($match->hidden_property_ids)) {
            $query->whereNotIn('id', $match->hidden_property_ids);
        }

        $listingType  = $overrides['listing_type']  ?? null; // do not auto-restrict; let scoring weight it
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
        if ($listingType)  $strLoose($query, 'listing_type', $listingType);
        if ($category)     $strLoose($query, 'category', $category);
        if ($propertyType) $strLoose($query, 'property_type', $propertyType);

        // Numeric criteria: allow NULL on the property side too.
        $numLoose = function (Builder $q, string $col, string $op, int $val) {
            $q->where(function (Builder $q2) use ($col, $op, $val) {
                $q2->whereNull($col)->orWhere($col, $op, $val);
            });
        };
        if ($priceMin)     $numLoose($query, 'price', '>=', $priceMin);
        if ($priceMax)     $numLoose($query, 'price', '<=', $priceMax);
        if ($bedsMin)      $numLoose($query, 'beds', '>=', $bedsMin);
        if ($bathsMin)     $numLoose($query, 'baths', '>=', $bathsMin);
        if ($garagesMin)   $numLoose($query, 'garages', '>=', $garagesMin);
        if ($floorMin)     $numLoose($query, 'size_m2', '>=', $floorMin);
        if ($floorMax)     $numLoose($query, 'size_m2', '<=', $floorMax);
        if ($erfMin)       $numLoose($query, 'erf_size_m2', '>=', $erfMin);
        if ($erfMax)       $numLoose($query, 'erf_size_m2', '<=', $erfMax);

        $suburbs = $overrides['suburbs'] ?? $match->suburbList();
        if (!empty($suburbs)) {
            $query->where(function (Builder $q) use ($suburbs) {
                foreach ($suburbs as $s) {
                    $q->orWhere('suburb', 'like', '%' . $s . '%');
                }
            });
        }

        return $query->with(['agent', 'branch'])
            ->get()
            ->map(function (Property $p) use ($match) {
                $p->setAttribute('match_score', $this->score($p, $match));
                return $p;
            })
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
    public function score(Property $property, ContactMatch $match): int
    {
        $mustHaves = $match->must_have_features ?? [];
        if (!empty($mustHaves) && !$this->propertyHasFeatures($property, $mustHaves)) {
            return 0;
        }

        $components = [];

        if ($match->price_min || $match->price_max) {
            $components[] = [25, $this->priceFitRatio($property, $match)];
        }
        if (!empty($match->suburbList())) {
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
        if ($match->category) {
            $components[] = [5, $property->category === $match->category ? 1.0 : 0.0];
        }
        if ($match->property_type) {
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
            return 100; // no criteria specified → every live property is a full match
        }

        $totalWeight = 0;
        $earned      = 0.0;
        foreach ($components as [$weight, $fit]) {
            $totalWeight += $weight;
            $earned      += $weight * $fit;
        }

        return (int) round(min(100, max(0, $earned / $totalWeight * 100)));
    }

    /** Price within [min,max] → 1.0; outside → linearly decays to 0 at ±50% of range. */
    protected function priceFitRatio(Property $property, ContactMatch $match): float
    {
        $price = (int) ($property->price ?? 0);
        if ($price <= 0) return 0.0;

        $min = (int) ($match->price_min ?: 0);
        $max = (int) ($match->price_max ?: 0);

        if ($min && $price < $min) {
            $delta = $min - $price;
            return max(0.0, 1 - $delta / max(1, $min * 0.5));
        }
        if ($max && $price > $max) {
            $delta = $price - $max;
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
        $list   = $match->suburbList();
        $suburb = strtolower((string) $property->suburb);
        if (empty($list))   return 1.0;
        if ($suburb === '') return 0.0;

        foreach ($list as $s) {
            $sLower = strtolower(trim($s));
            if ($sLower === '') continue;
            if ($sLower === $suburb) return 1.0;
        }
        foreach ($list as $s) {
            $sLower = strtolower(trim($s));
            if ($sLower === '') continue;
            if (str_contains($suburb, $sLower) || str_contains($sLower, $suburb)) return 0.5;
        }
        return 0.0;
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

        // Read features_json (array of strings or {name:bool} dict)
        $raw = $property->features_json ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_int($k)) {
                    if (strtolower((string) $v) === $key) return true;
                } else {
                    if (strtolower((string) $k) === $key && $v) return true;
                }
            }
        }

        // Fallback: scan description for keyword
        $hay = strtolower((string) ($property->description ?? '') . ' ' . ($property->headline ?? ''));
        return $hay !== '' && str_contains($hay, $key);
    }
}
