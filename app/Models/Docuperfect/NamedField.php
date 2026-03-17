<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NamedField extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_named_fields';

    protected $fillable = [
        'name',
        'field_type',
        'default_options',
        'sort_order',
        'source_type',
        'source_column',
        'source_contact_type',
    ];

    protected $casts = [
        'default_options' => 'array',
        'sort_order' => 'integer',
    ];
}
