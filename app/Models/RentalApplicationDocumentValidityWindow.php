<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-392 — Johan, verbatim: "Validity windows are per document type PER
 * PURPOSE, agency-configurable — 2 months for the rental application, 3
 * months for FICA including the ID copy. A stale document warns naming the
 * purpose it fails and by how long, in plain language."
 *
 * document_type_id NULL = the purpose-wide default (Johan's own 2/3-month
 * figures). A present document_type_id is a per-type override sitting on
 * top of that default. No row at all for a purpose = DEFAULT_DAYS applies
 * in-memory — never persisted until an agency actually saves a change,
 * same "unconfigured means the shipped default, not an empty state"
 * behaviour RentalApplicationChecklistConfig already established.
 */
class RentalApplicationDocumentValidityWindow extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'purpose', 'document_type_id', 'validity_days'];

    protected $casts = ['validity_days' => 'integer'];

    public const PURPOSES = ['rental_application', 'fica'];

    public const PURPOSE_LABELS = [
        'rental_application' => 'Rental application',
        'fica' => 'FICA',
    ];

    /** Johan's own figures — the shipped defaults until an agency saves its own. */
    public const DEFAULT_DAYS = [
        'rental_application' => 60,
        'fica' => 90,
    ];

    public function documentType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * The validity window (in days) for a document type under a purpose,
     * for one agency. Type-specific override → purpose-wide agency default
     * → Johan's shipped default. No caller ever needs to know which of the
     * three actually applied — that ambiguity is deliberately absorbed
     * here, not left for every call site to reimplement.
     */
    public static function daysFor(int $agencyId, string $purpose, ?int $documentTypeId): int
    {
        if ($documentTypeId !== null) {
            $override = static::query()
                ->where('agency_id', $agencyId)
                ->where('purpose', $purpose)
                ->where('document_type_id', $documentTypeId)
                ->value('validity_days');
            if ($override !== null) {
                return (int) $override;
            }
        }

        $purposeDefault = static::query()
            ->where('agency_id', $agencyId)
            ->where('purpose', $purpose)
            ->whereNull('document_type_id')
            ->value('validity_days');

        return $purposeDefault !== null ? (int) $purposeDefault : (self::DEFAULT_DAYS[$purpose] ?? 60);
    }

    /**
     * Johan: "a stale document warns naming the purpose it fails and by how
     * long, in plain language." Returns null when the document is within
     * its window (or has no created_at, which shouldn't happen but never
     * warns on absent data), otherwise the plain-language sentence itself —
     * callers show it verbatim, no further formatting needed.
     */
    public static function stalenessWarning(
        \Illuminate\Support\Carbon $createdAt,
        int $agencyId,
        string $purpose,
        ?int $documentTypeId
    ): ?string {
        $windowDays = static::daysFor($agencyId, $purpose, $documentTypeId);
        // This codebase's Carbon returns a float from diffInDays() (fractional
        // precision, not whole days) — (int) truncates it to whole elapsed
        // days, matching what "N days past" should actually say.
        $ageDays = (int) $createdAt->diffInDays(now());

        if ($ageDays <= $windowDays) {
            return null;
        }

        $overDays = $ageDays - $windowDays;
        $purposeLabel = self::PURPOSE_LABELS[$purpose] ?? $purpose;
        $overText = $overDays === 1 ? '1 day' : "{$overDays} days";

        return "This document is too old for {$purposeLabel} purposes — {$overText} past the {$windowDays}-day limit.";
    }
}
