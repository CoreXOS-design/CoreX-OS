<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalAmountVersion extends Model
{
    use SoftDeletes;

    protected $table = 'rental_amount_versions';

    protected $fillable = [
        'rental_id',
        'effective_from',
        'rent_incl',
        'rent_excl',
        'commission_incl',
        'commission_excl',
        'created_by_user_id',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'rent_incl' => 'decimal:2',
        'rent_excl' => 'decimal:2',
        'commission_incl' => 'decimal:2',
        'commission_excl' => 'decimal:2',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
