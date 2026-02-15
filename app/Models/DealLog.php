<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DealLog extends Model
{
    protected $fillable = [
        'deal_id',
        'actor_user_id',
        'event_type',
        'from_value',
        'to_value',
        'message',
    ];

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
