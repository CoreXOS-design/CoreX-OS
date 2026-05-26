<?php

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceBand extends Model
{
    use SoftDeletes, BelongsToAgency;

    public const LISTING_TYPE_SALE   = 'sale';
    public const LISTING_TYPE_RENTAL = 'rental';

    protected $fillable = [
        'listing_type',
        'name',
        'price_min',
        'price_max',
        'display_order',
    ];

    protected $casts = [
        'price_min'     => 'integer',
        'price_max'     => 'integer',
        'display_order' => 'integer',
    ];

    public function scopeForListingType($query, string $type)
    {
        return $query->where('listing_type', $type);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('price_min');
    }

    /**
     * True if this band includes the given price (rand).
     * price_min is inclusive; price_max is exclusive (or null = unbounded above).
     */
    public function covers(int $priceRand): bool
    {
        if ($priceRand < $this->price_min) {
            return false;
        }
        if ($this->price_max !== null && $priceRand >= $this->price_max) {
            return false;
        }
        return true;
    }
}
