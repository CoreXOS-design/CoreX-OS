<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PropertySettingItem extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id','group', 'name', 'sort_order', 'is_default', 'active'];

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
