<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Presentation extends Model
{
    // Status values: draft | presented | locked
    protected $fillable = [
        'branch_id',
        'created_by_user_id',
        'listing_id',
        'title',
        'property_address',
        'suburb',
        'property_type',
        'bedrooms',
        'floor_area_m2',
        'seller_name',
        'seller_email',
        'status',
        'currency',
    ];

    protected $casts = [
        'bedrooms'      => 'integer',
        'floor_area_m2' => 'integer',
    ];

    public function uploads()
    {
        return $this->hasMany(PresentationUpload::class);
    }

    public function fields()
    {
        return $this->hasMany(PresentationField::class);
    }

    public function sections()
    {
        return $this->hasMany(PresentationSection::class);
    }

    public function snapshots()
    {
        return $this->hasMany(PresentationSnapshot::class);
    }

    public function links()
    {
        return $this->hasMany(PresentationLink::class);
    }

    public function soldComps()
    {
        return $this->hasMany(PresentationSoldComp::class);
    }

    public function activeListings()
    {
        return $this->hasMany(PresentationActiveListing::class);
    }

    public function versions()
    {
        return $this->hasMany(PresentationVersion::class);
    }
}
