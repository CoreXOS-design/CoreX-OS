<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserComplianceOverride extends Model
{
    use SoftDeletes, BelongsToAgency;

    public const OVERRIDE_EXEMPT          = 'exempt';
    public const OVERRIDE_WAIVED          = 'waived';
    public const OVERRIDE_NOT_APPLICABLE  = 'not_applicable';

    public const OVERRIDE_TYPE_LABELS = [
        'exempt'          => 'Exempt',
        'waived'          => 'Waived',
        'not_applicable'  => 'Not Applicable',
    ];

    protected $fillable = [
        'user_id',
        'agency_id',
        'compliance_item',
        'override_type',
        'reason',
        'created_by',
        'expires_at',
        'revoked_by',
        'revoked_at',
        'revoke_reason',
    ];

    protected $casts = [
        'expires_at'  => 'date',
        'revoked_at'  => 'datetime',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now()->toDateString());
            });
    }

    // ── Static helpers ──

    /**
     * Returns the active override for a user + compliance item, or null.
     */
    public static function forUserAndItem($userId, string $item): ?self
    {
        return static::where('user_id', $userId)
            ->where('compliance_item', $item)
            ->active()
            ->latest()
            ->first();
    }
}
