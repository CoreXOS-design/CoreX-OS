<?php

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Town extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'name',
        'slug',
        'region',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    public function suburbs(): HasMany
    {
        return $this->hasMany(TownSuburb::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('name');
    }
}
