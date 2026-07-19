<?php

namespace App\Models;

// TODO(matcher-unification): see backlog ticket — MatchingService and PropertyMatchScoringService still run as two engines.

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;
use App\Observers\ContactMatchObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactMatch extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, SoftDeletes, BelongsToAgency;

    /** A buyer requirement's branch is its contact's. */
    protected function branchParent(): array
    {
        return [\App\Models\Contact::class, 'contact_id'];
    }

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_FULFILLED = 'fulfilled';
    public const STATUS_EXPIRED   = 'expired';

    protected $fillable = [
        'agency_id',
        'contact_id',
        'created_by_user_id',
        'updated_by_user_id',
        'name',
        'share_token',
        'share_slug',
        'status',
        'is_primary',
        'listing_type',
        'category',
        'property_type',
        'property_types',
        'price_min',
        'price_max',
        'beds_min',
        'bedrooms_max',
        'baths_min',
        'garages_min',
        'parking_min',
        'floor_size_min',
        'floor_size_max',
        'erf_size_min',
        'erf_size_max',
        'p24_suburb_ids',
        'suburbs',
        'must_have_features',
        'nice_to_have_features',
        'deal_breakers',
        'notes',
        'hidden_property_ids',
        'hidden_property_reasons',
        'property_view_counts',
        'last_engaged_at',
        'auto_archive_at',
    ];

    protected $casts = [
        'is_primary'            => 'boolean',
        'price_min'             => 'integer',
        'price_max'             => 'integer',
        'beds_min'              => 'integer',
        'bedrooms_max'          => 'integer',
        'baths_min'             => 'integer',
        'garages_min'           => 'integer',
        'parking_min'           => 'integer',
        'floor_size_min'        => 'integer',
        'floor_size_max'        => 'integer',
        'erf_size_min'          => 'integer',
        'erf_size_max'          => 'integer',
        'property_types'        => 'array',
        'p24_suburb_ids'        => 'array',
        'suburbs'               => 'array',
        'must_have_features'    => 'array',
        'nice_to_have_features' => 'array',
        'deal_breakers'         => 'array',
        'hidden_property_ids'   => 'array',
        'hidden_property_reasons' => 'array',
        'property_view_counts'  => 'array',
        'last_engaged_at'       => 'datetime',
        'auto_archive_at'       => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $match) {
            if (empty($match->share_token)) {
                $match->share_token = Str::random(48);
            }
            if (empty($match->status)) {
                $match->status = self::STATUS_ACTIVE;
            }
            $match->syncSuburbsFromP24Ids();
        });
        static::updating(function (self $match) {
            if ($match->isDirty('p24_suburb_ids')) {
                $match->syncSuburbsFromP24Ids();
            }
        });
        static::created(function (self $match) {
            if (empty($match->share_slug)) {
                $match->share_slug = self::generateSlug($match);
                $match->saveQuietly();
            }
        });
    }

    public static function generateSlug(self $match): string
    {
        $match->loadMissing('contact');
        $base = trim(($match->contact->first_name ?? '') . ' ' . ($match->contact->last_name ?? ''));
        $base = $base !== '' ? Str::slug($base) : 'match';

        do {
            $candidate = $base . '-' . strtolower(Str::random(5));
            $exists = static::withoutGlobalScopes()->where('share_slug', $candidate)->exists();
        } while ($exists);

        return $candidate;
    }

    public function sharedUrl(): string
    {
        return route('shared.match', $this->share_slug ?: $this->share_token);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(ContactMatchFeedback::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(ContactMatchNotification::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForListingType(Builder $q, ?string $type): Builder
    {
        return $type ? $q->where('listing_type', $type) : $q;
    }

    public function scopePrimary(Builder $q): Builder
    {
        return $q->where('is_primary', true);
    }

    /**
     * Mark this match as the contact's primary wishlist, demoting any
     * siblings. Wraps the operation in a transaction and bypasses the
     * observer's saved-handler demotion to avoid double work.
     *
     * Recursion-prevention strategy: the observer reads a static flag
     * (ContactMatchObserver::$demoting). We set the flag here so the
     * observer's saved() returns early when our own $this->save() fires.
     */
    public function setAsPrimary(): void
    {
        DB::transaction(function () {
            ContactMatchObserver::$demoting = true;
            try {
                static::where('contact_id', $this->contact_id)
                    ->where('id', '!=', $this->id)
                    ->whereNull('deleted_at')
                    ->update(['is_primary' => false]);
                $this->is_primary = true;
                $this->save();
            } finally {
                ContactMatchObserver::$demoting = false;
            }
        });
    }

    /**
     * Returns the canonical list of property types this match cares about.
     * Reads the new property_types JSON column first, falls back to the
     * legacy property_type string. Per spec D2: every consumer should call
     * this method, never the raw columns, while property_type is being
     * deprecated.
     *
     * @return string[]
     */
    public function propertyTypeList(): array
    {
        if (is_array($this->property_types) && !empty($this->property_types)) {
            return array_values(array_filter(array_map('trim', $this->property_types)));
        }
        if (!empty($this->property_type)) {
            return [$this->property_type];
        }
        return [];
    }

    public function isPropertyHidden(int $propertyId): bool
    {
        return in_array($propertyId, $this->hidden_property_ids ?? []);
    }

    /**
     * Returns the reason an agent gave when hiding this property, or null
     * if the property is not hidden / no reason was recorded.
     */
    public function hiddenReasonFor(int $propertyId): ?string
    {
        $reasons = $this->hidden_property_reasons ?? [];
        return $reasons[(string) $propertyId] ?? null;
    }

    /**
     * Hide a property from this match and store the agent's reason.
     * Idempotent — calling again updates the reason.
     */
    public function hidePropertyWithReason(int $propertyId, string $reason): void
    {
        $ids = $this->hidden_property_ids ?? [];
        if (!in_array($propertyId, $ids)) {
            $ids[] = $propertyId;
        }

        $reasons = $this->hidden_property_reasons ?? [];
        $reasons[(string) $propertyId] = $reason;

        $this->update([
            'hidden_property_ids'     => array_values($ids),
            'hidden_property_reasons' => $reasons,
        ]);
    }

    /**
     * Un-hide a property and drop any stored reason.
     */
    public function unhideProperty(int $propertyId): void
    {
        $ids = array_values(array_filter(
            $this->hidden_property_ids ?? [],
            fn ($id) => $id !== $propertyId
        ));

        $reasons = $this->hidden_property_reasons ?? [];
        unset($reasons[(string) $propertyId]);

        $this->update([
            'hidden_property_ids'     => $ids,
            'hidden_property_reasons' => $reasons,
        ]);
    }

    public function toggleHiddenProperty(int $propertyId, ?string $reason = null): void
    {
        if ($this->isPropertyHidden($propertyId)) {
            $this->unhideProperty($propertyId);
        } else {
            $this->hidePropertyWithReason($propertyId, $reason ?? '');
        }
    }

    public function incrementPropertyView(int $propertyId): void
    {
        $counts = $this->property_view_counts ?? [];
        $key    = (string) $propertyId;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $this->update([
            'property_view_counts' => $counts,
            'last_engaged_at'      => now(),
        ]);
    }

    public function propertyViewCount(int $propertyId): int
    {
        return (int) (($this->property_view_counts ?? [])[(string) $propertyId] ?? 0);
    }

    public function listingTypeLabel(): string
    {
        return $this->listing_type === 'rental' ? 'Rental' : 'For Sale';
    }

    public function priceRangeLabel(): string
    {
        $min = $this->price_min ? 'R ' . number_format($this->price_min) : null;
        $max = $this->price_max ? 'R ' . number_format($this->price_max) : null;
        if ($min && $max) return $min . ' – ' . $max;
        if ($min) return $min . '+';
        if ($max) return 'Up to ' . $max;
        return '—';
    }

    /**
     * Returns the canonical list of suburb NAMES this match cares about.
     * Names are derived from p24_suburb_ids and kept in sync on save; this
     * method just returns the cached array for display.
     */
    public function suburbList(): array
    {
        $list = is_array($this->suburbs) ? $this->suburbs : [];
        return array_values(array_filter(array_map('trim', $list)));
    }

    /**
     * Returns the canonical list of P24 suburb IDs this match cares about.
     *
     * @return int[]
     */
    public function p24SuburbIdList(): array
    {
        $list = is_array($this->p24_suburb_ids) ? $this->p24_suburb_ids : [];
        return array_values(array_unique(array_filter(array_map('intval', $list))));
    }

    // ── AT-71 — countable-buyer gate ─────────────────────────────────────
    //
    // A wishlist is COUNTABLE when it carries enough real criteria to be a
    // meaningful buyer. The default bar (agency-configurable) is "at least one
    // non-empty criteria field" — only a completely empty wishlist is
    // uncountable. Uncountable wishlists are excluded from every match
    // count/list (both engines) so an empty wishlist can never inflate to a
    // full match. Always read CANONICAL columns here (p24_suburb_ids,
    // property_types, beds_min/bedrooms_max) — never the legacy `suburb`
    // (dropped) or derived `suburbs` shadow.

    /**
     * Which canonical criteria GROUPS this wishlist has populated. The single
     * PHP source of truth for "what counts as a non-empty wish field". The SQL
     * mirror lives in countableGroupSql() — keep the two in lock-step.
     *
     * @return string[]
     */
    public function presentCriteriaGroups(): array
    {
        $g = [];
        if ($this->price_min || $this->price_max)                       $g[] = 'price_band';
        if (!empty($this->p24SuburbIdList()))                            $g[] = 'area';
        if ($this->beds_min || $this->bedrooms_max)                     $g[] = 'beds';
        if ($this->baths_min)                                           $g[] = 'baths';
        if ($this->garages_min || $this->parking_min)                   $g[] = 'garages';
        if (!empty($this->propertyTypeList()))                          $g[] = 'property_type';
        if (filled($this->category))                                    $g[] = 'category';
        if ($this->floor_size_min || $this->floor_size_max
            || $this->erf_size_min || $this->erf_size_max)              $g[] = 'size';
        if (!empty($this->must_have_features))                          $g[] = 'must_have';
        if (!empty($this->nice_to_have_features))                       $g[] = 'nice_to_have';
        if (!empty($this->deal_breakers))                               $g[] = 'deal_breakers';
        return $g;
    }

    /**
     * Buyer-facing labels for the match-% BASIS (AT-204 honesty layer).
     *
     * The canonical MatchingService only scores the criteria a buyer actually
     * gave (score = 100 when every specified criterion is met). A budget-only
     * wishlist therefore reads "100%" on everything — mathematically right but
     * it looks fake with no context. These labels let the display layer state
     * WHAT the % is based on ("matches your budget") without ever touching the
     * engine. Short, plain-English (STANDARDS F.8), ordered as the buyer reads.
     *
     * @return string[]
     */
    public function matchBasisLabels(): array
    {
        $map = [
            'price_band'    => 'budget',
            'area'          => 'area',
            'beds'          => 'bedrooms',
            'baths'         => 'bathrooms',
            'garages'       => 'parking',
            'property_type' => 'property type',
            'category'      => 'property type',
            'size'          => 'size',
            'must_have'     => 'must-haves',
            'nice_to_have'  => 'preferences',
            'deal_breakers' => 'deal-breakers',
        ];
        $out = [];
        foreach ($this->presentCriteriaGroups() as $g) {
            $label = $map[$g] ?? null;
            if ($label && !in_array($label, $out, true)) {
                $out[] = $label;
            }
        }
        return $out;
    }

    /**
     * Human sentence describing what a buyer's match % is based on, e.g.
     * "your budget", "your budget & area", "your budget, area & 2 more".
     * Returns '' when the buyer has given no criteria at all.
     */
    public function matchBasisText(): string
    {
        $labels = $this->matchBasisLabels();
        if (empty($labels)) {
            return '';
        }
        if (count($labels) === 1) {
            return 'your ' . $labels[0];
        }
        if (count($labels) === 2) {
            return 'your ' . $labels[0] . ' & ' . $labels[1];
        }
        $head = array_slice($labels, 0, 2);
        $rest = count($labels) - 2;
        return 'your ' . implode(', ', $head) . ' & ' . $rest . ' more';
    }

    /**
     * The buyer's FULL honest brief — every criterion they've actually given,
     * as ordered ['label' => …, 'value' => …] rows for the preferences summary
     * (AT-204). Reads only canonical columns via the existing accessors; never
     * invents a criterion the buyer didn't set. Safe on a bare/partial wishlist.
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function presentBrief(): array
    {
        $rows = [];
        $push = function (string $label, ?string $value) use (&$rows) {
            $value = $value !== null ? trim($value) : '';
            if ($value !== '' && $value !== '—') {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        };

        foreach ($this->presentCriteriaGroups() as $g) {
            switch ($g) {
                case 'price_band':
                    $push('Budget', $this->priceRangeLabel());
                    break;
                case 'area':
                    $areas = $this->suburbList();
                    $push('Areas', !empty($areas)
                        ? implode(', ', $areas)
                        : trim(count($this->p24SuburbIdList()) . ' selected area(s)'));
                    break;
                case 'beds':
                    $push('Bedrooms', $this->presentMinMax($this->beds_min, $this->bedrooms_max));
                    break;
                case 'baths':
                    $push('Bathrooms', $this->baths_min ? $this->baths_min . '+' : null);
                    break;
                case 'garages':
                    $min = $this->garages_min ?: $this->parking_min;
                    $push('Parking', $min ? $min . '+' : null);
                    break;
                case 'property_type':
                    $types = array_map(
                        fn ($t) => ucwords(str_replace('_', ' ', (string) $t)),
                        $this->propertyTypeList()
                    );
                    $push('Property type', implode(', ', array_filter($types)));
                    break;
                case 'category':
                    $push('Category', ucwords(str_replace('_', ' ', (string) $this->category)));
                    break;
                case 'size':
                    $floor = $this->presentSize($this->floor_size_min, $this->floor_size_max);
                    $erf   = $this->presentSize($this->erf_size_min, $this->erf_size_max);
                    $push('Floor size', $floor ? $floor . ' m²' : null);
                    $push('Erf size', $erf ? $erf . ' m²' : null);
                    break;
                case 'must_have':
                    $push('Must have', $this->presentFeatureList($this->must_have_features));
                    break;
                case 'nice_to_have':
                    $push('Nice to have', $this->presentFeatureList($this->nice_to_have_features));
                    break;
                case 'deal_breakers':
                    $push('Avoid', $this->presentFeatureList($this->deal_breakers));
                    break;
            }
        }
        return $rows;
    }

    /** "3+" / "2–4" / "Up to 4" from a min/max pair. */
    private function presentMinMax(?int $min, ?int $max): ?string
    {
        if ($min && $max) {
            return $min === $max ? (string) $min : $min . '–' . $max;
        }
        if ($min) {
            return $min . '+';
        }
        if ($max) {
            return 'Up to ' . $max;
        }
        return null;
    }

    /** "80–120" / "80+" / "Up to 120" for a size range (no unit). */
    private function presentSize(?int $min, ?int $max): ?string
    {
        if ($min && $max) {
            return number_format($min) . '–' . number_format($max);
        }
        if ($min) {
            return number_format($min) . '+';
        }
        if ($max) {
            return 'Up to ' . number_format($max);
        }
        return null;
    }

    /** Humanise a features JSON array into a comma list. */
    private function presentFeatureList($features): ?string
    {
        if (!is_array($features) || empty($features)) {
            return null;
        }
        $clean = array_filter(array_map(
            fn ($f) => ucwords(str_replace(['_', '-'], ' ', trim((string) $f))),
            $features
        ));
        return !empty($clean) ? implode(', ', $clean) : null;
    }

    /**
     * Authoritative (PHP) countability test, honouring the agency setting.
     * Default bar (['any']) → countable iff ≥1 criteria group present.
     * A specific bar (e.g. ['area','price_band']) → all listed groups required.
     */
    public function isCountable(): bool
    {
        $required = $this->agency_id
            ? AgencyContactSettings::minCountableFor((int) $this->agency_id)
            : AgencyContactSettings::DEFAULT_MIN_COUNTABLE_CRITERIA;

        $present = $this->presentCriteriaGroups();

        if (in_array('any', $required, true) || empty($required)) {
            return count($present) > 0;
        }
        return count(array_diff($required, $present)) === 0;
    }

    /**
     * SQL gate mirroring isCountable(). Restricts a query to countable
     * wishlists for the given agency's configured bar. Used by the live
     * matching engine and the raw demand counts.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     */
    public function scopeCountable(Builder $q, int $agencyId): Builder
    {
        self::applyCountableSql($q, $agencyId);
        return $q;
    }

    /**
     * Apply the countable-wishlist WHERE to ANY query builder (Eloquent or raw
     * DB::table). `$prefix` lets callers target an aliased join, e.g. 'cm.'.
     * Reads the agency's configured bar; the per-group SQL is the mirror of
     * presentCriteriaGroups().
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyCountableSql($query, int $agencyId, string $prefix = '')
    {
        $required = AgencyContactSettings::minCountableFor($agencyId);
        $conds    = self::countableGroupSql($prefix);

        if (in_array('any', $required, true) || empty($required)) {
            // Countable if ANY one group is non-empty.
            $query->where(function ($q) use ($conds) {
                foreach ($conds as $sql) {
                    $q->orWhereRaw($sql);
                }
            });
        } else {
            // Countable only if EVERY required group is non-empty.
            foreach ($required as $group) {
                if (isset($conds[$group])) {
                    $query->whereRaw($conds[$group]);
                }
            }
        }

        return $query;
    }

    /**
     * Per-group raw-SQL "is non-empty" predicates — the SQL mirror of
     * presentCriteriaGroups(). `$prefix` (e.g. 'cm.') qualifies the columns.
     *
     * @return array<string,string>
     */
    protected static function countableGroupSql(string $prefix = ''): array
    {
        $c = fn (string $col) => $prefix . $col;
        $jsonNonEmpty = fn (string $col) => "JSON_LENGTH(COALESCE({$c($col)}, JSON_ARRAY())) > 0";

        return [
            'price_band'    => "({$c('price_min')} > 0 OR {$c('price_max')} > 0)",
            'area'          => $jsonNonEmpty('p24_suburb_ids'),
            'beds'          => "({$c('beds_min')} > 0 OR {$c('bedrooms_max')} > 0)",
            'baths'         => "{$c('baths_min')} > 0",
            'garages'       => "({$c('garages_min')} > 0 OR {$c('parking_min')} > 0)",
            'property_type' => '(' . $jsonNonEmpty('property_types')
                                . " OR ({$c('property_type')} IS NOT NULL AND {$c('property_type')} <> ''))",
            'category'      => "({$c('category')} IS NOT NULL AND {$c('category')} <> '')",
            'size'          => "({$c('floor_size_min')} > 0 OR {$c('floor_size_max')} > 0"
                                . " OR {$c('erf_size_min')} > 0 OR {$c('erf_size_max')} > 0)",
            'must_have'     => $jsonNonEmpty('must_have_features'),
            'nice_to_have'  => $jsonNonEmpty('nice_to_have_features'),
            'deal_breakers' => $jsonNonEmpty('deal_breakers'),
        ];
    }

    /**
     * Looks up suburb names for the current p24_suburb_ids and writes them
     * into the `suburbs` column. Called from creating/updating hooks so
     * downstream display code that reads $match->suburbs keeps working
     * without an extra join.
     */
    public function syncSuburbsFromP24Ids(): void
    {
        $ids = $this->p24SuburbIdList();
        if (empty($ids)) {
            $this->suburbs = [];
            return;
        }
        $names = \App\Models\P24Suburb::whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();
        $this->suburbs = array_values(array_filter(array_map('trim', $names)));
    }
}
