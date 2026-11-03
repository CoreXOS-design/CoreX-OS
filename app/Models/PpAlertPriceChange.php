<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpAlertPriceChange extends Model
{
    use SoftDeletes;

    protected $table = 'pp_alert_price_changes';

    protected $fillable = ['listing_id', 'old_price', 'new_price', 'change_date'];

    protected $casts = [
        'old_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'change_date' => 'date',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(PpAlertListing::class, 'listing_id');
    }
}
