<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Property extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'external_id',
        'title',
        'excerpt',
        'description',
        'price',
        'city',
        'suburb',
        'region',
        'beds',
        'baths',
        'garages',
        'size_m2',
        'erf_size_m2',
        'property_type',
        'mandate_type',
        'status',
        'images_json',
        'dawn_images_json',
        'noon_images_json',
        'dusk_images_json',
        'gallery_images_json',
        'agent_id',
        'branch_id',
        'agency_id',
        'published_at',
    ];

    protected $casts = [
        'images_json'         => 'array',
        'dawn_images_json'    => 'array',
        'noon_images_json'    => 'array',
        'dusk_images_json'    => 'array',
        'gallery_images_json' => 'array',
        'published_at'        => 'datetime',
        'price'               => 'integer',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Property $property) {
            if (empty($property->external_id)) {
                $property->external_id = (string) Str::uuid();
            }
        });
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function formattedPrice(): string
    {
        return 'R ' . number_format((int) $this->price, 0, '.', ' ');
    }

    /** All images flattened into one array for convenience */
    public function allImages(): array
    {
        return array_merge(
            $this->dawn_images_json    ?? [],
            $this->noon_images_json    ?? [],
            $this->dusk_images_json    ?? [],
            $this->gallery_images_json ?? [],
            $this->images_json         ?? [],
        );
    }
}
