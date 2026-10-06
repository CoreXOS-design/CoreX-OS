<?php

namespace App\Models\PlatformEsign;

use Illuminate\Database\Eloquent\Model;

class Signer extends Model
{
    protected $table = 'platform_esign_signers';

    protected $fillable = [
        'document_id', 'role_key', 'role_label', 'sign_order', 'name', 'email', 'id_number', 'token', 'previous_token_hash', 'status',
        'invited_at', 'first_viewed_at', 'signed_at', 'typed_name', 'initials', 'signature_image', 'signature2_image', 'signed_ip', 'signed_user_agent',
        'consent_text_snapshot', 'reminders_sent', 'last_reminded_at',
    ];

    protected $hidden = ['token', 'previous_token_hash', 'id_number', 'signature_image', 'signature2_image'];

    // id_number is POPIA personal information: encrypted at rest (migration 2026_10_06_170000), like the agreement's bank details.
    protected $casts = ['id_number' => 'encrypted', 'invited_at' => 'datetime', 'first_viewed_at' => 'datetime', 'signed_at' => 'datetime', 'last_reminded_at' => 'datetime'];

    public function document() { return $this->belongsTo(Document::class, 'document_id'); }

    public function hasSigned(): bool
    {
        return $this->status === 'signed';
    }
}
