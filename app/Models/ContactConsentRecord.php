<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class ContactConsentRecord extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'contact_id', 'agency_id', 'consent_type', 'given_at',
        'given_by_user_id', 'method', 'evidence_document_id',
        'revoked_at', 'revoked_by_user_id', 'revoked_reason', 'notes',
    ];

    protected $casts = [
        'given_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function givenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'given_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('consent_type', $type);
    }
}
