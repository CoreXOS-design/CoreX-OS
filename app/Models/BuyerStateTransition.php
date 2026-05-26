<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class BuyerStateTransition extends Model
{
    use BelongsToAgency;

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'contact_id', 'from_state', 'to_state', 'reason',
        'triggered_by_user_id', 'occurred_at',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
}
