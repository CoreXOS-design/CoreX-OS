<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentCustomField extends Model
{
    use SoftDeletes;

    protected $table = 'document_custom_fields';

    protected $fillable = [
        'template_id',
        'field_key',
        'label',
        'assigned_to',
        'field_type',
        'default_value',
        'sort_order',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
