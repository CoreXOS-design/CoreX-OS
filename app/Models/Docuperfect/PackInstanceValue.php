<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PackInstanceValue extends Model
{
    use SoftDeletes;

    protected $table = 'docuperfect_pack_instance_values';

    protected $fillable = [
        'pack_instance_id',
        'named_field_id',
        'value',
    ];

    public function namedField()
    {
        return $this->belongsTo(NamedField::class, 'named_field_id');
    }
}
