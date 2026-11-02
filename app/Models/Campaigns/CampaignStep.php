<?php

namespace App\Models\Campaigns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-534 — one email of a campaign's sequence (.ai/specs/campaigns.md §3.4). */
class CampaignStep extends Model
{
    use SoftDeletes;

    protected $fillable = ['campaign_id', 'step_number', 'subject', 'body', 'day_gap', 'created_by', 'updated_by'];

    protected $casts = ['step_number' => 'integer', 'day_gap' => 'integer'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }
}
