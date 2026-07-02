<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WhatsApp device registration (AT-32). device_token is stored as a SHA-256
 * hash; the plaintext is shown once at issue time and never persisted.
 */
class CommunicationWaDevice extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'communication_wa_devices';

    protected $fillable = [
        'agency_id', 'user_id', 'wa_number', 'waha_session', 'device_token', 'last_seen_at', 'active',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'active'       => 'boolean',
    ];

    protected $hidden = [
        'device_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /** Resolve an active device by the plaintext token (hashed to match storage). */
    public function scopeForToken($query, string $plaintext)
    {
        return $query->where('device_token', hash('sha256', $plaintext))->where('active', true);
    }

    /** AT-149 — resolve the active device linked to a WAHA server session name. */
    public function scopeForWahaSession($query, string $session)
    {
        return $query->where('waha_session', $session)->where('active', true);
    }
}
