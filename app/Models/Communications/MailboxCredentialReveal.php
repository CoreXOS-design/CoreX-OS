<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-37. Append-only audit row written every time a mailbox password is
 * revealed. Not soft-deletable and never edited — an audit trail is immutable
 * by definition. See CommunicationMailbox::reveals().
 */
class MailboxCredentialReveal extends Model
{
    use BelongsToAgency;

    protected $table = 'mailbox_credential_reveals';

    protected $fillable = [
        'agency_id', 'mailbox_id', 'revealed_by', 'revealed_for_user_id',
        'revealed_at', 'ip_address',
    ];

    protected $casts = [
        'revealed_at' => 'datetime',
    ];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationMailbox::class, 'mailbox_id');
    }

    public function revealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revealed_by');
    }

    public function revealedForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revealed_for_user_id');
    }
}
