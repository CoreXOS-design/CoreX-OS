<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A captured "the canonical engine missed this" signal: an agent added a
 * property to a buyer's Viewing Pack that was NOT a current Core Match (spec
 * §3). Capture-only — the diagnostic/correction surface is the separate Core
 * Match Intelligence ticket. Snapshots are immutable point-in-time JSON.
 */
class CoreMatchMiss extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'contact_id',
        'property_id',
        'agent_id',
        'viewing_pack_id',
        'buyer_criteria_snapshot',
        'property_attributes_snapshot',
        'captured_at',
    ];

    protected $casts = [
        'buyer_criteria_snapshot'      => 'array',
        'property_attributes_snapshot' => 'array',
        'captured_at'                  => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function viewingPack(): BelongsTo
    {
        return $this->belongsTo(ViewingPack::class);
    }
}
