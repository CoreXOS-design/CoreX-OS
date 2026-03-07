<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PropertyMarketingPost extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'property_id',
        'user_id',
        'platform',
        'platform_post_id',
        'ad_copy',
        'image_urls',
        'status',
        'published_at',
        'last_synced_at',
        'impressions',
        'reach',
        'likes',
        'comments',
        'shares',
        'link_clicks',
    ];

    protected $casts = [
        'image_urls'     => 'array',
        'published_at'   => 'datetime',
        'last_synced_at' => 'datetime',
        'impressions'    => 'integer',
        'reach'          => 'integer',
        'likes'          => 'integer',
        'comments'       => 'integer',
        'shares'         => 'integer',
        'link_clicks'    => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): ?AgentSocialAccount
    {
        return AgentSocialAccount::where('user_id', $this->user_id)
            ->where('platform', $this->platform)
            ->active()
            ->first();
    }
}
