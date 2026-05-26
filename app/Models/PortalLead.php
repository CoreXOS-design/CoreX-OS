<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalLead extends Model
{
    use HasFactory, SoftDeletes, BelongsToAgency;

    public const PORTAL_P24 = 'p24';
    public const PORTAL_PP  = 'pp';

    protected $fillable = [
        'agency_id',
        'portal',
        'lead_type',
        'listing_id',
        'listing_portal_ref',
        'contact_id',
        'contact_exists',
        'existing_contact_agent_id',
        'name',
        'email',
        'phone',
        'message',
        'is_whatsapp',
        'lead_source_raw',
        'received_at',
        'notified_at',
    ];

    protected $casts = [
        'contact_exists'  => 'boolean',
        'is_whatsapp'     => 'boolean',
        'lead_source_raw' => 'array',
        'received_at'     => 'datetime',
        'notified_at'     => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'listing_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function existingContactAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'existing_contact_agent_id');
    }

    public function portalLabel(): string
    {
        return $this->portal === self::PORTAL_P24 ? 'Property24' : 'Private Property';
    }
}
