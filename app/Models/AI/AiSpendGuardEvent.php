<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI Spend Safety happening: an alert email, a cut-out, an owner resume, or
 * a threshold change. The history shown on the AI usage agency page and the
 * audit trail for every resume / settings save.
 *
 * Append-only, no delete affordance (non-negotiable #1 honoured by absence).
 *
 * Tenancy: same documented deviation as AiUsageEvent — NOT BelongsToAgency.
 * agency_id NULL is the platform-wide system bucket / a platform settings
 * change, which AgencyScope would hide. The only readers are owner_only routes.
 *
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §A.2, §A.4, §A.5.
 */
final class AiSpendGuardEvent extends Model
{
    protected $table = 'ai_spend_guard_events';

    public const TYPE_ALERT    = 'alert';
    public const TYPE_PAUSE    = 'pause';
    public const TYPE_RESUME   = 'resume';
    public const TYPE_SETTINGS = 'settings';

    protected $fillable = [
        'agency_id',
        'type',
        'spend_zar',
        'threshold_zar',
        'user_id',
        'details',
        'occurred_at',
    ];

    protected $casts = [
        'spend_zar'     => 'decimal:4',
        'threshold_zar' => 'decimal:2',
        'details'       => 'array',
        'occurred_at'   => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
