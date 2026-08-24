<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit record of a bulk email sent by the System Owner to
 * every CoreX user or to one specific agency's users.
 *
 * Spec: .ai/specs/system-updates-bulk-email.md
 *
 * DELIBERATELY NOT tenant-owned: no BelongsToAgency, AgencyScope never
 * registered — see the migration docblock and spec §3. A broadcast already
 * happened by the time this row exists; there is nothing to edit or delete.
 */
class BulkEmailBroadcast extends Model
{
    use HasFactory;

    public const TARGET_ALL    = 'all';
    public const TARGET_AGENCY = 'agency';

    protected $fillable = [
        'subject',
        'body',
        'target_type',
        'target_agency_id',
        'recipient_count',
        'sent_by_user_id',
    ];

    public function targetAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'target_agency_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    /** Sender name, or "System" when the sending account has been deleted. */
    public function senderName(): string
    {
        return $this->sender?->name ?: 'System';
    }

    /** Human label for the recent-broadcasts table. */
    public function targetLabel(): string
    {
        return $this->target_type === self::TARGET_AGENCY
            ? ($this->targetAgency?->name ?: 'a deleted agency')
            : 'All CoreX Users';
    }
}
