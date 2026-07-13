<?php

namespace App\Models\DealV2;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-217 (DR2, attorney respec) — a working contact person under a service-provider
 * FIRM. A firm (AgencyServiceProvider) has 1..n of these: e.g. BBB Inc → attorney X
 * (via his assistant) and attorney Y (via his paralegal). Agency-scoped, soft-deleted
 * so deactivating a contact preserves historic deal references.
 */
class AgencyServiceProviderContact extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $table = 'agency_service_provider_contacts';

    protected $fillable = [
        'agency_id',
        'service_provider_id',
        'attorney_name',
        'contact_person',
        'role',
        'email',
        'phone',
        'default_delivery_mode',
        'default_channel',
        'is_active',
        'created_by_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function firm(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'service_provider_id');
    }

    /** Human label for a deal-side pick: "Attorney X (via Assistant)". */
    public function getLabelAttribute(): string
    {
        $parts = array_filter([
            $this->attorney_name,
            $this->contact_person ? 'via ' . $this->contact_person : null,
        ]);

        return $parts ? implode(' — ', array_filter([$this->attorney_name, $this->contact_person ? '(via ' . $this->contact_person . ')' : null])) : (string) ($this->email ?? 'Contact');
    }
}
