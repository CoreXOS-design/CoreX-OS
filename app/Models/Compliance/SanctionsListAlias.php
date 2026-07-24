<?php

namespace App\Models\Compliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SanctionsListAlias extends Model
{
    protected $fillable = ['entry_id', 'source_feed', 'alias', 'normalised_alias'];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SanctionsListEntry::class, 'entry_id');
    }
}
