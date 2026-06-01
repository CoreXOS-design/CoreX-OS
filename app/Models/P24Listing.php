<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class P24Listing extends Model
{
    use SoftDeletes;

    protected $table = 'p24_listings';

    protected $fillable = [
        'agency_id',
        'p24_listing_number',
        'asking_price',
        'property_type',
        'suburb',
        'area',
        'bedrooms',
        'bathrooms',
        'garages',
        'is_mandated',
        'listing_status',
        'p24_url',
        'first_seen_date',
        'last_seen_date',
        'original_price',
        'times_seen',
    ];

    protected $casts = [
        'asking_price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'is_mandated' => 'boolean',
        'first_seen_date' => 'date',
        'last_seen_date' => 'date',
        'times_seen' => 'integer',
        'bedrooms' => 'integer',
        'bathrooms' => 'integer',
        'garages' => 'integer',
    ];

    // ── Relationships ──

    public function priceChanges(): HasMany
    {
        return $this->hasMany(P24PriceChange::class, 'listing_id');
    }

    // ── Scopes ──

    public function scopeInSuburb($query, string $suburb)
    {
        return $query->where('suburb', $suburb);
    }

    public function scopeInPriceRange($query, float $min, float $max)
    {
        return $query->whereBetween('asking_price', [$min, $max]);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('property_type', $type);
    }

    public function scopeSeenAfter($query, $date)
    {
        return $query->where('first_seen_date', '>=', $date);
    }

    public function scopeSeenBefore($query, $date)
    {
        return $query->where('first_seen_date', '<=', $date);
    }

    public function scopeMandated($query)
    {
        return $query->where('is_mandated', true);
    }

    public function scopeActive($query)
    {
        return $query->where('listing_status', 'active');
    }

    // ── Accessors ──

    public function getDaysOnMarketAttribute(): ?int
    {
        if (!$this->first_seen_date) {
            return null;
        }

        return Carbon::parse($this->first_seen_date)->diffInDays(now());
    }

    public function getPriceChangePercentAttribute(): ?float
    {
        if (!$this->original_price || $this->original_price == 0) {
            return null;
        }

        if ((float) $this->original_price === (float) $this->asking_price) {
            return null;
        }

        return round(
            (($this->asking_price - $this->original_price) / $this->original_price) * 100,
            2
        );
    }
}
