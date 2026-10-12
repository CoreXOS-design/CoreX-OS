<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22 - the OWNER's "How I want faults handled" for one rental property (one row per property).
 * Fault-flow code never reads this directly: it asks {@see \App\Services\Rentals\RentalOwnerFaultSettings}, which knows what counts as standing authority.
 */
class RentalPropertyFaultSetting extends Model
{
    use BelongsToAgency;

    public const STATUS_NOT_SET = 'not_set';
    public const STATUS_CAPTURED = 'captured_by_agent';
    public const STATUS_CONFIRMED = 'confirmed_by_owner';
    public const STATUS_DISPUTED = 'disputed';

    public const BASIS_PHONE = 'phone_call';
    public const BASIS_WHATSAPP = 'whatsapp';
    public const BASIS_EMAIL = 'email';
    public const BASIS_MANDATE = 'signed_mandate';
    public const BASIS_CARRIED_OVER = 'carried_over';

    /** what an agent may choose as "How the owner told us" (carried_over is only ever written by the migration) */
    public const BASES = [
        self::BASIS_PHONE => 'Phone call',
        self::BASIS_WHATSAPP => 'WhatsApp',
        self::BASIS_EMAIL => 'Email',
        self::BASIS_MANDATE => 'Signed mandate',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'repair_limit' => 'decimal:2', 'emergency_limit' => 'decimal:2', 'callout_limit' => 'decimal:2',
        'capture_occurred_at' => 'date', 'captured_at' => 'datetime', 'confirmed_at' => 'datetime', 'disputed_at' => 'datetime', 'last_sent_to_owner_at' => 'datetime',
        'version' => 'integer',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id')->withoutGlobalScopes();
    }

    public function changes(): HasMany
    {
        return $this->hasMany(RentalPropertyFaultSettingChange::class, 'property_id', 'property_id')->orderByDesc('id');
    }

    public function basisLabel(): ?string
    {
        return $this->capture_basis === self::BASIS_CARRIED_OVER ? 'Carried over from the existing work terms' : (self::BASES[$this->capture_basis] ?? null);
    }
}
