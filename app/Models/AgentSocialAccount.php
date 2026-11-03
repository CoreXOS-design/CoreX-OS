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
        'is_platform',
    ];

    protected $casts = [
        'access_token'      => 'encrypted',
        'is_active'         => 'boolean',
        'is_platform'       => 'boolean',
        'token_expires_at'  => 'datetime',
    ];

    protected $hidden = [
        'access_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** AT-527 - the CoreX page connected by a System Owner (never an agency's). */
    public function scopePlatform($query)
    {
        return $query->where('is_platform', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
