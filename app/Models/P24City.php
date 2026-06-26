<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class P24City extends Model
{
    use SoftDeletes;

    protected $table = 'p24_cities';

    protected $fillable = ['p24_id', 'p24_province_id', 'name', 'p24_verified_at'];

    protected $casts = [
        'p24_id'          => 'integer',
        'p24_province_id' => 'integer',
        'p24_verified_at' => 'datetime',
    ];

    public function province(): BelongsTo
    {
        return $this->belongsTo(P24Province::class, 'p24_province_id');
    }

    public function suburbs(): HasMany
    {
        return $this->hasMany(P24Suburb::class, 'p24_city_id');
    }
}
