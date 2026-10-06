<?php

namespace App\Models\PlatformEsign;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'platform_esign_attachments';
    protected $fillable = ['document_id', 'original_name', 'stored_path', 'sha256'];
}
