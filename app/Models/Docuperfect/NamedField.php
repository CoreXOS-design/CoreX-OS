<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NamedField extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_named_fields';

    /**
     * Every value the source_type column accepts - the ONE list. The template builder's data sources, the package importer and the
     * package exporter all read it; a new data source is an ENUM migration plus one entry here (NamedFieldSourceTypesTest fails if the
     * two drift).
     */
    public const SOURCE_TYPES = ['property', 'contact', 'agent', 'deal', 'static', 'computed', 'manual', 'lease'];

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
