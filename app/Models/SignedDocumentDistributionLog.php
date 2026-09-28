<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * §41, 2026-09-28 — App\Services\Distribution\SignedDocumentDistributionService's
 * own append-only audit trail. Polymorphic on purpose (distributable_type/id) —
 * shared across every module that uses the distribution service, never
 * one log table per consumer. Immutable: UPDATED_AT is disabled, a
 * correction is a new row, same convention as every other history table
 * in the rentals module.
 */
class SignedDocumentDistributionLog extends Model
{
    use BelongsToAgency;

    const UPDATED_AT = null;

    protected $fillable = [
        'agency_id',
        'distributable_type',
        'distributable_id',
        'channel',
        'mode',
        'recipient_role',
        'recipient_contact_id',
        'recipient_email',
        'status',
        'message_id',
        'error',
        'sent_by_user_id',
    ];

    public function distributable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
