<?php

namespace App\Models\Platform;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One contract sent to one agency for signature. The signed text is the frozen
 * `body_html_snapshot`, never the live template. Spec §6.2.
 */
class PlatformContractEnvelope extends Model
{
    use SoftDeletes;

    public const STATUSES = ['draft', 'sent', 'viewed', 'signed', 'declined', 'expired', 'voided'];

    protected $table = 'platform_contract_envelopes';

    protected $fillable = [
        'agency_id', 'template_id', 'template_version', 'title', 'body_html_snapshot',
        'signatory_name', 'signatory_email', 'signatory_role', 'token', 'token_expires_at',
        'status', 'sent_at', 'first_viewed_at', 'signed_at', 'declined_at', 'decline_reason',
        'signed_typed_name', 'signature_image', 'signed_ip', 'signed_user_agent',
        'consent_text_snapshot', 'document_hash', 'sealed_pdf_path',
        'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected $hidden = ['token', 'signature_image'];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'sent_at'          => 'datetime',
        'first_viewed_at'  => 'datetime',
        'signed_at'        => 'datetime',
        'declined_at'      => 'datetime',
        'voided_at'        => 'datetime',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PlatformContractTemplate::class, 'template_id')->withTrashed();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PlatformContractAttachment::class, 'envelope_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PlatformContractEvent::class, 'envelope_id');
    }

    /** True when the signing link can still be used. */
    public function isSignable(): bool
    {
        return in_array($this->status, ['sent', 'viewed'], true)
            && !($this->token_expires_at && $this->token_expires_at->isPast());
    }

    public function publicUrl(): string
    {
        return url('/agency-contract/' . $this->token);
    }
}
