<?php

namespace App\Models\Campaigns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AT-534 — a list of prospects (.ai/specs/campaigns.md §3.1). */
class CampaignList extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'source_filename', 'last_imported_at', 'created_by', 'updated_by'];

    protected $casts = ['last_imported_at' => 'datetime'];

    public function leads(): HasMany
    {
        return $this->hasMany(CampaignLead::class);
    }

    /** The one non-archived campaign that uses this list, if any (§3.3 — a list belongs to at most one). */
    public function campaign(): HasOne
    {
        return $this->hasOne(Campaign::class);
    }
}
