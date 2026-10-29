<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PolicyVersion (AT-29) — mirrors RmcpVersion, re-anchored per-policy via
 * policy_id. approve() supersedes-then-activates within the SAME policy_id
 * (RMCP scopes by agency_id), so publishing one policy never touches another.
 */
class PolicyVersion extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'policy_id',
        'version_number',
        'title',
        'status',
        'approved_by',
        'approved_at',
        'approver_title',
        'board_approval_document_path',
        'approval_ip',
        'approval_notes',
        'effective_from',
        'superseded_at',
        'superseded_by_version_id',
        'next_review_due',
        'change_notes',
        'created_by',
        'signed_copy_path',
        'signed_copy_name',
        'signed_copy_sha256',
        'signed_copy_uploaded_by',
        'signed_copy_uploaded_at',
        'signed_by_name',
        'signed_on',
        'submitted_to_fic_on',
        'staff_training_sent_at',
        'staff_training_sent_by',
        'staff_training_sent_count',
        'source_kind',
        'source_document_path',
        'source_document_name',
        'source_document_sha256',
        'source_page_count',
    ];

    protected $casts = [
        'approved_at'     => 'datetime',
        'superseded_at'   => 'datetime',
        'effective_from'  => 'date',
        'next_review_due' => 'date',
        'signed_copy_uploaded_at'  => 'datetime',
        'signed_on'                => 'date',
        'submitted_to_fic_on'      => 'date',
        'staff_training_sent_at'   => 'datetime',
    ];

    // ── Relationships ──

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AgencyPolicy::class, 'policy_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PolicySection::class, 'policy_version_id')->orderBy('display_order');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgement::class, 'policy_version_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_version_id');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    // ── Methods ──

    public function canBeEdited(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Supersede-then-activate within the SAME policy_id. Activating a new
     * version flips the prior active version (of this policy only) to
     * superseded — which reverts every staff member to not_started for this
     * policy (status is computed against the active version id). See spec §8.
     */
    /** A policy made from an uploaded document (stored as a PDF, one section per page) rather than typed sections. */
    public function isDocument(): bool
    {
        return $this->source_kind === 'document';
    }

    public function approve(User $user, string $title, ?string $documentPath, ?string $notes): void
    {
        self::where('policy_id', $this->policy_id)
            ->where('status', 'active')
            ->update([
                'status'                   => 'superseded',
                'superseded_at'            => now(),
                'superseded_by_version_id' => $this->id,
            ]);

        $this->update([
            'status'                       => 'active',
            'approved_by'                  => $user->id,
            'approved_at'                  => now(),
            'approver_title'               => $title,
            'board_approval_document_path' => $documentPath,
            'approval_ip'                  => request()->ip(),
            'approval_notes'               => $notes,
        ]);
    }
}
