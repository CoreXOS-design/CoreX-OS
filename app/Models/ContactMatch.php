<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        'name',
        'share_token',
        'share_slug',
        'status',
        'listing_type',
        'category',
        'property_type',
        'price_min',
        'price_max',
        'beds_min',
        'baths_min',
        'garages_min',
        'parking_min',
        'floor_size_min',
        'floor_size_max',
        'erf_size_min',
        'erf_size_max',
        'suburb',
        'suburbs',
        'must_have_features',
        'nice_to_have_features',
        'notes',
        'hidden_property_ids',
        'property_view_counts',
        'last_engaged_at',
        'auto_archive_at',
    ];

    protected $casts = [
        'price_min'             => 'integer',
        'price_max'             => 'integer',
        'beds_min'              => 'integer',
        'baths_min'             => 'integer',
        'garages_min'           => 'integer',
        'parking_min'           => 'integer',
        'floor_size_min'        => 'integer',
        'floor_size_max'        => 'integer',
        'erf_size_min'          => 'integer',
        'erf_size_max'          => 'integer',
        'suburbs'               => 'array',
        'must_have_features'    => 'array',
        'nice_to_have_features' => 'array',
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
     * Returns the canonical list of suburbs this match cares about.
     * Combines new `suburbs` json column with the legacy `suburb` field.
     */
    public function suburbList(): array
    {
        $list = is_array($this->suburbs) ? $this->suburbs : [];
        if (!empty($this->suburb) && !in_array($this->suburb, $list, true)) {
            $list[] = $this->suburb;
        }
        return array_values(array_filter(array_map('trim', $list)));
    }
}
