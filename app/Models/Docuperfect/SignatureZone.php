<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;

class SignatureZone extends Model
{
    protected $table = 'signature_zones';

    protected $fillable = [
        'signature_template_id',
        'zone_type',
        'party_role',
        'page_number',
        'x_position',
        'y_position',
        'width',
        'height',
        'is_auto_placed',
        'source',
        'label',
        'sort_order',
    ];

    protected $casts = [
        'x_position' => 'decimal:4',
        'y_position' => 'decimal:4',
        'width' => 'decimal:4',
        'height' => 'decimal:4',
        'is_auto_placed' => 'boolean',
    ];

    const TYPE_SIGNATURE = 'signature';
    const TYPE_INITIAL = 'initial';
    const TYPE_OTHER_CONDITIONS = 'other_conditions';

    const SOURCE_TEMPLATE = 'template';
    const SOURCE_SETUP = 'setup';
    const SOURCE_DOM = 'dom';

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function expandedMarkers()
    {
        return $this->hasMany(SignatureMarker::class, 'from_zone_id');
    }

    // --- Scopes ---

    public function scopeForPage($query, int $page)
    {
        return $query->where('page_number', $page);
    }

    public function scopeForRole($query, string $role)
    {
        return $query->where('party_role', $role);
    }

    public function scopeSignatures($query)
    {
        return $query->where('zone_type', self::TYPE_SIGNATURE);
    }

    public function scopeInitials($query)
    {
        return $query->where('zone_type', self::TYPE_INITIAL);
    }

    public function scopeOtherConditions($query)
    {
        return $query->where('zone_type', self::TYPE_OTHER_CONDITIONS);
    }

    public function scopeAutoPlaced($query)
    {
        return $query->where('is_auto_placed', true);
    }
}
