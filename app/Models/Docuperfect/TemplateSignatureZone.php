<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TemplateSignatureZone extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_template_signature_zones';

    protected $fillable = [
        'template_id',
        'page_index',
        'x_position',
        'y_position',
        'width',
        'height',
        'type',
        'assigned_parties',
        'label',
        'required',
        'sort_order',
    ];

    protected $casts = [
        'x_position' => 'decimal:4',
        'y_position' => 'decimal:4',
        'width' => 'decimal:4',
        'height' => 'decimal:4',
        'assigned_parties' => 'array',
        'required' => 'boolean',
    ];

    // Type constants
    const TYPE_SIGNATURE = 'signature';
    const TYPE_INITIAL = 'initial';

    // --- Relationships ---

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    // --- Scopes ---

    public function scopeForPage($query, int $pageIndex)
    {
        return $query->where('page_index', $pageIndex);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('page_index')->orderBy('sort_order');
    }
}
