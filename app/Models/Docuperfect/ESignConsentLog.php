<?php

namespace App\Models\Docuperfect;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ESignConsentLog extends Model
{
    protected $table = 'esign_consent_log';

    // Immutable — no mass updates, no updated_at column
    public $timestamps = false;

    protected $fillable = [
        'flow_id',
        'document_id',
        'signature_request_id',
        'signing_party_id',
        'contact_id',
        'id_number_entered',
        'id_verified',
        'consent_text',
        'consent_accepted_at',
        'ip_address',
        'user_agent',
        'device_info',
        'document_hash',
        'created_at',
    ];

    protected $casts = [
        'consent_accepted_at' => 'datetime',
        'created_at' => 'datetime',
        'device_info' => 'array',
        'id_verified' => 'boolean',
    ];

    public function setIdNumberEnteredAttribute($value)
    {
        $this->attributes['id_number_entered'] = encrypt($value);
    }

    public function getIdNumberEnteredAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    /**
     * Consent log records cannot be deleted — FICA requires 5-year retention.
     */
    public function delete()
    {
        throw new \RuntimeException(
            'Consent log records cannot be deleted. FICA requires 5-year retention.'
        );
    }

    /**
     * Consent log records are immutable once created.
     */
    public function update(array $attributes = [], array $options = [])
    {
        throw new \RuntimeException(
            'Consent log records are immutable.'
        );
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class, 'flow_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    public function signingParty(): BelongsTo
    {
        return $this->belongsTo(ESignSigningParty::class, 'signing_party_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
