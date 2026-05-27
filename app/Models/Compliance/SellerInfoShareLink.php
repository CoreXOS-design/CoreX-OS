<?php

namespace App\Models\Compliance;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class SellerInfoShareLink extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'tier',
        'seller_name',
        'seller_email',
        'agent_message',
        'property_id',
        'contact_id',
        'sent_by_user_id',
        'agency_id',
        'token',
        'expires_at',
        'accessed_count',
        'last_accessed_at',
    ];

    protected $casts = [
        'expires_at'       => 'datetime',
        'last_accessed_at' => 'datetime',
        'accessed_count'   => 'integer',
    ];

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function recordAccess(): void
    {
        $this->increment('accessed_count');
        $this->update(['last_accessed_at' => now()]);
    }
}
