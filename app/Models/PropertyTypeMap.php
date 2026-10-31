<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MIC 5.2 (AT-519): raw property-type text -> canonical type_key. agency_id NULL = global row; an agency row overrides it for that
 * agency. Reference data, not tenant data - read it through App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver.
 */
class PropertyTypeMap extends Model
{
    protected $table = 'property_type_map';

    protected $guarded = [];

    public const TYPE_KEYS = ['house', 'apartment', 'townhouse', 'land', 'farm', 'commercial', 'industrial', 'other'];
}
