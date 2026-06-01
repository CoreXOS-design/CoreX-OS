<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PpProvince extends Model
{
    protected $fillable = ['pp_province_id', 'pp_province_enum', 'name'];

    public function cities(): HasMany
    {
        return $this->hasMany(PpCity::class);
    }
}
