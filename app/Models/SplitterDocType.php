<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SplitterDocType extends Model
{
    use SoftDeletes;


    protected $table = 'document_types';

    protected $fillable = ['slug', 'label', 'sort_order', 'is_active', 'listing_types', 'contact_roles', 'fica_slot'];

    /**
     * AT-105 enh — allowed values, shared by validation + UI.
     * contact_roles is a many-set; an empty set means "routes to no contact".
     */
    public const CONTACT_ROLES = ['seller_owner', 'buyer', 'tenant', 'landlord', 'lessor'];
    public const FICA_SLOTS    = ['id', 'por', 'fica_form', 'none'];

    protected $casts = [
        'sort_order'    => 'integer',
        'is_active'     => 'boolean',
        'listing_types' => 'array',
        'contact_roles' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
