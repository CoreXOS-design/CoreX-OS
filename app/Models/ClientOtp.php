<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientOtp extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_user_id',
        'email',
        'purpose',
        'code_hash',
        'expires_at',
        'used_at',
        'attempts',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired()
            && !$this->isUsed()
            && $this->attempts < (int) config('clientauth.otp.max_attempts', 5);
    }
}
