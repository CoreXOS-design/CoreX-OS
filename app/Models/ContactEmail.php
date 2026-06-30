<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-125 — one of a contact's many email addresses. Exactly one is_primary per
 * contact when any rows exist; the primary's raw value mirrors back to
 * contacts.email (the synced-primary mirror the existing readers use).
 *
 * `email_normalised` (lower(trim)) is the match key — the SAME normalisation the
 * dedup/ingestion resolvers use — so an incoming address can match ANY of a
 * contact's emails (wired in a later step).
 */
class ContactEmail extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'contact_id', 'email', 'label', 'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /** Raw value in, normalised match key (lower+trim) computed alongside it. */
    public function setEmailAttribute($value): void
    {
        $this->attributes['email'] = $value;
        $this->attributes['email_normalised'] = ($value === null || trim((string) $value) === '')
            ? null
            : strtolower(trim((string) $value));
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
