<?php

namespace App\Models\Docuperfect;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * P0-4 — the signing audit trail is EVIDENCE, not a convenience log.
 *
 * Ceremony §6: the tracker is an audit-ready attribution record — the thing we put in
 * front of a principal or the Ombud to prove who held a document and for how long. An
 * evidence record that the application can quietly edit or delete is not evidence.
 *
 * It used to be append-only by CONVENTION only: the model carried SoftDeletes and
 * overrode nothing, so any caller could have updated or deleted a row. The SoftDeletes
 * came from a blanket sweep (2026_03_11_100002_add_soft_deletes_to_docuperfect_and_
 * rental_tables) that added the trait to every docuperfect table — the audit log was
 * caught in the net, it was never a deliberate decision.
 *
 * Now enforced, mirroring the canonical CoreX immutable-audit pattern
 * (CommsAccessAuditLog): update() and delete() throw, AND the Eloquent events are
 * guarded too. The event guards are not belt-and-braces — they are load-bearing:
 * overriding update() does NOT stop `$log->action = 'x'; $log->save();`, because save()
 * on an existing model goes straight to performUpdate() and never routes through
 * update(). Without the `updating` guard the log would still be quietly editable.
 *
 * Nothing in the codebase mutates or deletes an audit row (traced: every call site is
 * ::log() or ::create(), plus reads), so this closes the hole without breaking a caller.
 */
class SignatureAuditLog extends Model
{
    protected $table = 'signature_audit_log';

    // Append-only — a row is written once and never touched again.
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

    // --- Immutability (the evidence guarantee) ---

    public function update(array $attributes = [], array $options = [])
    {
        throw new DomainException(
            'The signing audit trail is append-only — an audit row cannot be changed.'
        );
    }

    public function delete()
    {
        throw new DomainException(
            'The signing audit trail is append-only — an audit row cannot be deleted. '
            . 'It is the evidence of who held a document and for how long.'
        );
    }

    /**
     * Also block the paths that bypass the overrides above:
     *  - `$log->x = 1; $log->save();` → performUpdate(), never touches update()
     *  - `SignatureAuditLog::destroy($id)` → calls delete() on a fresh instance
     *  - a mutating relationship/cascade
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException(
                'The signing audit trail is append-only — an audit row cannot be changed.'
            );
        });

        static::deleting(function () {
            throw new DomainException(
                'The signing audit trail is append-only — an audit row cannot be deleted.'
            );
        });
    }

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
