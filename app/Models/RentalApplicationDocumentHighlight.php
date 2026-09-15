<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-392 Phase 2 — persistent highlight marks over a rental-application
 * document. One row per document (see the migration). Mirrors
 * ViewingPackDocument's redacted_file_path pattern — a stable, regenerated-
 * on-reapply flattened artifact plus a DB pointer checked at read-time —
 * but non-destructive (translucent colour, not opaque black) and keeps the
 * structured mark list so the agent can see/edit their own marks on reopen.
 */
class RentalApplicationDocumentHighlight extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id', 'document_id', 'marks_json', 'marks_version', 'highlighted_file_path', 'updated_by_user_id',
    ];

    protected $casts = [
        'marks_json' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
