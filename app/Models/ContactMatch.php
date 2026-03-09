<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContactMatch extends Model
{
    protected $fillable = [
        'contact_id',
        'created_by_user_id',
        'share_token',
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
        'notes',
        'hidden_property_ids',
        'property_view_counts',
    ];

    protected $casts = [
        'price_min'      => 'integer',
        'price_max'      => 'integer',
        'beds_min'       => 'integer',
        'baths_min'      => 'integer',
        'garages_min'    => 'integer',
        'parking_min'    => 'integer',
        'floor_size_min' => 'integer',
        'floor_size_max' => 'integer',
        'erf_size_min'       => 'integer',
        'erf_size_max'       => 'integer',
        'hidden_property_ids'   => 'array',
        'property_view_counts'  => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $match) {
            if (empty($match->share_token)) {
                $match->share_token = Str::random(48);
            }
        });
    }

    public function sharedUrl(): string
    {
        return route('shared.match', $this->share_token);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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
        $this->update(['property_view_counts' => $counts]);
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
}
