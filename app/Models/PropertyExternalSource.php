<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Source-of-record metadata for a Property imported as another agency's
 * Property24/PrivateProperty listing ("Other Agency Stock").
 *
 * .ai/specs/other-agency-stock.md §3
 */
class PropertyExternalSource extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const PORTAL_P24 = 'p24';
    public const PORTAL_PP  = 'pp';

    protected $fillable = [
        'agency_id', 'property_id',
        'portal', 'listing_ref', 'listing_url',
        'source_agency_name', 'source_agent_name', 'source_agent_phone',
        'source_agent_email', 'source_agent_profile_url',
        'date_posted', 'imported_at', 'imported_by_user_id',
    ];

    protected $casts = [
        'date_posted' => 'date',
        'imported_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }
}
