<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An attached document included under a Viewing Pack property. Points at the
 * unified `documents` table. `document_type_slug` denormalises the catalogue
 * slug so Step 1's buyer_pack_eligible can be resolved without a join;
 * `redacted_file_path` holds the flattened/redacted artifact (Step 5).
 */
class ViewingPackDocument extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'viewing_pack_property_id',
        'document_id',
        'document_type_slug',
        'redacted_file_path',
        'included',
    ];

    protected $casts = [
        'included' => 'boolean',
    ];

    public function viewingPackProperty(): BelongsTo
    {
        return $this->belongsTo(ViewingPackProperty::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
