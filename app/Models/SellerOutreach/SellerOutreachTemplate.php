<?php

declare(strict_types=1);

namespace App\Models\SellerOutreach;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellerOutreachTemplate extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_EMAIL = 'email';

    protected $fillable = [
        'agency_id',
        'name',
        'channel',
        'subject',
        'body',
        'description',
        'is_active',
        'is_default_for_channel',
        'include_tracking_link',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default_for_channel' => 'boolean',
        'include_tracking_link' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default_for_channel', true);
    }
}
