<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SplitterDocType extends Model
{
    use SoftDeletes;


    protected $table = 'document_types';

    protected $fillable = ['slug', 'label', 'sort_order', 'is_active', 'listing_types'];

    protected $casts = [
        'sort_order'    => 'integer',
        'is_active'     => 'boolean',
        'listing_types' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
