<?php

declare(strict_types=1);

namespace App\Models\Prospecting;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One registered owner on a captured property. CMA deeds lookups can list
 * multiple owners per property (joined " ; " on the source page); this table
 * is the multi-owner storage the single tracked_properties.owner_contact_id
 * column can't represent. See TrackedProperty::owners().
 */
final class TrackedPropertyOwner extends Model
{
    protected $fillable = [
        'tracked_property_id',
        'contact_id',
        'name',
        'id_number',
        'id_type',
        'is_primary',
        'role',
        'ownership_share_pct',
        'deed_reference',
        'ownership_status',
    ];

    protected $casts = [
        'is_primary'          => 'boolean',
        'ownership_share_pct' => 'float',
    ];

    /** Owner-row roles on a deed capture. */
    public const ROLE_OWNER = 'owner';
    public const ROLE_DIRECTOR = 'director';

    /**
     * Multi-owner / ownership-history capture (.ai/specs/deeds-capture.md §7).
     * CURRENT = the registered owner as of the deed dated in the same
     * generation as the panel's sale/registered date; PAST = an earlier
     * transfer's owner (e.g. a prior seller). Only CURRENT rows are linked to
     * a promoted property as its owner — see DeedsCaptureController::promote().
     */
    public const OWNERSHIP_CURRENT = 'current';
    public const OWNERSHIP_PAST = 'past';

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
