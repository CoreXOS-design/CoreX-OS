<?php

namespace App\Models\PlatformEsign;

use Illuminate\Database\Eloquent\Model;

/** One page initialled by one signer (spec §11.7). */
class Initial extends Model
{
    public $timestamps = false;

    protected $table = 'platform_esign_initials';

    protected $fillable = ['document_id', 'signer_id', 'page_no', 'initials', 'ip', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
