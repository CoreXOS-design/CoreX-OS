<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P24PriceChange extends Model
{
    use SoftDeletes;

    protected $table = 'p24_price_changes';

    protected $fillable = [
        'listing_id',
        'old_price',
        'new_price',
        'change_date',
    ];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'change_date' => 'date',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(P24Listing::class, 'listing_id');
    }
}
