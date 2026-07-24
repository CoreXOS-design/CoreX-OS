<?php

namespace App\Models\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SanctionsListIdentifier extends Model
{
    protected $fillable = ['entry_id', 'source_feed', 'id_type', 'id_value', 'normalised_value', 'country'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SanctionsListEntry::class, 'entry_id');
    }
}
