<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignatureAuditLog extends Model
{
    use SoftDeletes;

    protected $table = 'signature_audit_log';

    // Immutable — no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'signature_template_id',
        'signature_request_id',
        'action',
        'actor_type',
        'actor_id',
        'actor_name',
        'actor_email',
        'actor_ip_address',
        'actor_user_agent',
        'metadata_json',
        'document_hash',
    ];

    protected $casts = [
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];

    // Action constants
    const ACTION_CREATED = 'created';
    const ACTION_SENT = 'sent';
    const ACTION_VIEWED = 'viewed';
    const ACTION_SIGNED = 'signed';
    const ACTION_COMPLETED = 'completed';
    const ACTION_DECLINED = 'declined';
    const ACTION_EXPIRED = 'expired';
    const ACTION_CANCELLED = 'cancelled';
    const ACTION_REMINDER_SENT = 'reminder_sent';
    const ACTION_WET_INK_UPLOADED = 'wet_ink_uploaded';
    const ACTION_WET_INK_APPROVED = 'wet_ink_approved';
    const ACTION_WET_INK_REJECTED = 'wet_ink_rejected';
    const ACTION_TEAM_ALERT_SENT = 'team_alert_sent';
    const ACTION_MANUAL_REMINDER_SENT = 'manual_reminder_sent';
    const ACTION_DOCUMENT_COMPLETED = 'document_completed';
    const ACTION_SIGNED_PDF_EMAILED = 'signed_pdf_emailed';

    // Actor type constants
    const ACTOR_SYSTEM = 'system';
    const ACTOR_USER = 'user';
    const ACTOR_SIGNER = 'signer';

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function signingRequest()
    {
        return $this->belongsTo(SignatureRequest::class, 'signature_request_id');
    }

    // --- Static factory ---

    public static function log(
        SignatureTemplate $template,
        string $action,
        string $actorType,
        string $actorName,
        ?string $actorEmail = null,
        ?int $actorId = null,
        ?int $requestId = null,
        ?string $ip = null,
        ?string $ua = null,
        ?array $metadata = null,
        ?string $documentHash = null
    ): self {
        return static::create([
            'signature_template_id' => $template->id,
            'signature_request_id' => $requestId,
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'actor_email' => $actorEmail,
            'actor_ip_address' => $ip,
            'actor_user_agent' => $ua,
            'metadata_json' => $metadata,
            'document_hash' => $documentHash,
        ]);
    }
}
