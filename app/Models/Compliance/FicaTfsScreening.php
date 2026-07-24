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
     * undecided review"). A clean `passed` clears the gate — that IS the feature (clean =>
     * the agent proceeds). A CO decision overrides either way. The `trust_auto_pass` flag
     * does NOT change gating; it only governs the label's coverage caveat (see coverageNote()),
     * because a human CO still approves every file — screening never auto-completes approval.
     */
    public function clearsApprovalGate(): bool
    {
        if ($this->decision === 'cleared_false_positive') {
            return true;
        }
        if ($this->decision === 'confirmed_hit') {
            return false;
        }
        return $this->outcome === 'passed';
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
        return $this->auto_pass_trusted
            ? 'Full sanctions coverage confirmed.'
            : 'Checked against the FIC UN Consolidated list only — SA-domestic designation coverage is pending sign-off.';
    }
}
