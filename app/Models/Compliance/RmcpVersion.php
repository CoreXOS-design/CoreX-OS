<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RmcpVersion extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'version_number',
        'title',
        'status',
        'approved_by',
        'approved_at',
        'approver_title',
        'board_approval_document_path',
        'board_letter_id',
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
    ];

    protected $casts = [
        'approved_at'    => 'datetime',
        'superseded_at'  => 'datetime',
        'effective_from' => 'date',
        'next_review_due' => 'date',
        'signed_copy_uploaded_at'  => 'datetime',
        'signed_on'                => 'date',
        'submitted_to_fic_on'      => 'date',
        'staff_training_sent_at'   => 'datetime',
    ];

    // ── Relationships ──

    public function sections(): HasMany
    {
        return $this->hasMany(RmcpSection::class, 'rmcp_version_id')->orderBy('display_order');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Every round of the board approval letter sent for this version (newest first). */
    public function boardLetters(): HasMany
    {
        return $this->hasMany(RmcpBoardLetter::class, 'rmcp_version_id')->orderByDesc('round');
    }

    /** The signed letter this version was approved with - the Approval block reads its signatories and date. */
    public function boardLetter(): BelongsTo
    {
        return $this->belongsTo(RmcpBoardLetter::class, 'board_letter_id');
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

    /** Does the text carry its own Approval block (v2 does)? Then the printed/on-screen page adds no second one. */
    public function hasApprovalSection(): bool
    {
        return $this->sections->contains(fn (RmcpSection $s) => strcasecmp((string) $s->section_number, 'Approval') === 0);
    }

    /**
     * A fingerprint of the text the board is asked to approve (version number, title and every section, in order).
     * Frozen on the letter at send; the Approve step compares it so a section edited after the board signed is caught.
     */
    public function contentHash(): string
    {
        $parts = [(string) $this->version_number, (string) $this->title];
        foreach ($this->sections()->get(['display_order', 'section_number', 'title', 'body_html']) as $s) {
            $parts[] = implode("\x1f", [$s->display_order, $s->section_number, $s->title, $s->body_html]);
        }

        return hash('sha256', implode("\x1e", $parts));
    }

    public function approve(User $user, string $title, ?string $documentPath, ?string $notes, ?int $boardLetterId = null): void
    {
        // Supersede the current active version for this agency
        self::where('agency_id', $this->agency_id)
            ->where('status', 'active')
            ->update([
                'status'                  => 'superseded',
                'superseded_at'           => now(),
                'superseded_by_version_id' => $this->id,
            ]);

        $this->update([
            'status'                       => 'active',
            'approved_by'                  => $user->id,
            'approved_at'                  => now(),
            'approver_title'               => $title,
            'board_approval_document_path' => $documentPath,
            'board_letter_id'              => $boardLetterId,
            'approval_ip'                  => request()->ip(),
            'approval_notes'               => $notes,
        ]);
    }
}
