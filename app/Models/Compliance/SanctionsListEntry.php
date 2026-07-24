<?php

namespace App\Models\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single sanctioned party (individual or entity) from a source feed.
 * GLOBAL reference data — not agency-scoped.
 */
class SanctionsListEntry extends Model
{
    protected $fillable = [
        'source_feed', 'import_id', 'external_ref', 'record_kind', 'primary_name',
        'normalised_name', 'date_of_birth', 'dob_raw', 'place_of_birth', 'nationality',
        'designation', 'address', 'comments', 'listed_on', 'raw',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'listed_on'     => 'date',
        'raw'           => 'array',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(SanctionsListAlias::class, 'entry_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(SanctionsListIdentifier::class, 'entry_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(SanctionsListImport::class, 'import_id');
    }
}
