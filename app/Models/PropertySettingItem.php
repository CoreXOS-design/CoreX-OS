<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertySettingItem extends Model
{
    protected $fillable = ['group', 'name', 'sort_order', 'is_default', 'active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_default' => 'boolean',
        'active'     => 'boolean',
    ];

    // Allowed groups
    const GROUP_CATEGORY     = 'category';
    const GROUP_TYPE         = 'property_type';
    const GROUP_STATUS       = 'property_status';
    const GROUP_MANDATE_TYPE = 'mandate_type';

    public function scopeGroup($query, string $group)
    {
        return $query->where('group', $group)->orderBy('sort_order')->orderBy('name');
    }
}
