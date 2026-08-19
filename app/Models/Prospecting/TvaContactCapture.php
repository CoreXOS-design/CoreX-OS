<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One TVA (The Virtual Agent) KYC person lookup capture — the person identity
 * half; TvaContactCaptureItem holds the individual phone/email rows. Matched
 * to a deeds-capture suspense record (TrackedProperty) by id_number when one
 * exists; nullable tracked_property_id means it lands standalone.
 *
 * SoftDeletes (2026-08-13) — the Deeds Capture screen's "Remove" action
 * dismisses a capture (wrong details, duplicate) without hard-purging it,
 * per Non-Negotiable #1. Same reversible model as TrackedProperty's own
 * SoftDeletes, which the property-side "Remove" action uses directly.
 */
final class TvaContactCapture extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'captured_by_user_id',
        'tracked_property_id',
        'matched_contact_id',
        'id_number',
        'first_name',
        'surname',
        'source',
        'consent_status',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TvaContactCaptureItem::class);
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class);
    }

    public function matchedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'matched_contact_id');
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
