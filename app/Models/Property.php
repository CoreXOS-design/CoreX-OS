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
        'description',
        'price',
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
        'agent_id',
        'branch_id',
        'agency_id',
        'published_at',
    ];

    protected $casts = [
        'images_json'  => 'array',
        'published_at' => 'datetime',
        'price'        => 'integer',
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
}
