<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * AT-509 — one switch of a property compliance check (.ai/specs/property-compliance-gate-settings.md §4.2).
 *
 * Append-only: CoreX's permanent evidence of who switched what, when, from where, and the exact disclaimer
 * text they accepted. Rows are only ever created by ComplianceGateSettingsService; an update or delete
 * throws. No global agency scope — every read names its agency explicitly (agency settings page) or is the
 * system owner's deliberate cross-agency view (Dev Settings).
 */
class ComplianceGateChange extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_OFF = 'switched_off';
    public const ACTION_ON  = 'switched_on';

    protected $fillable = [
        'agency_id', 'gate_key', 'gate_label', 'action',
        'user_id', 'user_name', 'user_email', 'ip_address', 'user_agent',
        'disclaimer_version', 'disclaimer_text', 'disclaimer_accepted_at',
    ];

    protected $casts = [
        'disclaimer_accepted_at' => 'datetime',
        'created_at'             => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compliance switch history is append-only.'));
        static::deleting(fn () => throw new LogicException('Compliance switch history is append-only.'));
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSwitchOff(): bool
    {
        return $this->action === self::ACTION_OFF;
    }
}
