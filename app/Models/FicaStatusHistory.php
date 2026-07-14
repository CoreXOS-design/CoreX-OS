<?php

namespace App\Models;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-236 — one immutable row per FICA approval-workflow hop (append-only).
 *
 * Never updated, never deleted: the FIC-Act audit trail. Written through the
 * static ::record() helper so every call site captures the actor's officer tier
 * consistently, and agency_id is stamped explicitly from the submission (not
 * from Auth — safe in queue/console too, AT-203 landmine class).
 */
class FicaStatusHistory extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null; // append-only — created_at only.

    protected $table = 'fica_status_history';

    protected $fillable = [
        'agency_id', 'fica_submission_id', 'from_status', 'to_status',
        'action', 'actor_user_id', 'actor_tier', 'note', 'meta',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FicaSubmission::class, 'fica_submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Record one workflow hop. Derives the actor's tier at action time from their
     * live FICA appointment against the submission's agency, so the audit reflects
     * who they were when they acted.
     *
     * @param array<string,mixed> $meta
     */
    public static function record(
        FicaSubmission $submission,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?User $actor = null,
        ?string $note = null,
        array $meta = []
    ): self {
        return static::create([
            'agency_id'          => $submission->agency_id,
            'fica_submission_id' => $submission->id,
            'from_status'        => $fromStatus,
            'to_status'          => $toStatus,
            'action'             => $action,
            'actor_user_id'      => $actor?->id,
            'actor_tier'         => $actor ? static::tierFor($actor, (int) $submission->agency_id) : 'system',
            'note'               => $note !== null && trim($note) !== '' ? $note : null,
            'meta'               => $meta ?: null,
        ]);
    }

    /** The actor's officer tier for this agency at call time. */
    public static function tierFor(User $actor, int $agencyId): string
    {
        if ($actor->isPrimaryComplianceOfficer($agencyId)) {
            return FicaOfficerAppointment::ROLE_PRIMARY; // 'primary_compliance_officer'
        }
        if ($actor->isMlro()) {
            return FicaOfficerAppointment::ROLE_MLRO;    // 'mlro'
        }
        if (method_exists($actor, 'isOwnerRole') && $actor->isOwnerRole()) {
            return 'admin';
        }
        return 'agent';
    }
}
