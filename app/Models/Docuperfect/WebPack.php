<?php

namespace App\Models\Docuperfect;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class WebPack extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'web_packs';

    protected $fillable = [
        'name',
        'description',
        'agency_id',
        'created_by',
    ];

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(WebPackItem::class)->orderBy('sort_order');
    }
}
