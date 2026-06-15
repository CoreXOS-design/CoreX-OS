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
        'agency_id', 'email_address', 'imap_host', 'imap_port', 'username',
        'encrypted_password', 'poll_inbox', 'poll_sent', 'poll_interval_minutes',
        'last_polled_at', 'last_uid_seen', 'active',
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

    protected $hidden = [
        'encrypted_password',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
