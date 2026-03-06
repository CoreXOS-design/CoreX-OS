<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentSocialAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'platform',
        'platform_page_id',
        'platform_page_name',
        'access_token',
        'token_expires_at',
        'is_active',
    ];

    protected $casts = [
        'access_token'      => 'encrypted',
        'is_active'         => 'boolean',
        'token_expires_at'  => 'datetime',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
