<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One send of the board resolution for an RMCP version ("round"). Wording and the RMCP fingerprint are frozen at send.
 * A changed draft, a changed board or a cancelled round means a NEW round - an earlier round is never edited or deleted.
 */
class RmcpBoardLetter extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_SENT       = 'sent';
    public const STATUS_COMPLETE   = 'complete';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'agency_id', 'rmcp_version_id', 'round', 'status', 'effective_from', 'next_review_due', 'content_hash', 'letter_html',
        'sent_by', 'sent_at', 'completed_at', 'signed_pdf_path', 'signed_pdf_sha256',
    ];

    protected $casts = [
        'round'           => 'integer',
        'effective_from'  => 'date',
        'next_review_due' => 'date',
        'sent_at'         => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(RmcpVersion::class, 'rmcp_version_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(RmcpBoardSignature::class, 'letter_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }
}
