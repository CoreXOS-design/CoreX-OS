<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PpCity extends Model
{
    protected $fillable = ['pp_city_id', 'pp_province_id', 'name'];

    public function province(): BelongsTo
    {
        return $this->belongsTo(PpProvince::class);
    }

    public function suburbs(): HasMany
    {
        return $this->hasMany(PpSuburb::class);
    }
}
