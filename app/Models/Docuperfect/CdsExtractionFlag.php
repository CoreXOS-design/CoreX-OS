<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ES-6.7 — a single AI-detected extraction-fidelity divergence between an
 * imported PDF and its extracted CDS structure. A human ratifies each flag in
 * the CDS builder; HIGH-severity flags must be resolved before the template can
 * be used in the e-sign wizard. Soft-delete (no hard delete) + resolution audit
 * trail (resolved_by / resolved_at / resolution_note).
 */
class CdsExtractionFlag extends Model
{
    use SoftDeletes;

    protected $table = 'cds_extraction_flags';

    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_LOW  = 'low';

    public const STATUS_PENDING      = 'pending';
    public const STATUS_ACCEPTED     = 'accepted';      // extraction is fine as-is
    public const STATUS_FIXED        = 'fixed';         // human corrected the content
    public const STATUS_ACKNOWLEDGED = 'acknowledged';  // low-severity, noted

    public const RESOLVED_STATUSES = [
        self::STATUS_ACCEPTED,
        self::STATUS_FIXED,
        self::STATUS_ACKNOWLEDGED,
    ];

    protected $fillable = [
        'cds_draft_id',
        'template_id',
        'severity',
        'divergence_type',
        'location',
        'description',
        'source_snippet',
        'extracted_snippet',
        'status',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function draft()
    {
        return $this->belongsTo(CdsDraft::class, 'cds_draft_id');
    }

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function isResolved(): bool
    {
        return in_array($this->status, self::RESOLVED_STATUSES, true);
    }

    /** Unresolved high-severity flags — the set that BLOCKS wizard use. */
    public function scopeBlocking($query)
    {
        return $query->where('severity', self::SEVERITY_HIGH)
            ->where('status', self::STATUS_PENDING);
    }
}
