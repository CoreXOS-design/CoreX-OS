<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuyerStateTransition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'contact_id', 'from_state', 'to_state', 'reason',
        'triggered_by_user_id', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
