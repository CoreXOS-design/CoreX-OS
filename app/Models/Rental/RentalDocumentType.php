<?php

namespace App\Models\Rental;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RentalDocumentType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_system' => 'boolean',
        'is_lease' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function booted()
    {
        static::creating(function ($type) {
            if (empty($type->slug)) {
                $type->slug = Str::slug($type->name, '_');
            }
        });
    }
}
