<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AT-URGENT-2026-09-09 — every change to the outbound mail intercept
 * kill-switch, who/when/direction/reason. "If real mail goes somewhere it
 * should not have, we must be able to see instantly whether the switch was
 * off and who moved it."
 */
class OutboundMailGuardToggleAudit extends Model
{
    public const DIRECTION_INTERCEPT_ON = 'intercept_on';
    public const DIRECTION_INTERCEPT_OFF = 'intercept_off';

    protected $table = 'outbound_mail_guard_toggle_audit';

    protected $fillable = [
        'user_id',
        'direction',
        'reason',
        'environment',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
