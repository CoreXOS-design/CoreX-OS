<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\DealV2\AgencyServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * .ai/specs/rental-portal-access.md §4/§6 — AT-445. A per-job secure link
 * for a contractor with no CoreX login. Only a hash of the token is ever
 * stored; the raw token exists only in the one URL shown to the agent.
 */
class RentalSecureAccessToken extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'agency_service_provider_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'agency_service_provider_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isLive(): bool
    {
        if ($this->revoked_at) {
            return false;
        }
        if ($this->expires_at->isPast()) {
            return false;
        }
        if (!\App\Models\RentalPortalSetting::contractorLinksEnabledFor($this->agency_id)) {
            return false;
        }
        $workOrder = $this->workOrder;
        if ($workOrder && in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return false;
        }

        return true;
    }

    /**
     * Generate a new raw token (40+ random bytes, hex-encoded) and return it
     * alongside its hash. The raw value is never persisted or logged — only
     * handed back once, to be embedded in the link shown to the agent.
     */
    public static function generateRawToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function findLiveByRawToken(string $rawToken): ?self
    {
        $hash = self::hashToken($rawToken);

        /** @var self|null $record */
        $record = static::withoutGlobalScopes()->where('token_hash', $hash)->first();

        return $record;
    }
}
