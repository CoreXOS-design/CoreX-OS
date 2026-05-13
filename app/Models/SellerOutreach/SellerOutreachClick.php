<?php

declare(strict_types=1);

namespace App\Models\SellerOutreach;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerOutreachClick extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'send_id', 'clicked_at', 'ip_address', 'user_agent', 'geo_country',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function send(): BelongsTo
    {
        return $this->belongsTo(SellerOutreachSend::class, 'send_id');
    }
}
