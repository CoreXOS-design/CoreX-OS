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
 * (last-9 SA mobile core for ZA shapes; full digits, uncollapsed, for anything
 * else) so the AT-122 ingestion resolver + dedup can match an incoming number
 * against ALL of a contact's identifiers.
 *
 * `country_iso`/`dial_code` (contact-details Phase 1) — the number's country,
 * defaulting to ZA/+27. Existing rows predate this feature and were captured
 * as SA numbers, so the default backfill is accurate, not a placeholder. Used
 * by WhatsAppNumberFormatter to build a correct click-to-chat deep link — never
 * assume +27 for a number that carries its own dial code.
 *
 * `is_whatsapp`/`is_primary_whatsapp` (contact-details Phase 3) — a number can
 * be flagged as reachable on WhatsApp independently of being the primary
 * CONTACT number; exactly one is_primary_whatsapp per contact when any
 * is_whatsapp rows exist (same single-primary invariant as is_primary, kept
 * by ContactIdentifierService). A contact's office line can be the primary
 * contact number while a personal cell is the primary WhatsApp number.
 */
class ContactPhone extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'contact_id', 'phone', 'country_iso', 'dial_code', 'label', 'contact_identifier_label_id',
        'is_primary', 'is_whatsapp', 'is_primary_whatsapp',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_whatsapp' => 'boolean',
        'is_primary_whatsapp' => 'boolean',
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

    /** Contact-details Phase 2 — the assigned managed label, if any. */
    public function identifierLabel(): BelongsTo
    {
        return $this->belongsTo(ContactIdentifierLabel::class, 'contact_identifier_label_id');
    }
}
