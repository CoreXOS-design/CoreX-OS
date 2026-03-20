<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebPackItem extends Model
{
    use SoftDeletes;

    protected $table = 'web_pack_items';

    protected $fillable = [
        'web_pack_id',
        'template_id',
        'sort_order',
        'slot_type',
        'slot_group',
        'slot_label',
    ];

    public function webPack()
    {
        return $this->belongsTo(WebPack::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}
