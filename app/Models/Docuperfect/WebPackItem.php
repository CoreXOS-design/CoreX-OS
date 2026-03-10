<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;

class WebPackItem extends Model
{
    protected $table = 'web_pack_items';

    protected $fillable = [
        'web_pack_id',
        'template_id',
        'sort_order',
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
