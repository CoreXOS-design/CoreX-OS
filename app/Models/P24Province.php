<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class P24Province extends Model
{
    use SoftDeletes;

    protected $table = 'p24_provinces';

    protected $fillable = ['p24_id', 'p24_country_id', 'name', 'p24_verified_at'];

    protected $casts = [
        'p24_id'         => 'integer',
        'p24_country_id' => 'integer',
        'p24_verified_at' => 'datetime',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(P24Country::class, 'p24_country_id');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(P24City::class);
    }
}
