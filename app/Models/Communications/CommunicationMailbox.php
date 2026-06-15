<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Email adapter config (AT-32). Agency-held IMAP credentials; password stored
 * encrypted via the 'encrypted' cast.
 */
class CommunicationMailbox extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'communication_mailboxes';

    protected $fillable = [
        'agency_id', 'user_id', 'email_address', 'imap_host', 'imap_port', 'username',
        'encrypted_password', 'auth_type', 'set_by', 'poll_inbox', 'poll_sent',
        'poll_interval_minutes', 'last_polled_at', 'last_uid_seen', 'active',
    ];

    protected $casts = [
        'encrypted_password' => 'encrypted',
        'poll_inbox'         => 'boolean',
        'poll_sent'          => 'boolean',
        'poll_interval_minutes' => 'integer',
        'last_polled_at'     => 'datetime',
        'last_uid_seen'      => 'integer',
        'active'             => 'boolean',
    ];

    // Never serialised. The encrypted password is write-only from every UI/API —
    // the single sanctioned read path is the audited reveal (AT-37), which reads
    // the attribute server-side and logs the access; it never goes through
    // toArray()/toJson().
    protected $hidden = [
        'encrypted_password',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function reveals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MailboxCredentialReveal::class, 'mailbox_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
