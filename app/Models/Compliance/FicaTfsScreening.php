<?php

namespace App\Models\Compliance;

use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-submission TFS screening outcome — the version-stamped audit-truth of every screen.
 */
class FicaTfsScreening extends Model
{
    protected $fillable = [
        'fica_submission_id', 'agency_id', 'subject_kind',
        'screened_name', 'screened_name_normalised', 'screened_id_number', 'screened_id_normalised', 'screened_dob',
        'outcome', 'auto_pass_trusted', 'reason', 'import_id', 'list_fetched_at',
        'match_count', 'candidates', 'screened_by', 'screened_at',
        'decision', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $casts = [
        'screened_dob'      => 'date',
        'auto_pass_trusted' => 'boolean',
        'candidates'        => 'array',
        'list_fetched_at'   => 'datetime',
        'screened_at'       => 'datetime',
        'decided_at'        => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FicaSubmission::class, 'fica_submission_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SanctionsListImport::class, 'import_id');
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
    }

    /**
     * Does this screening CLEAR the FICA approval gate?
     *
     * Blocks on hit / review_required / error (per Johan: "block on an open hit or an
     * undecided review"). A clean `passed` clears the gate only when the auto-pass is
     * TRUSTED (config tfs.trust_auto_pass — approved 2026-07-24 for the UN list); if trust
     * were turned off, a clean pass would require a CO to clear it. A stale list never
     * produces `passed` (the service downgrades it to review_required), so a trusted pass
     * is always clean AND fresh. A CO decision overrides either way.
     */
    public function clearsApprovalGate(): bool
    {
        if ($this->decision === 'cleared_false_positive') {
            return true;
        }
        if ($this->decision === 'confirmed_hit') {
            return false;
        }
        return $this->outcome === 'passed' && $this->auto_pass_trusted;
    }

    /** True while an unresolved hit/review is blocking approval. */
    public function isBlocking(): bool
    {
        return ! $this->clearsApprovalGate();
    }

    /** Short human status for the UI badge. */
    public function badge(): string
    {
        return match ($this->outcome) {
            'hit'             => 'Sanctions HIT',
            'review_required' => 'Review required',
            'passed'          => 'Screened & passed',
            default           => 'Could not screen',
        };
    }

    /** Panel colour tone. */
    public function tone(): string
    {
        if ($this->decision === 'cleared_false_positive') {
            return 'green';
        }
        return match ($this->outcome) {
            'hit'             => 'red',
            'review_required' => 'amber',
            'passed'          => 'green',
            default           => 'grey',
        };
    }

    /**
     * HONEST COVERAGE LABEL — always shown, so "passed" is never a bare claim.
     * While auto-pass is untrusted we say exactly which list was checked and that
     * SA-domestic coverage is pending, so we never assert more coverage than we have.
     */
    public function coverageNote(): ?string
    {
        if ($this->outcome !== 'passed') {
            return null;
        }
        // Trusted: the provenance line (list + version) is the assertion — no extra caveat.
        // Untrusted (flag turned off): say so, because a clean pass then needs a CO to clear.
        return $this->auto_pass_trusted
            ? null
            : 'Auto-pass is currently disabled — a Compliance Officer must clear this to proceed.';
    }
}
