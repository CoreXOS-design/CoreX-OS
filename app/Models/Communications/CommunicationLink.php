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

    protected $fillable = [
        'agency_id', 'communication_id', 'linkable_type', 'linkable_id',
        'link_method', 'confidence', 'confirmed_by', 'confirmed_at',
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
