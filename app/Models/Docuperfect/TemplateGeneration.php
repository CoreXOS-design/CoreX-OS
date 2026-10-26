<?php

namespace App\Models\Docuperfect;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One Word file a CDS template was built from (Re-import from Word).
 * Spec: .ai/specs/cds-reimport-from-word.md.
 *
 * Deliberately not BelongsToAgency: every read goes through a template the caller has already
 * been allowed to open (Template::assertAccessibleBy), and the row carries the template's own
 * agency_id so it is never ambiguous which agency a generation belongs to.
 */
class TemplateGeneration extends Model
{
    use SoftDeletes;

    public const PENDING = 'pending';
    public const CURRENT = 'current';
    public const SUPERSEDED = 'superseded';
    public const ABANDONED = 'abandoned';

    protected $table = 'docuperfect_template_generations';

    protected $fillable = [
        'agency_id', 'template_id', 'generation', 'status', 'draft_id', 'document_id',
        'original_filename', 'file_sha256', 'uploaded_by', 'report', 'carried_bindings',
        'cds_json', 'editor_state', 'field_mappings', 'applied_at', 'superseded_at', 'superseded_by',
    ];

    protected $casts = [
        'report' => 'array',
        'carried_bindings' => 'array',
        'cds_json' => 'array',
        'editor_state' => 'array',
        'field_mappings' => 'array',
        'applied_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        // A Document row is agency-scoped; the template's generation list is read by the
        // template's own agency (or a CoreX admin), so bypass nothing here — an unreadable
        // document simply has no download link.
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function scopeForTemplate($query, int $templateId)
    {
        return $query->where('template_id', $templateId);
    }
}
