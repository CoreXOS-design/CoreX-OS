<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SplitterDocType extends Model
{
    protected $table = 'splitter_doc_types';

    protected $fillable = ['slug', 'label', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
