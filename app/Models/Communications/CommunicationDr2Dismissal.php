<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CX-113 Phase G — "not deal correspondence" flag for the DR2 Unfiled Emails screen.
 * See the create-table migration for the reversible, agency-wide, reason-tagged design.
 */
class CommunicationDr2Dismissal extends Model
{
    use BelongsToAgency;

    public const REASON_NOT_DEAL_RELATED   = 'not_deal_related';
    public const REASON_SUPPLIER_MARKETING = 'supplier_marketing';
    public const REASON_PERSONAL           = 'personal';
    public const REASON_DUPLICATE          = 'duplicate';
    public const REASON_OTHER              = 'other';

    public const REASONS = [
        self::REASON_NOT_DEAL_RELATED   => 'Not deal related',
        self::REASON_SUPPLIER_MARKETING => 'Supplier/marketing',
        self::REASON_PERSONAL           => 'Personal',
        self::REASON_DUPLICATE          => 'Duplicate',
        self::REASON_OTHER              => 'Other',
    ];

    protected $fillable = [
        'agency_id', 'communication_id', 'reason', 'reason_other',
        'dismissed_by_user_id', 'dismissed_at', 'restored_by_user_id', 'restored_at',
    ];

    protected $casts = [
        'dismissed_at' => 'datetime',
        'restored_at'  => 'datetime',
    ];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by_user_id');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_user_id');
    }

    public function reasonLabel(): string
    {
        if ($this->reason === self::REASON_OTHER && filled($this->reason_other)) {
            return $this->reason_other;
        }

        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
