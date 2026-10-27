<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One board member's signature line on one letter. Name, title and email are copied at send, so the signed record
 * still reads the same if the board list changes later. A signed row is never changed again.
 */
class RmcpBoardSignature extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_VIEWED  = 'viewed';
    public const STATUS_SIGNED  = 'signed';

    protected $fillable = [
        'agency_id', 'letter_id', 'board_member_id', 'user_id', 'member_name', 'member_title', 'member_email', 'token', 'status',
        'sent_at', 'last_sent_at', 'send_count', 'last_send_error', 'viewed_at', 'signed_at',
        'signature_type', 'signature_path', 'typed_name', 'signed_via', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'sent_at'      => 'datetime',
        'last_sent_at' => 'datetime',
        'viewed_at'    => 'datetime',
        'signed_at'    => 'datetime',
        'send_count'   => 'integer',
    ];

    protected $hidden = ['token'];

    public function letter(): BelongsTo
    {
        return $this->belongsTo(RmcpBoardLetter::class, 'letter_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    /** The public link resolves a token without any signed-in user, so it must step outside the agency scope on purpose. */
    public static function findByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('token', $token)->first();
    }
}
