<?php

namespace App\Models\PlatformEsign;

use Illuminate\Database\Eloquent\Model;

class FieldValue extends Model
{
    protected $table = 'platform_esign_field_values';
    protected $fillable = ['document_id', 'signer_id', 'field_id', 'value'];
}
