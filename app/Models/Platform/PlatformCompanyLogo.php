<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One uploaded version of the platform company logo. Never hard-deleted; superseded versions stay restorable. */
class PlatformCompanyLogo extends Model
{
    use SoftDeletes;

    protected $table = 'platform_company_logos';
    protected $fillable = ['path', 'original_name', 'mime', 'size', 'uploaded_by'];

    public function uploader()
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }
}
