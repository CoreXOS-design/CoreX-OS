<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-inspections.md — one generated, versioned printable
 * tick-box form. `manifest_json` is the OMR reader lane's entire contract —
 * see RentalInspectionFormPdfService::buildManifest() for the schema, kept
 * in exactly one place so the PDF layout and the manifest can never
 * describe two different geometries. Never mutated once created — a
 * returned scan is matched to the exact version that was printed, so a
 * "correction" is always a NEW version, never an edit of an old one.
 */
class RentalInspectionForm extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'branch_id',
        'rental_inspection_id',
        'version',
        'content_hash',
        'pdf_storage_path',
        'manifest_json',
        'page_count',
        'box_count',
        'generated_by_user_id',
    ];

    protected $casts = [
        'manifest_json' => 'array',
        'version' => 'integer',
        'page_count' => 'integer',
        'box_count' => 'integer',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    /** The most recently generated version for an inspection — never assumes version numbers are gapless. */
    public function scopeCurrentFor(Builder $q, int $inspectionId): Builder
    {
        return $q->where('rental_inspection_id', $inspectionId)->orderByDesc('version')->orderByDesc('id');
    }
}
