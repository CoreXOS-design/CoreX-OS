<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class AgentSocialAccount extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
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
