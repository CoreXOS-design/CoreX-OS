<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * .ai/specs/attorney-portal.md §8 - append-only POPIA trail of everything done through (or to) an attorney's access.
 * A row is never changed or removed.
 */
class AttorneyPortalLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENTS = [
        'invited', 'resent', 'revoked', 'link_opened', 'code_sent', 'code_failed', 'signed_in', 'signed_out',
        'deals_listed', 'deal_viewed', 'document_viewed', 'document_downloaded', 'refused', 'session_expired', 'notified',
    ];

    protected $table = 'attorney_portal_logs';

    protected $fillable = ['agency_id', 'access_id', 'event', 'deal_id', 'document_id', 'ip', 'user_agent', 'meta'];

    protected $casts = ['meta' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The attorney portal log is append-only.'));
        static::deleting(fn () => throw new LogicException('The attorney portal log is append-only.'));
    }
}
