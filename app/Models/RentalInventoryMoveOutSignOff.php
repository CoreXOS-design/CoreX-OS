<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Audit 2026-10-19 B5 - one party's sign-off of the MOVE-OUT inventory check (the findings recorded against the move-in inventory's lines
 * when the tenant leaves): signed (a typed name, the time, the recording user, IP and device) or refused (with a reason). Append-only in
 * spirit: a correction ARCHIVES the earlier row and adds a new one (RentalInventory::recordMoveOutSignOff()), nothing is edited or deleted.
 */
class RentalInventoryMoveOutSignOff extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const PARTY_TENANT = 'tenant';
    public const PARTY_LANDLORD = 'landlord';
    public const PARTY_AGENT = 'agent';

    public const DISPOSITION_SIGNED = 'signed';
    public const DISPOSITION_REFUSED = 'refused';

    protected $fillable = [
        'agency_id',
        'rental_inventory_id',
        'party_role',
        'party_contact_id',
        'disposition',
        'signed_name',
        'refusal_reason',
        'signed_ip',
        'signed_user_agent',
        'recorded_by_user_id',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(RentalInventory::class, 'rental_inventory_id');
    }

    public function partyContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'party_contact_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
