<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Mobile-app client identity. One row per real person, keyed by email.
 * A ClientUser may be linked to one or more Contact rows across multiple
 * agencies (one Contact per agency the person is on).
 *
 * Spec: .ai/specs/client-auth.md
 */
class ClientUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'email',
        'password',
        'password_must_change',
        'password_set_at',
        'activated_at',
        'first_login_at',
        'last_login_at',
        'preferred_agency_id',
        'locked_to_agency_id',
        'current_agency_id',
        'created_by_agency_id',
        'last_ip',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password'             => 'hashed',
        'password_must_change' => 'boolean',
        'password_set_at'      => 'datetime',
        'activated_at'         => 'datetime',
        'first_login_at'       => 'datetime',
        'last_login_at'        => 'datetime',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function preferredAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'preferred_agency_id');
    }

    public function lockedToAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'locked_to_agency_id');
    }

    public function currentAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'current_agency_id');
    }

    public function createdByAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'created_by_agency_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(ClientAccessLog::class)->latest();
    }

    public function otps(): HasMany
    {
        return $this->hasMany(ClientOtp::class);
    }

    public function hasPassword(): bool
    {
        return !empty($this->password);
    }
}
