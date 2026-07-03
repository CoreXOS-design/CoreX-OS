<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-163. Append-only audit row written every time the off-box backup
 * encryption password is revealed. Never edited, never soft-deleted — an audit
 * trail is immutable by definition. NOT agency-scoped (the password is a single
 * box-global secret). See Admin\BackupController::reveal().
 */
class BackupPasswordReveal extends Model
{
    protected $table = 'backup_password_reveals';

    protected $fillable = [
        'revealed_by', 'revealed_by_agency_id', 'revealed_at', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'revealed_at' => 'datetime',
    ];

    public function revealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revealed_by');
    }
}
