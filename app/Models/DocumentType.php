<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentType extends Model
{
    use SoftDeletes;

    protected $table = 'document_types';

    protected $fillable = ['slug', 'label', 'sort_order', 'is_active', 'grouping', 'listing_types'];

    protected $casts = [
        'sort_order'    => 'integer',
        'is_active'     => 'boolean',
        'listing_types' => 'array',
    ];

    /**
     * Backward-compat accessor so views using $dt->name still work.
     */
    public function getNameAttribute(): string
    {
        return $this->label;
    }

    /**
     * Check if this document type applies to a given listing type (sale/rental).
     * Only shows on Drive if listing_types has been explicitly assigned.
     * Empty/null listing_types = not assigned to any listing type = won't appear as a Drive folder.
     */
    public function appliesToListingType(?string $listingType): bool
    {
        $types = $this->listing_types;
        if (empty($types)) return false;
        return in_array($listingType, $types);
    }

    /**
     * Scope: only doc types that have at least one listing type assigned.
     */
    public function scopeWithListingType($query)
    {
        return $query->whereNotNull('listing_types')->where('listing_types', '!=', '[]');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
