<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Services\ContactDuplicateService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-125 — one of a contact's many phone numbers. Exactly one is_primary per
 * contact when any rows exist; the primary's raw value mirrors back to
 * contacts.phone (the synced-primary mirror that the ~77 existing readers use).
 *
 * `phone_normalised` is the match key — kept in sync via the `phone` mutator
 * using the SAME normalisation as ContactDuplicateService::normalizePhone
 * (last-9 SA mobile core) so the AT-122 ingestion resolver + dedup can match an
 * incoming number against ALL of a contact's identifiers (wired in a later step).
 */
class ContactPhone extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'contact_id', 'phone', 'label', 'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /**
     * Raw value in, normalised match key computed alongside it. A mutator (not a
     * saving hook) so the key is set even on saveQuietly()/mass paths. <9 digits
     * → null key (mirrors ContactDuplicateService::normalizePhone).
     */
    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = $value;
        $this->attributes['phone_normalised'] = ($value === null || trim((string) $value) === '')
            ? null
            : app(ContactDuplicateService::class)->normalizePhone((string) $value);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
