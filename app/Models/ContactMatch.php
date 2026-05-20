<?php

namespace App\Models;

// TODO(matcher-unification): see backlog ticket — MatchingService and PropertyMatchScoringService still run as two engines.

use App\Models\Concerns\BelongsToAgency;
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
    use SoftDeletes, BelongsToAgency;

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

    public function toggleHiddenProperty(int $propertyId): void
    {
        $ids = $this->hidden_property_ids ?? [];
        if (in_array($propertyId, $ids)) {
            $ids = array_values(array_filter($ids, fn($id) => $id !== $propertyId));
        } else {
            $ids[] = $propertyId;
        }
        $this->update(['hidden_property_ids' => $ids]);
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
