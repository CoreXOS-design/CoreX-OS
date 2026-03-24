<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentType extends Model
{
    use SoftDeletes;

    protected $table = 'document_types';

    protected $fillable = [
        'slug',
        'label',
        'sort_order',
        'is_active',
        'grouping',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
    ];

    /**
     * Backward-compat accessor: views referencing $dt->name get the label.
     */
    public function getNameAttribute(): string
    {
        return $this->label;
    }

    public function templates()
    {
        return $this->hasMany(Template::class, 'document_type_id');
    }
}
