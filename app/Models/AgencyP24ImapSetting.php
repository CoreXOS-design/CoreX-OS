<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * P24 IMAP per-agency (#3) — each agency's own P24 alert-email mailbox
 * credentials. Password is encrypted at rest via the 'encrypted' cast and
 * never serialised. Mirrors the CommunicationMailbox pattern (AT-32/AT-181).
 */
class AgencyP24ImapSetting extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'agency_p24_imap_settings';

    protected $fillable = [
        'agency_id', 'imap_host', 'imap_port', 'imap_encryption', 'imap_folder',
        'username', 'encrypted_password', 'active',
        'last_polled_at', 'last_error', 'last_error_at', 'consecutive_failures',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'encrypted_password'    => 'encrypted',
        'imap_port'              => 'integer',
        'active'                 => 'boolean',
        'last_polled_at'         => 'datetime',
        'last_error_at'          => 'datetime',
        'consecutive_failures'   => 'integer',
    ];

    protected $hidden = [
        'encrypted_password',
    ];

    /** Fully configured = has enough to attempt a connection. */
    public function isConfigured(): bool
    {
        return filled($this->imap_host) && filled($this->username) && filled($this->encrypted_password);
    }

    public static function forAgency(int $agencyId): ?self
    {
        return static::withoutGlobalScopes()->where('agency_id', $agencyId)->first();
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeConfigured($query)
    {
        return $query->whereNotNull('imap_host')->whereNotNull('username')->whereNotNull('encrypted_password');
    }
}
