<?php

namespace App\Models\PlatformEsign;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TemplateField extends Model
{
    use SoftDeletes;

    protected $table = 'platform_esign_template_fields';

    public const TYPES = ['signature' => 'Signature', 'initial' => 'Initials', 'date' => 'Date', 'text' => 'Text'];

    protected $fillable = ['template_id', 'page_index', 'x', 'y', 'w', 'h', 'type', 'role_key', 'label', 'required', 'sort_order'];

    protected $casts = ['x' => 'float', 'y' => 'float', 'w' => 'float', 'h' => 'float', 'required' => 'boolean'];

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }
}
