<?php

namespace App\Models\Rental;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RentalProperty extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'address_line_1',
        'address_line_2',
        'suburb',
        'city',
        'postal_code',
        'province',
        'full_address',
        'property_type',
        'landlord_name',
        'landlord_email',
        'landlord_phone',
        'monthly_rental',
        'notes',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'monthly_rental' => 'decimal:2',
    ];

    const PROPERTY_TYPES = [
        'house' => 'House',
        'flat' => 'Flat / Apartment',
        'townhouse' => 'Townhouse',
        'duplex' => 'Duplex',
        'cottage' => 'Cottage / Granny Flat',
        'commercial' => 'Commercial',
        'industrial' => 'Industrial',
        'land' => 'Vacant Land',
        'other' => 'Other',
    ];

    protected static function booted()
    {
        static::saving(function ($property) {
            $parts = array_filter([
                $property->address_line_1,
                $property->address_line_2,
                $property->suburb,
                $property->city,
                $property->postal_code,
            ]);
            $property->full_address = implode(', ', $parts);
        });
    }

    public function documents()
    {
        return $this->hasMany(\App\Models\Docuperfect\Document::class, 'property_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
