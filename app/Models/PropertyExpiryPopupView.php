<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-448 — one row per (user, property, expiry date) the expiring-soon popup
 * has announced. Spec: .ai/specs/at448-property-expiry.md §4.3a.
 *
 * A log, never deleted: the popup excludes listings that have a row for the
 * expiry date they currently carry, which is what makes "announce once" hold.
 */
class PropertyExpiryPopupView extends Model
{
    use BelongsToAgency;

    protected $table = 'property_expiry_popup_views';

    protected $fillable = ['agency_id', 'user_id', 'property_id', 'expiry_date', 'seen_at'];

    protected $casts = [
        'expiry_date' => 'date',
        'seen_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
