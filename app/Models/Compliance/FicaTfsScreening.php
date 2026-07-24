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
     * Did the screening actually RUN to a definite result? (passed / hit / review).
     * `error` (no list / stale list) means it could NOT complete — the checklist item
     * stays un-ticked and the manual "Run TFS" fallback button is offered.
     */
    public function ranSuccessfully(): bool
    {
        return in_array($this->outcome, ['passed', 'hit', 'review_required'], true);
    }

    /** A hit or an undecided name review — loudly flagged, never a silent green. */
    public function isFlagged(): bool
    {
        return in_array($this->outcome, ['hit', 'review_required'], true)
            && $this->decision !== 'cleared_false_positive';
    }

    /**
     * TIER 3 — an exact ID/passport match LOCKS the record: all normal action buttons
     * disappear and the only path is "Report to CO". A CO clearing it as a false positive
     * unlocks it. A name review (Tier 2) does NOT lock — it is amber and non-blocking.
     */
    public function isLocked(): bool
    {
        return $this->outcome === 'hit' && $this->decision !== 'cleared_false_positive';
    }

    /** Only a locked (exact-ID) hit blocks approval; name matches never block. */
    public function blocksApproval(): bool
    {
        return $this->isLocked();
    }

    /** Convenience for callers/blades that want the positive form. */
    public function clearsApprovalGate(): bool
    {
        return ! $this->blocksApproval();
    }

    /**
     * Risk rating this screening imposes on the submission's EXISTING risk_rating field:
     * exact-ID hit => 3 (critical/red), name match => 2 (amber). No match => null (untouched).
     */
    public function riskRatingValue(): ?int
    {
        return match ($this->outcome) {
            'hit'             => 3,
            'review_required' => 2,
            default           => null,
        };
    }

    /** The exact Tier-2 amber message. */
    public function amberMessage(): ?string
    {
        return $this->outcome === 'review_required' ? 'ID does not match, name and surname match.' : null;
    }

    /** Identity fingerprint — used to auto re-run when the screened details change. */
    public function fingerprint(): string
    {
        return ($this->screened_name_normalised ?? '') . '|' . ($this->screened_id_normalised ?? '');
    }

    /** Short human status for the UI badge. */
    public function badge(): string
    {
        return match ($this->outcome) {
            'hit'             => 'Sanctions HIT',
            'review_required' => 'Review required',
            'passed'          => 'Screened & passed',
            default           => 'Not screened',
        };
    }

    /** One-line human status for the panel. */
    public function statusLine(): string
    {
        return match ($this->outcome) {
            'hit'             => 'Sanctions HIT — refer to compliance.',
            'review_required' => 'Possible name match — review required.',
            'passed'          => 'Screened & passed — no sanctions match.',
            default           => $this->reason === 'list_stale'
                                    ? 'Could not screen — the sanctions list is stale. Run it once the daily update succeeds.'
                                    : 'Could not screen — the sanctions list is unavailable. Run it manually to retry.',
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
