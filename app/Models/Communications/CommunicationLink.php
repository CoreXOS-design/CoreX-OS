<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Communication link (AT-32) — Intelligence layer, decoupled from the archive.
 */
class CommunicationLink extends Model
{
    use SoftDeletes, BelongsToAgency;

    const METHOD_DETERMINISTIC   = 'deterministic';
    const METHOD_ATTORNEY_REF    = 'attorney_ref';
    const METHOD_ELLIE_SUGGESTED = 'ellie_suggested';
    const METHOD_MANUAL          = 'manual';
    /**
     * comm -> Document provenance for an attachment DR2 filed into the deal's
     * document library (CX-114, 2026-08-22). Mirrors Comms Suspense's own
     * comm->document link (CorrespondenceFilingService, method attorney_ref) —
     * a distinct method value so the two provenance trails stay separable, and
     * so a move/unlink only ever withdraws documents attributable to attachment
     * filing, never a document linked here for some other reason.
     */
    const METHOD_ATTACHMENT      = 'attachment';

    protected $fillable = [
        'agency_id', 'communication_id', 'linkable_type', 'linkable_id',
        'source_attachment_id', 'link_method', 'confidence', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'confidence'   => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
