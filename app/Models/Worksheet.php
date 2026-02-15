<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Worksheet extends Model
{
    protected $fillable = [
        'user_id',
        'period',

        'personal_net_target',
        'business_net_target',
        'want_net_target',

        'avg_sale_price',
        'commission_percent',
        'paye_percent',

        'agent_split_percent',
        'correctly_priced_percent',

        'current_listings',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
