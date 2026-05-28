<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PpSuburb extends Model
{
    protected $fillable = ['pp_suburb_id', 'pp_city_id', 'name', 'normalised_name'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(PpCity::class);
    }

    public static function normalise(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name));
    }
}
