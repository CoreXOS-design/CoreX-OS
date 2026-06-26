<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class P24Suburb extends Model
{
    use SoftDeletes;


    protected $table = 'p24_suburbs';

    protected $fillable = [
        'name',
        'slug',
        'p24_id',
        'p24_city_id',
        'region',
        'surrounding_ids',
        'confirmed',
        'latitude',
        'longitude',
        'centroid_source',
        'centroid_geocoded_at',
    ];

    protected $casts = [
        'p24_id'          => 'integer',
        'p24_city_id'     => 'integer',
        'surrounding_ids' => 'array',
        'confirmed'       => 'boolean',
        'latitude'        => 'float',
        'longitude'       => 'float',
        'centroid_geocoded_at' => 'datetime',
    ];

    public function city(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(P24City::class, 'p24_city_id');
    }

    /**
     * Look up a suburb by name (case-insensitive) or slug.
     */
    public static function lookup(string $suburbName): ?self
    {
        $key = strtolower(trim($suburbName));
        $slug = str_replace(' ', '-', $key);

        return static::where('slug', $slug)
            ->orWhereRaw('LOWER(name) = ?', [$key])
            ->first();
    }
}
