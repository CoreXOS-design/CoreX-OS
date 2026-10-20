<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspection-form.md §13 — one row per (scan × item): what
 * the OMR reader detected in that item's tick-box row, and what the agent
 * confirmed on the review screen. This is NOT a parallel observation
 * store — applying a confirmed mark creates an ORDINARY
 * RentalInspectionObservation via ::record(), identical to a screen tap.
 * `applied_observation_id` is the audit-trail pointer back from that real
 * observation to the scan/page/reviewer that produced it.
 */
class RentalInspectionScanMark extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_inspection_scan_id',
        'rental_inspection_item_id',
        'page_number',
        'detected_condition_key',
        'detected_confidence',
        'ambiguous',
        'tick_boxes',
        'detected_note',
        'detected_note_confidence',
        'note_ink',
        'confirmed_condition_key',
        'confirmed_by_user_id',
        'confirmed_at',
        'applied_observation_id',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'detected_confidence' => 'float',
        'ambiguous' => 'boolean',
        'tick_boxes' => 'integer',
        'note_ink' => 'boolean',
        'detected_note_confidence' => 'float',
        'confirmed_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionScan::class, 'rental_inspection_scan_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function appliedObservation(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionObservation::class, 'applied_observation_id');
    }

    /** AT-472 / form spec §13.15 - the three states a row's tick can be in on the review screen. */
    public const TICK_READ = 'read';       // read with confidence
    public const TICK_UNSURE = 'unsure';   // picked up but unsure: two boxes, faint, or low confidence - the agent must confirm
    public const TICK_MISSED = 'missed';   // not picked up at all - the agent must set it

    /** A tick whose strongest box is under the sensitivity has confidence under 0.4 (confidence = strongest / (2.5 x sensitivity)). */
    private const LEGACY_NOTHING_PICKED_UP_BELOW = 0.4;

    /**
     * @param float $unsureBelow the agency's "ticks to check below this confidence" (RentalInspectionSetting::omrUnsureBelowFor())
     */
    public function tickState(float $unsureBelow): string
    {
        // How many boxes read as marked: stored since AT-472; a mark read before that is told apart by its confidence.
        $boxes = $this->tick_boxes ?? ($this->ambiguous && $this->detected_confidence !== null && (float) $this->detected_confidence < self::LEGACY_NOTHING_PICKED_UP_BELOW ? 0 : null);
        if ($boxes === 0 || ($this->detected_condition_key === null && ! $this->ambiguous)) {
            return self::TICK_MISSED;
        }
        if ($this->ambiguous || $this->detected_condition_key === null) {
            return self::TICK_UNSURE;   // two boxes, or a page that could not be placed reliably
        }

        return $this->detected_confidence !== null && (float) $this->detected_confidence < $unsureBelow ? self::TICK_UNSURE : self::TICK_READ;
    }

    /** The AI-read note, when it is good enough to fill the note box (text present and at or above the agency's confidence line). */
    public function usableDetectedNote(float $minConfidence): ?string
    {
        $text = $this->detected_note !== null ? trim($this->detected_note) : '';

        return $text !== '' && $this->detected_note_confidence !== null && (float) $this->detected_note_confidence >= $minConfidence ? $text : null;
    }

    /** The note area had handwriting in it but there is no usable AI reading of it: the agent is told to type the note in. */
    public function noteUnreadable(float $minConfidence): bool
    {
        if ($this->usableDetectedNote($minConfidence) !== null) {
            return false;
        }

        return $this->note_ink || ($this->detected_note !== null && trim($this->detected_note) !== '');
    }

    /** Never guessed — a row with no detected key or that's flagged ambiguous needs an explicit human choice before it can be applied. */
    public function needsHumanDecision(): bool
    {
        return $this->ambiguous || $this->detected_condition_key === null;
    }
}
