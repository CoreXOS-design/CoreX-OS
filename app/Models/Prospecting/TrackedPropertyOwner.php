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
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
