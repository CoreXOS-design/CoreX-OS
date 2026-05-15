<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class P24Country extends Model
{
    protected $table = 'p24_countries';

    protected $fillable = ['p24_id', 'name'];

    protected $casts = ['p24_id' => 'integer'];

    public function provinces(): HasMany
    {
        return $this->hasMany(P24Province::class);
    }
}
