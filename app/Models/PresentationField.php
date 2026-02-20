<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PresentationField extends Model
{
    protected $fillable = [
        'presentation_id',
        'field_key',
        'extracted_value',
        'override_value',
        'final_value',
        'source_upload_id',
        'confidence',
    ];

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function sourceUpload()
    {
        return $this->belongsTo(PresentationUpload::class, 'source_upload_id');
    }
}
